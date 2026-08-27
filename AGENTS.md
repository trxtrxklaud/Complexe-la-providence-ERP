# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## Project

`Complexe La Providence ERP` — internal management system for a private school in Sidi Bouzid, Tunisia. Covers students, enrollment, fee collection, employees, salaries, expenses, treasury, and financial reports. The UI is **Arabic RTL**, currency is Tunisian Dinar (TND). Most comments and user-facing strings are in Arabic.

Laravel 12 (PHP 8.2+) serves a JSON API; a React 19 SPA is the only consumer. There is no Blade UI beyond `resources/views/app.blade.php`, which bootstraps Vite.

## Commands

```bash
# Backend
php artisan serve --host=127.0.0.1 --port=8001
php artisan test                                  # full suite (SQLite in-memory)
php artisan test --filter=PaymentServiceTest      # single test class
php artisan test tests/Feature/FeeWaiverTest.php  # single file
composer test                                     # config:clear then phpunit
./vendor/bin/pint                                 # PHP formatter (Laravel Pint)

# Frontend
npm run dev        # Vite dev server (default port 5173)
npm run build      # production build → public/build
npm run lint       # tsc --noEmit (type-check only; no ESLint)
```

On Windows/Laragon, use Laragon's PHP path if `php` is not on `PATH`.

Dev DB is **SQLite with real data** — never run `php artisan migrate:fresh`. Tests use a separate in-memory SQLite DB (see `phpunit.xml`).

## Architecture

### Ledger is the single source of truth for money
Every financial document (payment, salary, expense, advance, advance repayment, withdrawal) posts its cash effect into `cash_transactions` via `LedgerService::post()`. **Reports never read from source tables — only from `cash_transactions`**, so figures reconcile across every screen without double-counting. Posting is idempotent: a unique key `(source_type, source_id, category)` means re-posting a document updates its row rather than inserting a duplicate. When adding or changing any money-moving feature, route its cash effect through `LedgerService`. A one-off backfill command (`app/Console/Commands/BackfillLedgerCommand.php`) rebuilds `cash_transactions` from the source tables when the ledger needs repopulating.

### Service layer holds business logic
Controllers are thin; domain logic lives in `app/Services/` (`PaymentService`, `CollectionService`, `FeeService`, `EnrollmentService`, `LedgerService`, etc.). Key invariants enforced there:
- **Payments** (`PaymentService`) use an `idempotency_key` to dedupe retries, lock fee rows (`lockForUpdate`) so concurrent payments can't over-allocate, and subtract active waivers from the remaining balance.
- **Deletion is generally forbidden** for financial records — they are cancelled with a documented reason (e.g. `salaries/{id}/cancel`, advance repayment cancellation) rather than destroyed. Preserve this pattern.
- **Collection & families:** `CollectionService` creates `student_fees` month-by-month on demand and records payments; the family module (`FamilyController` / `FamilyService`, `POST /families/{family}/collect`) settles several students' fees in one transaction. Both live under `manage_payments`.

### Discount (التخفيض) is NOT arrears (المتخلّد)
These are two different concepts and must never be conflated:
- **Arrears (المتخلّد) = unpaid fees.** Monthly/club/registration fees not yet paid. This IS debt owed to the school. Derived, not stored: `(fees − discount) − paid`.
- **Discount (التخفيض) = a fixed annual price reduction.** Applied once (typically September), valid for the whole academic year, does NOT change month-to-month, is NOT debt. It lowers what the family owes. **Capped at 20% of annual fees.**

Implementation:
- `enrollment_discounts` table + `EnrollmentDiscount` model hold the discount; `DiscountService` owns all rules (single active discount per enrollment per year, 20% cap, cancel-not-delete). The cap basis is the **`FeePlan`** (monthly × 10 + yearly), which is known up-front — NOT the ad-hoc `student_fees` rows, which are created month-by-month by `CollectionService` and are incomplete when a discount is granted.
- A discount **never posts to the ledger** — no cash moves. It only reduces the amount owed. Do not route it through `LedgerService`.
- `StudentFee.amount_due` stores the **full** item price. The transactional "discount" field was removed from the collection/payment flow (`CollectionService`, `CollectPaymentRequest`); the annual discount is managed separately via `DiscountController` (`/enrollments/{enrollment}/discount`, guarded by `waive_fees`).

**Two separate discount families exist — do not merge them:**
- **Annual discount** (`EnrollmentDiscount` / `DiscountService`, described above): one fixed reduction for the whole year, 20% cap on the `FeePlan` basis, one active per enrollment/year.
- **Recurring monthly discounts** (`MonthlyDiscount` for tuition, `ClubMonthlyDiscount` for clubs; `MonthlyDiscountService` / `ClubMonthlyDiscountService`): applied per month across a `start_month..end_month` range (defaults to the academic year). Three types — `full_waiver`, `humanitarian_fixed` (> 20 TND, ≤ monthly fee), `normal_monthly` (≤ 20% of the monthly `FeePlan` amount). Overlapping active ranges for the same enrollment/category are rejected; cancel-not-delete with `lockForUpdate`.

Both discount families and fee waivers are guarded by `waive_fees` (never `manage_payments`) and **never post to the ledger** — the cashier collects money but cannot forgive debt; only the owner reduces what the school earns.

### Auth & permissions
- Sanctum bearer tokens. Every authenticated route passes through `auth:sanctum` + `active` (`EnsureUserIsActive`, blocks disabled accounts) + rate limiting.
- Granular permissions via the `permission:<name>` middleware (`CheckPermission`), grouped in `routes/api.php` (e.g. `manage_employees`, `manage_salaries`).
- `config/permissions.php` defines `super_roles` (bypass granular checks) from the `PERMISSION_SUPER_ROLES` env var, default `admin`.
- Route order and middleware nesting in `routes/api.php` form the API contract — reordering can cause 403s or break the frontend.

### Frontend structure
- `resources/js/main.tsx` → entry; `App.tsx` → route tree, guards, and `React.lazy()` code-splitting for heavy pages (kept eager: `AuthProvider`, `BrowserRouter`, layout, `Sidebar`, `ProtectedRoute`, Login, Dashboard, core student routes).
- `resources/js/api/http.ts` is the **single HTTP layer** — token storage (localStorage), headers, and the `ApiError` class live here. Do not read the token or build headers elsewhere.
- Each `api/*.ts` file maps to a backend resource; `pages/` are feature folders; `contexts/AuthContext.tsx` holds session state.
- `@` aliases `resources/js` (see `vite.config.ts`).

## Constraints

- **Sensitive / do-not-touch without explicit permission and dedicated tests:** `LedgerService`, `CashTransaction`, `PaymentService`, `CollectionService`, `TreasuryDaybookController`, `FinancialReportController`, and all auth/permission logic (Sanctum, `AuthController`, user FormRequests).
- In `app.blade.php`, `@viteReactRefresh` **must** stay before `@vite(...)` or the React preamble error returns.
- `vite.config.ts` `manualChunks` splitting is deliberate — don't raise `chunkSizeWarningLimit` to hide warnings, and don't hand-edit `public/build` output.
- Pre-existing TypeScript errors exist unrelated to any given task; check `npm run lint` output but don't fix unrelated files without being asked.
- Older docs (`README.md`, `HANDOFF.md`) may carry stale info — e.g. `README.md` references Laravel Horizon, which is **not installed** (see `composer.json`). Treat `composer.json` and the code as authoritative.
- Never `git reset --hard`, `git clean -fd`, or `migrate:fresh` without explicit approval.

## Definition of done

Read a file fully before editing it; keep changes minimal and scoped; run the nearest test to the change; run `npm run build` for frontend/Vite changes and `php artisan test` for backend changes where feasible.

## Security invariants

- **Financial data is gated server-side.** `DashboardService` only returns `financial_summary`, `cash`, and `treasury_balance` to users with `manage_treasury` or `view_reports` (super-roles bypass); cashiers never receive those keys. `outstanding_balance` stays available for collection workflows.
- **Records with financial dependents cannot be deleted.** Student deletion is blocked when enrollments, payments, or club subscriptions exist (`StudentController`); employee deletion is blocked when salaries, advances, or repayments exist (`EmployeeController` / `Employee`). This complements the cancel-not-delete rule above.
