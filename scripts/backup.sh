#!/bin/bash

# School La Providence ERP - Backup Script
# Run daily via cron: 0 2 * * * /var/www/providence/scripts/backup.sh

set -e

BACKUP_DIR="/var/backups/providence"
DATE=$(date +%Y%m%d_%H%M%S)
APP_DIR="/var/www/providence"
DB_NAME="providence_prod"
DB_USER="providence_user"
DB_PASS="your_db_password_here"

mkdir -p $BACKUP_DIR

echo "🔄 Starting backup at $(date)"

# 1. Database Backup
echo "→ Backing up database..."
mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > "$BACKUP_DIR/db_backup_$DATE.sql.gz"

# 2. Storage Files Backup (photos, documents, etc.)
echo "→ Backing up storage..."
tar -czf "$BACKUP_DIR/storage_backup_$DATE.tar.gz" -C $APP_DIR storage/app

# 3. Keep only last 14 days of backups
echo "→ Cleaning old backups..."
find $BACKUP_DIR -type f -mtime +14 -delete

echo "✅ Backup completed successfully: $DATE"
echo "📁 Backups stored in: $BACKUP_DIR"
