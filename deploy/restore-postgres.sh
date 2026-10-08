#!/bin/sh
set -eu

: "${PGHOST:?PGHOST is required}"
: "${PGDATABASE:?PGDATABASE is required}"
: "${PGUSER:?PGUSER is required}"
: "${PGPASSWORD:?PGPASSWORD is required}"
: "${BACKUP_FILE:?BACKUP_FILE is required}"
: "${RESTORE_CONFIRM:?Set RESTORE_CONFIRM to the target database name}"

if [ "$RESTORE_CONFIRM" != "$PGDATABASE" ]; then
    printf 'RESTORE_CONFIRM must exactly match PGDATABASE (%s).\n' "$PGDATABASE" >&2
    exit 2
fi

if [ ! -r "$BACKUP_FILE" ]; then
    printf 'Backup is not readable: %s\n' "$BACKUP_FILE" >&2
    exit 2
fi

CHECKSUM_FILE="${BACKUP_FILE}.sha256"
if [ ! -r "$CHECKSUM_FILE" ]; then
    printf 'Checksum file is required: %s\n' "$CHECKSUM_FILE" >&2
    exit 2
fi

(
    cd "$(dirname "$BACKUP_FILE")"
    sha256sum --check "$(basename "$CHECKSUM_FILE")"
)
pg_restore \
    --clean \
    --if-exists \
    --exit-on-error \
    --no-owner \
    --no-acl \
    --dbname="$PGDATABASE" \
    "$BACKUP_FILE"

printf 'Restore completed into database: %s\n' "$PGDATABASE"
