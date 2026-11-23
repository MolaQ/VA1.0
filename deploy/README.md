# VVA na serwerze domowym

Cała aplikacja działa w Dockerze i jest obsługiwana jednym skryptem `deploy/vva.sh`.
Ten sam zestaw działa na Linuksie i na Windows (Docker Desktop + WSL2), więc może to być
np. Twój laptop z RTX 3060.

```
Internet ──► Cloudflare Tunnel (albo port 443) ──► app  (FrankenPHP = Caddy + PHP 8.4, HTTPS)
                                                    ├─ /app/*  ──► reverb     (WebSockets: statystyki na żywo)
                                                    └─ Laravel ──► mysql      (MySQL 8.4)
                                                   queue      (kolejka: analiza AI, broadcasty)
                                                   scheduler  (zadania cykliczne)
                                                   backup     (co noc: baza + pliki → deploy/backups)
                                                   analyzer   (przyszły moduł AI na GPU – szablon)
```

| Plik | Do czego |
| --- | --- |
| `vva.sh` | instalacja, aktualizacja, kopie, logi, nadanie admina |
| `compose.yml` | definicja usług |
| `Dockerfile` | obraz aplikacji (Composer + build Vite + FrankenPHP) |
| `docker/Caddyfile` | HTTPS, proxy WebSocketów do Reverb, nagłówki cache |
| `.env.example` | szablon konfiguracji (kreator tworzy z niego `deploy/.env`) |
| `compose.gpu.example.yml` | miejsce na moduł AI z kartą NVIDIA |

---

## Krok 1. Przygotuj komputer

### Wariant A: laptop z Windows (np. ten z RTX 3060)

1. Zainstaluj **WSL2 z Ubuntu**: PowerShell jako administrator → `wsl --install -d Ubuntu`, uruchom
   ponownie komputer, otwórz „Ubuntu” z menu Start i ustaw login/hasło.
2. Zainstaluj **Docker Desktop** (docker.com → Download for Windows). W ustawieniach:
   - *General* → zaznacz **Use the WSL 2 based engine** i **Start Docker Desktop when you sign in**,
   - *Resources → WSL integration* → włącz dla **Ubuntu**.
3. Żeby laptop działał jak serwer: *Ustawienia → System → Zasilanie* → przy zasilaniu z sieci
   **uśpienie: nigdy**, a w *Panel sterowania → Opcje zasilania → Wybierz działanie zamykania pokrywy*
   → **Nic nie rób** (przy zasilaniu sieciowym). Docker Desktop startuje po zalogowaniu, więc po
   restarcie komputera trzeba się zalogować (albo włączyć automatyczne logowanie).
4. Jeśli na tym samym komputerze działa **XAMPP**, zatrzymaj w nim Apache (zajmuje port 80/443)
   albo w kroku 4 ustaw inne porty (`HTTP_PORT=8081`, `HTTPS_PORT=8443` w `deploy/.env`).

Wszystkie dalsze polecenia wpisujesz w terminalu **Ubuntu (WSL)**, nie w PowerShellu.

### Wariant B: komputer z Linuksem (Ubuntu / Debian)

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER     # potem wyloguj się i zaloguj ponownie
```

Sprawdzenie (oba warianty): `docker run --rm hello-world` ma wypisać „Hello from Docker!”.

---

## Krok 2. Pobierz kod

```bash
cd ~
git clone https://github.com/MolaQ/VA1.0.git vva
cd vva
git checkout main          # dopóki zmiany nie są scalone: git checkout claude/project-thread-tfsi6l
cd deploy
```

> Na Windows klonuj do katalogu w Ubuntu (`~/vva`), nie na `C:` (`/mnt/c/...`) – jest dużo szybciej.

---

## Krok 3. Wybierz, jak strona będzie widoczna z internetu

### Opcja zalecana: Cloudflare Tunnel (`tunnel`)

Bez przekierowania portów na routerze i bez stałego IP – działa też, gdy operator daje CGNAT
(częste w LTE/światłowodach). Potrzebna jest domena (dowolna, np. `mojklub.pl`, ok. 40–60 zł/rok)
podpięta do darmowego konta Cloudflare.

1. Załóż konto na cloudflare.com → *Add a domain* → wpisz domenę → plan **Free** → zmień u
   rejestratora serwery DNS na te podane przez Cloudflare (aktywacja zwykle do kilku godzin).
2. Cloudflare → **Zero Trust** → *Networks* → **Tunnels** → *Create a tunnel* → **Cloudflared** →
   nazwa `vva` → w kroku instalacji wybierz **Docker** i skopiuj **token** (długi ciąg `eyJ...`
   z polecenia `docker run ... --token eyJ...`). Samego polecenia nie uruchamiaj.
3. W zakładce **Public Hostname** dodaj: *Subdomain* `vva`, *Domain* `mojklub.pl`,
   *Service* **HTTP** `app:80`. Zapisz.

Strona będzie pod `https://vva.mojklub.pl`, certyfikat zapewnia Cloudflare.

### Opcja: własny certyfikat (`caddy`)

1. Domena (lub darmowy DDNS, np. DuckDNS) wskazuje na **publiczne IP** Twojego łącza.
2. Na routerze przekieruj porty **TCP 80, TCP 443 i UDP 443** na IP tego komputera w sieci domowej.
3. Windows: zezwól w Zaporze na porty 80 i 443 (Docker Desktop zwykle pyta o to sam).

Certyfikat Let's Encrypt pobierze i odnowi się automatycznie.

### Opcja testowa: tylko sieć domowa (`lan`)

`http://192.168.x.x` – do sprawdzenia, że wszystko działa. Instalacja jako aplikacja (PWA)
wymaga HTTPS, więc na telefonie zadziała dopiero w opcji `tunnel` lub `caddy`.

---

## Krok 4. Instalacja (jedno polecenie)

```bash
./vva.sh install
```

Kreator zapyta o tryb (`tunnel` / `caddy` / `lan`), domenę i ewentualnie token tunelu, sam
wygeneruje wszystkie hasła i klucze do `deploy/.env`, zbuduje obraz (pierwszy raz 5–10 minut),
uruchomi usługi i wykona migracje bazy. Na końcu pokaże adres strony.

Następnie:

1. Otwórz adres strony, **zarejestruj konto** i załóż klub.
2. Nadaj sobie rolę właściciela platformy:
   ```bash
   ./vva.sh make-admin twoj@email.pl
   ```
3. Sprawdź stan: `./vva.sh status` – wszystkie usługi powinny mieć status `running`
   (`app` dodatkowo `healthy`). Zielona kropka „online” w nagłówku oznacza, że WebSockets działają.

> `deploy/.env` zawiera hasła – nie wysyłaj go nikomu i nie dodawaj do repozytorium
> (jest w `.gitignore`). Zachowaj jego kopię w bezpiecznym miejscu: bez `APP_KEY` i hasła bazy
> kopie zapasowe są mniej użyteczne.

---

## Codzienna obsługa

| Co | Polecenie |
| --- | --- |
| Aktualizacja do najnowszej wersji z GitHuba | `./vva.sh update` |
| Stan usług + test strony | `./vva.sh status` |
| Logi na żywo (wszystkie / jedna usługa) | `./vva.sh logs` / `./vva.sh logs queue` |
| Zatrzymanie / uruchomienie | `./vva.sh stop` / `./vva.sh start` |
| Kopia zapasowa teraz | `./vva.sh backup` |
| Przywrócenie bazy | `./vva.sh restore backups/vva-db-2026-11-23_0300.sql.gz` |
| Polecenie artisan | `./vva.sh artisan about` |
| Konsola MySQL | `./vva.sh mysql` |
| Zmiana trybu / domeny | `./vva.sh setup`, potem `./vva.sh update` |

`update` robi kolejno: `git pull`, kopię bazy, przebudowę obrazu, restart usług i migracje.
Usługi same wstają po restarcie komputera (`restart: unless-stopped`).

### Kopie zapasowe

Usługa `backup` co 24 h zapisuje do `deploy/backups/`:
`vva-db-<data>.sql.gz` (baza) i `vva-files-<data>.tar.gz` (pliki z `storage/app`).
Trzyma 14 dni (`BACKUP_KEEP_DAYS`, `BACKUP_INTERVAL_HOURS` w `.env`).
Kopiuj ten katalog co jakiś czas poza komputer (dysk zewnętrzny, chmura) – awaria dysku
zabierze kopie razem z serwerem. Na Windows katalog widać w Eksploratorze pod
`\\wsl$\Ubuntu\home\<login>\vva\deploy\backups`.

### Przeniesienie danych z XAMPP (opcjonalnie)

Jeśli lokalnie masz dane w MySQL z XAMPP, w PowerShellu na komputerze z XAMPP:

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction vva > vva.sql
```

Skopiuj `vva.sql` do `deploy/backups/` na serwerze i w terminalu serwera:

```bash
gzip backups/vva.sql
./vva.sh restore backups/vva.sql.gz
```

(Nazwę bazy `vva` zamień na tę z Twojego lokalnego `.env`. Dane z SQLite trzeba przenieść
inaczej – daj znać, jeśli będzie potrzebne.)

---

## Moduł AI na GPU (na później)

Laptop z RTX 3060 może kiedyś liczyć analizę nagrań lokalnie. Szablon jest w
`compose.gpu.example.yml`: kontener `analyzer` dostaje kartę NVIDIA i rozmawia z API VVA
wewnątrz Dockera (`http://app/api/v1`), bez wychodzenia do internetu.

1. Sterownik NVIDIA: na Windows wystarczy aktualny sterownik Game Ready/Studio + Docker Desktop;
   na Linuksie dodatkowo **NVIDIA Container Toolkit**.
   Test: `docker run --rm --gpus all nvidia/cuda:12.6.3-base-ubuntu24.04 nvidia-smi`.
2. Gdy moduł powstanie: `cp compose.gpu.example.yml compose.gpu.yml`, w `.env` ustaw
   `COMPOSE_FILE=compose.yml:compose.gpu.yml` i `VVA_ANALYZER_URL=http://analyzer:8000`,
   token z *Panel klubu → Integracje AI* wpisz jako `VVA_ANALYZER_TOKEN`, potem `./vva.sh update`.

---

## Gdy coś nie działa

| Objaw | Co sprawdzić |
| --- | --- |
| `Docker nie działa` | Uruchom Docker Desktop (Windows) albo `sudo systemctl start docker` (Linux). |
| `port is already allocated` | Port 80/443 zajęty (XAMPP, IIS, Skype). Zatrzymaj program albo zmień `HTTP_PORT`/`HTTPS_PORT` w `.env`. |
| Tunel: błąd 502 / 1033 | `./vva.sh logs cloudflared`; w Cloudflare *Public Hostname* musi wskazywać **HTTP `app:80`**; token w `.env` bez spacji. |
| Caddy: brak certyfikatu | `./vva.sh logs app`; DNS musi wskazywać publiczne IP, porty 80/443 przekierowane. |
| Brak statystyk na żywo / kropki online | `./vva.sh logs reverb`; w Cloudflare włącz *Network → WebSockets* (domyślnie włączone). |
| Błąd 500 | `./vva.sh logs app` (błędy Laravela trafiają też do `storage/logs` w wolumenie). |
| Po zmianie `.env` nic się nie zmienia | `./vva.sh update` (konfiguracja jest cache'owana przy starcie). |
