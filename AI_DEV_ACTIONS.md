# AI / Developer Action Items and Change Log

This document lists concrete action items, suggested issue titles and descriptions, and recommended files/commands so any developer or AI model can apply them.

Repository: trxtrxklaud/Complexe-la-providence-ERP
Date: 2026-08-06
Author: Copilot (automation)

---

## Summary
Found during repo review:
- Frontend: React 19 + TypeScript + Vite + Tailwind
- Backend: Laravel (composer.json requests ^12.0)
- Documentation inconsistency: README.md mentions Laravel 11 and Horizon but composer.json requests Laravel ^12.0 and Horizon is not required.
- Tests scaffold missing: phpunit.xml is present but tests/ is empty.
- No visible CI/CD workflows (.github/workflows absent).
- Docker / docker-compose present and deployment docs available.

This document encodes recommended actionable tasks as issues and provides a step-by-step recipe for developers or automation to implement them.

---

## Action items (priority order)

1) Fix documentation / dependency mismatch
- Issue title: docs: reconcile Laravel version and Horizon mention
- Description: Update README.md and DEPLOYMENT.md to reflect the actual Laravel version in composer.json (currently ^12.0). If Horizon is used, add it to composer.json and document how to configure it; otherwise remove Horizon from README. Verify other mentions (Laravel 11 vs 12).
- Files to change: README.md, DEPLOYMENT.md, composer.json (only if adding horizon)
- Suggested commit message: docs: align README and deployment docs with composer.json (Laravel 12)

2) Add continuous integration (CI) workflow
- Issue title: ci: add GitHub Actions for PHP and frontend build/tests
- Description: Add .github/workflows/ci.yml to run on push/PR. Steps:
  - Setup PHP 8.2, composer install, composer dump-autoload
  - Run php artisan config:clear, phpunit
  - Setup Node (16/18/20 LTS), npm ci, npm run build or npm run lint
  - Optionally run typecheck (tsc --noEmit)
- Files to add: .github/workflows/ci.yml
- Suggested commit message: ci: add GitHub Actions CI (phpunit + frontend build)

3) Scaffold basic tests and add seed data for tests
- Issue title: test: add initial PHPUnit feature tests and example factories
- Description: Create initial tests for critical flows (Student creation/enrollment, Payment processing). Add factories/seeders used by tests (or use in-memory sqlite). Ensure phpunit.xml is configured for CI.
- Files to add: tests/Feature/StudentEnrollmentTest.php, tests/Feature/PaymentTest.php, database/factories/* (if missing)
- Suggested commit message: test: add basic feature tests for enrollment and payments

4) Add an AI/Developer-readable changelog and deployment checklist (this file exists)
- Issue title: docs: add CHANGELOG and deployment checklist for ops
- Description: Keep a machine-readable changelog (CHANGELOG_AI.md or AI_DEV_ACTIONS.md) listing changes and migration steps. This file (AI_DEV_ACTIONS.md) was added with actionable items.
- Suggested commit message: docs: add AI/Developer action items and changelog

5) Security review for environment and Sanctum/CORS
- Issue title: security: review SANCTUM/CORS and .env defaults
- Description: Verify .env.example does not leak secrets. Ensure Sanctum settings, CSRF, cookie domain, and CORS are correctly set for Vite dev server and production domain. Document required env vars in .env.example.
- Suggested commit message: chore: document required env vars and security notes

6) Optional: Add Laravel Horizon if used
- Issue title: infra: add Laravel Horizon and configure supervisors
- Description: If the project expects Horizon, add "laravel/horizon" to composer.json, configure horizon.php and supervisor/process management, and document redis usage in DEPLOYMENT.md.
- Suggested commit message: feat: add laravel/horizon and deployment notes

---

## Suggested files to add (examples)

1) .github/workflows/ci.yml (high-level)
- Run on: push, pull_request
- Jobs: phpunit (runs composer install, runs phpunit); frontend (node install, npm ci, npm run build/tsc)

2) tests/Feature/StudentEnrollmentTest.php (starter example)
- Use Illuminate\Foundation\Testing\RefreshDatabase
- Example: assert enrollment creates Student + Enrollment record

3) README.md: fix version lines and add "How to run CI locally" section

---

## How an AI or developer should apply these changes (step-by-step)

1) Create a new branch for each task, e.g., `fix/docs/laravel-version`.
2) Make minimal changes to README and DEPLOYMENT to reflect composer.json (or update composer.json if the code actually depends on Laravel 11).
3) Commit with the suggested commit message and open a PR with a short description and the CI checklist.
4) For CI: add .github/workflows/ci.yml with matrix jobs if desired; ensure secrets (DB, CACHE_URL) are configured in repository settings.
5) For tests: scaffold tests that run on sqlite in-memory to make CI fast.
6) Run the CI locally (act) or push to a draft PR and iterate.

---

## Machine-readable checklist (JSON)

{
  "repo": "trxtrxklaud/Complexe-la-providence-ERP",
  "date": "2026-08-06",
  "actions": [
    {"id": 1, "title": "docs: reconcile Laravel version and Horizon mention", "status": "open"},
    {"id": 2, "title": "ci: add GitHub Actions for PHP and frontend build/tests", "status": "open"},
    {"id": 3, "title": "test: add initial PHPUnit feature tests and example factories", "status": "open"},
    {"id": 4, "title": "docs: add CHANGELOG and deployment checklist for ops", "status": "open"},
    {"id": 5, "title": "security: review SANCTUM/CORS and .env defaults", "status": "open"},
    {"id": 6, "title": "infra: add Laravel Horizon and configure supervisors (optional)", "status": "open"}
  ]
}

---

If you want, I can now:
- create GitHub issues from the above action items (one issue per action). Note: I will create them in the repository when you confirm.
- add a starter .github/workflows/ci.yml and basic test scaffold. 

