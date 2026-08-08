# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
Every financial document (payment, salary, expense, advance, advance repayment, withdrawal) posts its cash effect into `cash_transactions` via `LedgerService::post()`. **Reports never read from source tables — only from `cash_transactions`**, so figures reconcile across every screen without double-counting. Posting is idempotent: a unique key `(source_type, source_id, category)` means re-posting a document updates its row rather than inserting a duplicate. When adding or changing any money-moving feature, route its cash effect through `LedgerService`.

### Service layer holds business logic
Controllers are thin; domain logic lives in `app/Services/` (`PaymentService`, `CollectionService`, `FeeService`, `EnrollmentService`, `LedgerService`, etc.). Key invariants enforced there:
- **Payments** (`PaymentService`) use an `idempotency_key` to dedupe retries, lock fee rows (`lockForUpdate`) so concurrent payments can't over-allocate, and subtract active waivers from the remaining balance.
- **Deletion is generally forbidden** for financial records — they are cancelled with a documented reason (e.g. `salaries/{id}/cancel`, advance repayment cancellation) rather than destroyed. Preserve this pattern.

### Discount (التخفيض) is NOT arrears (المتخلّد)
These are two different concepts and must never be conflated:
- **Arrears (المتخلّد) = unpaid fees.** Monthly/club/registration fees not yet paid. This IS debt owed to the school. Derived, not stored: `(fees − discount) − paid`.
- **Discount (التخفيض) = a fixed annual price reduction.** Applied once (typically September), valid for the whole academic year, does NOT change month-to-month, is NOT debt. It lowers what the family owes. **Capped at 20% of annual fees.**

Implementation:
- `enrollment_discounts` table + `EnrollmentDiscount` model hold the discount; `DiscountService` owns all rules (single active discount per enrollment per year, 20% cap, cancel-not-delete). The cap basis is the **`FeePlan`** (monthly × 10 + yearly), which is known up-front — NOT the ad-hoc `student_fees` rows, which are created month-by-month by `CollectionService` and are incomplete when a discount is granted.
- A discount **never posts to the ledger** — no cash moves. It only reduces the amount owed. Do not route it through `LedgerService`.
- `StudentFee.amount_due` stores the **full** item price. The transactional "discount" field was removed from the collection/payment flow (`CollectionService`, `CollectPaymentRequest`); the annual discount is managed separately via `DiscountController` (`/enrollments/{enrollment}/discount`, guarded by `waive_fees`).

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
- Older docs (`README.md`, `HANDOFF.md`) may reference Laravel 11 or stale info — `composer.json` and the code are authoritative.
- Never `git reset --hard`, `git clean -fd`, or `migrate:fresh` without explicit approval.

## Definition of done

Read a file fully before editing it; keep changes minimal and scoped; run the nearest test to the change; run `npm run build` for frontend/Vite changes and `php artisan test` for backend changes where feasible.

## Progress log

### Discount vs Arrears fix (in progress)
Separating the annual discount (التخفيض) from arrears (المتخلّد). Plan is 10 phases; each is user-approved and tested before the next.

- **Phase 1 — Migration ✅** `enrollment_discounts` table (`2026_08_06_000000_...`), fee_waivers-style (no FK constraints).
- **Phase 2 — Models ✅** `EnrollmentDiscount` (scopes `active`/`notCancelled`/`forYear`, `getEffectiveAmount`); `Enrollment::discounts()` + `activeDiscount()`.
- **Phase 3 — Services ✅** `DiscountService` (create/cancel, 20% cap on `FeePlan` basis, one-active rule, lockForUpdate). `CollectionService` now stores full price and ignores any transactional discount.
- **Phase 4 — API ✅** `DiscountController` (show/store/cancel) + `StoreDiscountRequest`; routes under `waive_fees`; `discount` field removed from `CollectPaymentRequest`.
- **Phase 5 — Frontend ✅** `api/discounts.ts`; `EnrollmentDiscountCard` on the student detail page; discount input removed from `CollectionPage`; `ReceiptModal` discount row now legacy-only.
- **Phase 6 — Fix arrears (next):** `ClassroomRosterController` — subtract discount (and waivers) in outstanding/`months_arrears`; add the discount column to `ClassroomRosterPage` (deferred here because it needs the controller's per-row data).
- **Phase 7 — Tests:** `DiscountServiceTest`, feature `DiscountTest`.

Verification so far: `php artisan test` 67 passing; `npm run lint` clean; `npm run build` OK.

## Security Closure Progress — 2026-08-08

- Canonical copy: `C:\laragon\www\providence`
- Branch: `audit/security-closure-2026-08-08`
- Baseline completed.
- Read-only security audit completed: no CRITICAL findings.
- HIGH finding fixed: dashboard treasury exposure.
- `DashboardService` conditionally returns `financial_summary`, `cash`, and `treasury_balance`.
- Financial access uses existing `manage_treasury` OR `view_reports` permissions, with configured super-role bypass.
- Cashier/restricted users do not receive those three keys server-side.
- `outstanding_balance` intentionally remains available for collection workflows.
- Verification: `php artisan test --filter=DashboardTest` — 4 tests passed, 18 assertions.
- Next task: review security headers separately.
- Unrelated Discounts UI changes must remain excluded.
- CRIT-001 closed: Student deletion blocked when enrollments, payments, or clubSubscriptions exist (`app/Http/Controllers/StudentController.php`).
- Focused test: `tests/Feature/StudentDeleteProtectionTest.php` (3 passed, 10 assertions).
- No migrations changed.
- CRIT-002 remains open.
