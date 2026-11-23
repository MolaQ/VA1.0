#!/bin/sh
# VVA database + files backup. Runs inside the "backup" container.
#   backup.sh once   -> one backup now
#   backup.sh        -> loop: backup every BACKUP_INTERVAL_HOURS, keep BACKUP_KEEP_DAYS
set -eu

INTERVAL_HOURS="${BACKUP_INTERVAL_HOURS:-24}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
DIR=/backups

run_backup() {
    stamp=$(date +%Y-%m-%d_%H%M)
    mkdir -p "$DIR"
    echo "[backup] $stamp: dumping database ${DB_DATABASE}…"
    MYSQL_PWD="$DB_ROOT_PASSWORD" mysqldump -h mysql -u root \
        --single-transaction --quick --routines --triggers --no-tablespaces \
        "$DB_DATABASE" | gzip > "$DIR/vva-db-$stamp.sql.gz.part"
    mv "$DIR/vva-db-$stamp.sql.gz.part" "$DIR/vva-db-$stamp.sql.gz"

    if [ -d /storage/app ]; then
        tar -czf "$DIR/vva-files-$stamp.tar.gz.part" -C /storage app
        mv "$DIR/vva-files-$stamp.tar.gz.part" "$DIR/vva-files-$stamp.tar.gz"
    fi

    find "$DIR" -name 'vva-*' -type f -mtime +"$KEEP_DAYS" -delete
    echo "[backup] done: $DIR/vva-db-$stamp.sql.gz"
}

if [ "${1:-loop}" = "once" ]; then
    run_backup
    exit 0
fi

echo "[backup] every ${INTERVAL_HOURS} h, keeping ${KEEP_DAYS} days"
sleep 120   # let MySQL settle after start
while true; do
    run_backup || echo "[backup] FAILED" >&2
    sleep $((INTERVAL_HOURS * 3600))
done
