# School La Providence ERP - Production Deployment Guide

## 1. Server Requirements

- PHP 8.2+
- MySQL 8.0+ / PostgreSQL 14+
- Node.js 20+ (for frontend build)
- Composer 2.6+
- Nginx or Apache
- Redis (recommended for queues & cache)
- SSL Certificate (Let's Encrypt recommended)

## 2. Environment Variables (.env)

Key variables:

```env
APP_NAME="School La Providence"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.laprovidence.ma

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=providence_prod
DB_USERNAME=providence
DB_PASSWORD=your_strong_password

SANCTUM_STATEFUL_DOMAINS=erp.laprovidence.ma
SESSION_DOMAIN=.laprovidence.ma

# Optional but recommended
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
```

## 3. Deployment Steps

### Step 1: Server Preparation

```bash
sudo apt update
sudo apt install php8.2 php8.2-fpm php8.2-mysql php8.2-bcmath \
php8.2-xml php8.2-mbstring php8.2-zip php8.2-gd composer nginx
```

### Step 2: Clone & Install

```bash
git clone <your-repo> /var/www/providence
cd /var/www/providence

composer install --no-dev --optimize-autoloader
npm install
npm run build
```

### Step 3: Environment & Permissions

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=SchoolSeeder --force

php artisan storage:link
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data /var/www/providence
```

### Step 4: Nginx Configuration

Create `/etc/nginx/sites-available/providence`:

```nginx
server {
    listen 443 ssl;
    server_name erp.laprovidence.ma;

    root /var/www/providence/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable site and reload Nginx.

### Step 5: SSL with Let's Encrypt

```bash
sudo certbot --nginx -d erp.laprovidence.ma
```

### Step 6: Supervisor for Queues (Recommended)

Create `/etc/supervisor/conf.d/providence-worker.conf`:

```ini
[program:providence-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/providence/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/providence/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start providence-worker:*
```

## 4. Frontend Deployment (Separate)

If deploying React frontend separately (recommended for performance):

```bash
npm run build
# Upload `dist/` folder to your CDN or static hosting (Vercel, Cloudflare Pages, etc.)
```

Update `VITE_API_URL` in frontend `.env` to point to your Laravel API.

## 5. Backup Strategy

- Daily database backup using `mysqldump`
- Daily file backup of `storage/app`
- Consider using Laravel Backup package

## 6. Monitoring & Logging

Recommended tools:
- Sentry (error tracking)
- Laravel Telescope (local) / Laravel Horizon (queues)
- UptimeRobot or Pingdom

## Security Checklist

- [ ] `APP_DEBUG=false` in production
- [ ] Strong database password
- [ ] Sanctum tokens properly configured
- [ ] CORS properly restricted
- [ ] Regular `composer audit` and `npm audit`
- [ ] WAF / Cloudflare protection enabled

---

**Last Updated**: July 2026
**Version**: Phase 3 Complete
