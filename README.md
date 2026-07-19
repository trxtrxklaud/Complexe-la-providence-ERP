<div align="center">
<img width="1200" height="475" alt="GHBanner" src="https://ai.google.dev/static/site-assets/images/share-ais-513315318.png" />
</div>

# School La Providence ERP - Complete Production System

**مدرسة العناية - نظام إدارة المدرسة**

## Architecture
- **Frontend**: React 19 + TypeScript + Vite + Tailwind (Arabic RTL)
- **Backend**: Laravel 11 + Sanctum (Normalized database + Service Layer)

## Quick Start (Google AI Studio)

```bash
npm install
npm run dev
```

Login with:
- Email: `admin@erp.com`
- Password: `123456`

The app runs with intelligent mocks for demo purposes.

## Full Laravel Backend Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=SchoolSeeder
php artisan serve
```

## Key Features (Phases 1-3 Completed)

- Professional normalized database (Enrollments, Guardians, Fee Plans, Student Fees, Payment Allocations)
- Complete service layer (EnrollmentService, FeeService, PaymentService, StudentService, DashboardService)
- Real dashboard with outstanding balance calculation
- Student enrollment with level/section/guardian support
- RBAC with permissions

## Project Status

✅ Database Architecture (Production Grade)
✅ Backend Services Layer
✅ Dashboard & Student Management
✅ Frontend UI (Preserved & Connected)

---

**Ready for development and deployment.**
