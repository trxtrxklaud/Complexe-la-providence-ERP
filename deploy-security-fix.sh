#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════
# Deploy سكربت — نشر الإصلاح الأمني + فهارس الأداء
# Commits: b6865818 (OTP إلزامي للوليّ) + 96bd3143 (فهارس الهواتف)
#
# التشغيل على السيرفر:  sudo bash deploy-security-fix.sh
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

APP_DIR="/var/www/providence"
cd "$APP_DIR"

echo "══ 1/6 سحب التحديثات ══"
git pull origin main

echo "══ 2/6 تشغيل Migrations (فهارس guardian_phone/mother_phone) ══"
php artisan migrate

echo "══ 3/6 ضبط OTP_CHANNEL للإنتاج (تعطيل dev_code) ══"
if grep -q "^OTP_CHANNEL=" .env; then
    sed -i 's/^OTP_CHANNEL=.*/OTP_CHANNEL=sms/' .env
else
    echo "OTP_CHANNEL=sms" >> .env
fi
echo "OTP_CHANNEL=$(grep '^OTP_CHANNEL=' .env)"

echo "══ 4/6 مسح وإعادة بناء الكاش ══"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "══ 5/6 فحص Redis ══"
echo "--- .env drivers ---"
grep -E "CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION" .env || true
echo "--- redis-cli ping ---"
redis-cli ping || echo "⚠️ Redis لا يستجيب — تحقق من الخدمة"

echo "══ 6/6 فحص الفهارس على MySQL ══"
mysql -u root -e "SHOW INDEX FROM providence.students WHERE Key_name LIKE '%guardian_phone%';"
mysql -u root -e "SHOW INDEX FROM providence.students WHERE Key_name LIKE '%mother_phone%';"

echo "══ ══ ══ Deploy اكتمل ══ ══ ══"
