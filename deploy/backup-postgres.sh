#!/bin/sh
set -eu

: "${PGHOST:?PGHOST is required}"
: "${PGDATABASE:?PGDATABASE is required}"
: "${PGUSER:?PGUSER is required}"
: "${PGPASSWORD:?PGPASSWORD is required}"

BACKUP_DIR="${BACKUP_DIR:-/backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUTPUT="${BACKUP_OUTPUT_FILE:-${BACKUP_DIR}/${PGDATABASE}-${TIMESTAMP}.dump}"
BACKUP_DIR="$(dirname "$OUTPUT")"

mkdir -p "$BACKUP_DIR"
umask 077
pg_dump --format=custom --no-owner --no-acl --file="$OUTPUT"
(
    cd "$BACKUP_DIR"
    sha256sum "$(basename "$OUTPUT")" > "$(basename "${OUTPUT}.sha256")"
)

if [ -n "${BACKUP_S3_URI:-}" ]; then
    : "${AWS_CLI_BIN:=aws}"
    "$AWS_CLI_BIN" s3 cp "$OUTPUT" "${BACKUP_S3_URI%/}/$(basename "$OUTPUT")" --only-show-errors
    "$AWS_CLI_BIN" s3 cp "${OUTPUT}.sha256" "${BACKUP_S3_URI%/}/$(basename "${OUTPUT}.sha256")" --only-show-errors
fi

find "$BACKUP_DIR" -type f \( -name '*.dump' -o -name '*.dump.sha256' \) -mtime "+$RETENTION_DAYS" -delete
printf 'Backup completed: %s\n' "$OUTPUT"
