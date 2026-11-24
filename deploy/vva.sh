#!/usr/bin/env bash
# VVA – zarządzanie serwerem domowym (Docker Compose).
# Uruchamiaj z katalogu deploy/:   ./vva.sh <polecenie>
# Na Windows: w terminalu Ubuntu (WSL2) z włączoną integracją Docker Desktop.
set -euo pipefail

cd "$(dirname "$0")"
ENV_FILE=.env
DC=(docker compose)

c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_off=$'\e[0m'
info() { echo "${c_ok}==>${c_off} $*"; }
warn() { echo "${c_warn}!! ${c_off} $*"; }
die()  { echo "${c_err}xx ${c_off} $*" >&2; exit 1; }

usage() {
    cat <<'EOF'
VVA – serwer domowy

  ./vva.sh setup             kreator konfiguracji (.env: tryb, domena, sekrety)
  ./vva.sh install           setup (jeśli trzeba) + budowa + start
  ./vva.sh update            git pull + kopia bazy + przebudowa + restart
  ./vva.sh start | stop      uruchom / zatrzymaj wszystkie usługi
  ./vva.sh status            stan kontenerów i test strony
  ./vva.sh logs [usługa]     logi na żywo (app, reverb, queue, scheduler, mysql, backup, cloudflared)
  ./vva.sh backup            kopia zapasowa teraz (do deploy/backups)
  ./vva.sh restore <plik>    przywróć bazę z pliku vva-db-*.sql.gz
  ./vva.sh make-admin <email>  nadaj kontu uprawnienia właściciela platformy
  ./vva.sh artisan <...>     dowolne polecenie artisan
  ./vva.sh mysql             konsola MySQL
EOF
}

need_docker() {
    command -v docker >/dev/null || die "Brak Dockera. Zainstaluj go (patrz deploy/README.md)."
    docker compose version >/dev/null 2>&1 || die "Brak 'docker compose' (wtyczka Compose v2)."
    docker info >/dev/null 2>&1 || die "Docker nie działa (uruchom Docker Desktop / 'sudo systemctl start docker' lub dodaj się do grupy docker)."
}

need_env() { [[ -f $ENV_FILE ]] || die "Brak deploy/.env – uruchom najpierw ./vva.sh setup"; }

env_get() { [[ -f $ENV_FILE ]] && grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- || true; }

# Replace (or append) KEY=VALUE without sed-escaping problems.
env_set() {
    local key=$1 value=$2 tmp
    tmp=$(mktemp)
    KEY="$key" VALUE="$value" awk '
        BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; done = 0 }
        $0 ~ "^" k "=" { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' "$ENV_FILE" > "$tmp"
    cat "$tmp" > "$ENV_FILE"
    rm -f "$tmp"
}

rand_hex() { openssl rand -hex "${1:-24}" 2>/dev/null || head -c "${1:-24}" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

ask() { # ask "Pytanie" default -> echo answer
    local answer
    read -r -p "$1 [${2}]: " answer || true
    echo "${answer:-$2}"
}

cmd_setup() {
    if [[ -f $ENV_FILE ]]; then
        warn "deploy/.env już istnieje – zmienię tylko tryb i domenę, sekrety zostają."
    else
        cp .env.example "$ENV_FILE"
        chmod 600 "$ENV_FILE"
    fi

    # Secrets: generate once, never overwrite.
    [[ -n $(env_get APP_KEY) ]]           || env_set APP_KEY "base64:$(openssl rand -base64 32)"
    [[ -n $(env_get DB_PASSWORD) ]]       || env_set DB_PASSWORD "$(rand_hex 24)"
    [[ -n $(env_get DB_ROOT_PASSWORD) ]]  || env_set DB_ROOT_PASSWORD "$(rand_hex 24)"
    [[ -n $(env_get REVERB_APP_KEY) ]]    || env_set REVERB_APP_KEY "$(rand_hex 16)"
    [[ -n $(env_get REVERB_APP_SECRET) ]] || env_set REVERB_APP_SECRET "$(rand_hex 24)"

    echo
    echo "Jak strona ma być dostępna?"
    echo "  1) tunnel – Cloudflare Tunnel (zalecane: bez przekierowania portów, działa za CGNAT)"
    echo "  2) caddy  – własny certyfikat HTTPS (porty 80 i 443 przekierowane na ten komputer)"
    echo "  3) lan    – tylko w sieci domowej, http://<IP> (test)"
    local current mode
    current=$(env_get VVA_MODE); current=${current:-tunnel}
    case $(ask "Wybierz 1/2/3 albo nazwę" "$current") in
        1|tunnel) mode=tunnel ;;
        2|caddy)  mode=caddy ;;
        3|lan)    mode=lan ;;
        *) die "Nieznany tryb." ;;
    esac
    env_set VVA_MODE "$mode"

    local domain
    if [[ $mode == lan ]]; then
        local ip
        ip=$(hostname -I 2>/dev/null | awk '{print $1}')
        domain=$(ask "Adres IP tego komputera w sieci domowej" "${ip:-192.168.1.10}")
        local port; port=$(env_get HTTP_PORT); port=${port:-80}
        env_set APP_DOMAIN "$domain"
        env_set SERVER_NAME ":80"
        env_set COMPOSE_PROFILES ""
        env_set SESSION_SECURE_COOKIE false
        if [[ $port == 80 ]]; then env_set APP_URL "http://$domain"; else env_set APP_URL "http://$domain:$port"; fi
    else
        domain=$(ask "Domena (np. vva.mojklub.pl)" "$(env_get APP_DOMAIN)")
        [[ -n $domain && $domain != vva.example.pl ]] || die "Podaj własną domenę."
        env_set APP_DOMAIN "$domain"
        env_set APP_URL "https://$domain"
        env_set SESSION_SECURE_COOKIE true
        env_set MAIL_FROM_ADDRESS "noreply@$domain"
        if [[ $mode == caddy ]]; then
            env_set SERVER_NAME "$domain"
            env_set COMPOSE_PROFILES ""
        else
            env_set SERVER_NAME ":80"
            env_set COMPOSE_PROFILES tunnel
            local token
            token=$(ask "Token Cloudflare Tunnel (eyJ..., Enter = później)" "$(env_get CLOUDFLARE_TUNNEL_TOKEN)")
            env_set CLOUDFLARE_TUNNEL_TOKEN "$token"
            [[ -n $token ]] || warn "Bez tokenu tunel nie wystartuje – dopisz CLOUDFLARE_TUNNEL_TOKEN w deploy/.env."
        fi
    fi

    info "Zapisano deploy/.env (tryb: $mode, adres: $(env_get APP_URL))."
}

cmd_build() {
    info "Buduję obraz aplikacji (pierwszy raz kilka minut)…"
    VVA_IMAGE_TAG=latest "${DC[@]}" build app
}

cmd_start() {
    need_env
    info "Uruchamiam usługi…"
    "${DC[@]}" up -d --remove-orphans
}

cmd_status() {
    need_env
    "${DC[@]}" ps
    local url; url=$(env_get APP_URL)
    echo
    if docker compose exec -T app curl -fsS -o /dev/null http://localhost/up 2>/dev/null; then
        info "Aplikacja odpowiada w kontenerze."
    else
        warn "Aplikacja jeszcze nie odpowiada (pierwszy start + migracje mogą potrwać ~1 min). Sprawdź: ./vva.sh logs app"
    fi
    if command -v curl >/dev/null && curl -fsS -o /dev/null --max-time 10 "$url/up" 2>/dev/null; then
        info "Strona działa z zewnątrz: $url"
    else
        warn "Nie udało się otworzyć $url z tego komputera (DNS / tunel / porty?)."
    fi
}

cmd_install() {
    need_docker
    [[ -f $ENV_FILE ]] || cmd_setup
    mkdir -p backups
    cmd_build
    cmd_start
    info "Czekam na pierwszy start (migracje bazy)…"
    for _ in $(seq 1 40); do
        docker compose exec -T app curl -fsS -o /dev/null http://localhost/up 2>/dev/null && break
        sleep 3
    done
    cmd_status
    echo
    info "Gotowe. Otwórz $(env_get APP_URL), zarejestruj konto i załóż klub."
    info "Potem nadaj sobie rolę właściciela platformy:  ./vva.sh make-admin twoj@email.pl"
}

cmd_backup() {
    need_env
    mkdir -p backups
    "${DC[@]}" run --rm --no-deps -T backup once
    ls -lh backups | tail -n 4
}

cmd_update() {
    need_docker; need_env
    if git -C .. rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        info "Pobieram zmiany z GitHuba (gałąź $(git -C .. branch --show-current))…"
        git -C .. pull --ff-only
    else
        warn "To nie jest repozytorium git – pomijam git pull."
    fi
    if "${DC[@]}" ps --status running --services 2>/dev/null | grep -qx mysql; then
        info "Kopia zapasowa przed aktualizacją…"
        cmd_backup
    fi
    cmd_build
    cmd_start
    docker image prune -f --filter "label=com.docker.compose.project=vva" >/dev/null 2>&1 || true
    docker image prune -f >/dev/null 2>&1 || true
    cmd_status
}

cmd_restore() {
    need_env
    local file=${1:-}
    [[ -f $file ]] || die "Podaj plik kopii, np. ./vva.sh restore backups/vva-db-2026-01-01_0300.sql.gz"
    warn "To nadpisze całą bazę $(env_get DB_DATABASE) danymi z $file."
    [[ $(ask "Wpisz TAK, aby kontynuować" "nie") == TAK ]] || die "Przerwano."
    "${DC[@]}" stop app queue scheduler reverb
    gunzip -c "$file" | "${DC[@]}" exec -T -e MYSQL_PWD="$(env_get DB_ROOT_PASSWORD)" mysql \
        mysql -u root "$(env_get DB_DATABASE)"
    "${DC[@]}" up -d
    info "Przywrócono bazę z $file."
}

cmd_make_admin() {
    need_env
    local email=${1:-}
    [[ -n $email ]] || die "Użycie: ./vva.sh make-admin twoj@email.pl"
    "${DC[@]}" exec -T -e VVA_EMAIL="$email" app php artisan tinker --execute '
        $u = App\Models\User::where("email", getenv("VVA_EMAIL"))->first();
        if (! $u) { echo "Nie ma konta z tym adresem – najpierw zarejestruj się na stronie.\n"; exit(1); }
        $u->forceFill(["is_super_admin" => true])->save();
        echo "OK: {$u->email} jest teraz właścicielem platformy.\n";'
}

main() {
    local cmd=${1:-help}; shift || true
    case $cmd in
        setup)      cmd_setup ;;
        install)    cmd_install ;;
        update)     cmd_update ;;
        start)      need_docker; cmd_start ;;
        stop)       need_env; "${DC[@]}" stop ;;
        down)       need_env; "${DC[@]}" down ;;
        status)     cmd_status ;;
        logs)       need_env; "${DC[@]}" logs -f --tail=200 "$@" ;;
        backup)     cmd_backup ;;
        restore)    cmd_restore "$@" ;;
        make-admin) cmd_make_admin "$@" ;;
        artisan)    need_env; "${DC[@]}" exec app php artisan "$@" ;;
        mysql)      need_env; "${DC[@]}" exec -e MYSQL_PWD="$(env_get DB_PASSWORD)" mysql mysql -u "$(env_get DB_USERNAME)" "$(env_get DB_DATABASE)" ;;
        build)      need_env; cmd_build ;;
        help|-h|--help) usage ;;
        *) usage; exit 1 ;;
    esac
}

main "$@"
