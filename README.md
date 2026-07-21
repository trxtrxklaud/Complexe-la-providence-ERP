# Complexe La Providence ERP

**مدرسة العناية - نظام إدارة المدرسة**

## Architecture

- **Frontend**: React 19 + TypeScript + Vite + Tailwind (Arabic RTL)
- **Backend**: Laravel 11 + Sanctum + Horizon (Normalized database + Service Layer)

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=SchoolSeeder
php artisan serve

