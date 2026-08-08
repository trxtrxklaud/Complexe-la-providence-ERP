# Complexe La Providence ERP

**مدرسة العناية - نظام إدارة المدرسة**

> **Owner / الملكية:** Complexe La Providence — Prod RH · Sidi Bouzid, Tunisie
> © 2026 Complexe La Providence — Prod RH. Tous droits réservés. Logiciel propriétaire, usage interne à l'établissement.

## Architecture

- **Frontend**: React 19 + TypeScript + Vite + Tailwind (Arabic RTL)
- **Backend**: Laravel 12 + Sanctum + Horizon (Normalized database + Service Layer)

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=SchoolSeeder
php artisan serve
```
