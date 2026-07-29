#!/bin/bash

# Complexe La Providence ERP - Backup Script
# Owner: Complexe La Providence — Prod RH
# Run daily via cron: 0 2 * * * /var/www/providence/scripts/backup.sh
#
# Credentials are NOT stored in this file. Export them before running, e.g. in
# /etc/default/providence-backup then: set -a; . /etc/default/providence-backup; set +a

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/providence}"
APP_DIR="${APP_DIR:-/var/www/providence}"
DB_NAME="${DB_NAME:-providence_prod}"
DB_USER="${DB_USER:-providence}"
DB_PASS="${DB_PASS:-}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
DATE=$(date +%Y%m%d_%H%M%S)

if [ -z "$DB_PASS" ]; then
  echo "❌ DB_PASS is not set. Export it before running this script." >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"

echo "🔄 Starting backup at $(date)"

# 1. Database backup (the school's financial ledger lives here)
echo "→ Backing up database..."
mysqldump --user="$DB_USER" --password="$DB_PASS" --single-transaction --quick "$DB_NAME" \
  | gzip > "$BACKUP_DIR/db_backup_$DATE.sql.gz"

# 2. Storage files backup (photos, documents, etc.)
echo "→ Backing up storage..."
tar -czf "$BACKUP_DIR/storage_backup_$DATE.tar.gz" -C "$APP_DIR" storage/app

# 3. Retention
echo "→ Cleaning backups older than $RETENTION_DAYS days..."
find "$BACKUP_DIR" -type f -mtime +"$RETENTION_DAYS" -delete

echo "✅ Backup completed successfully: $DATE"
echo "📁 Backups stored in: $BACKUP_DIR"
