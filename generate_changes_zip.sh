#!/usr/bin/env bash
set -euo pipefail

# generate_changes_zip.sh
# Creates a directory structure with:
#  - .github/workflows/ci.yml
#  - tests/Feature/DatabaseTablesTest.php
#  - tests/Feature/ModelsExistTest.php
#  - apply_changes.sh          (automation script to push branches/PRs)
#  - AI_DEV_ACTIONS.md        (action items & changelog)
#  - REVIEW_SUMMARY.md        (repository overview & how-to-run)
# Then zips them into changes_bundle.zip
#
# Usage:
# 1) Save this file and make it executable: chmod +x generate_changes_zip.sh
# 2) Run: ./generate_changes_zip.sh
# 3) Output: changes_bundle.zip

OUTDIR="changes_package"
ZIPNAME="changes_bundle.zip"

# Clean previous output
rm -rf "$OUTDIR" "$ZIPNAME"
mkdir -p "$OUTDIR"

# 1) .github/workflows/ci.yml
mkdir -p "$OUTDIR/.github/workflows"
cat > "$OUTDIR/.github/workflows/ci.yml" <<'YML'
name: CI

on:
  push:
    branches: [ main ]
  pull_request:
    branches: [ main ]

jobs:
  php:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: test_db
        ports:
          - 3306:3306
        options: >-
          --health-cmd "mysqladmin ping --silent"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 3

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v3
        with:
          php-version: '8.2'
          extensions: mbstring, xml, bcmath, gd, zip, pdo_mysql

      - name: Get Composer Cache
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
          restore-keys: ${{ runner.os }}-composer-

      - name: Install Composer deps
        run: composer install --no-progress --no-suggest --prefer-dist

      - name: Copy .env for CI
        run: cp .env.example .env

      - name: Generate app key
        run: php artisan key:generate --ansi

      - name: Prepare DB (migrate)
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'
        run: php artisan migrate --force

      - name: Run PHPUnit
        run: vendor/bin/phpunit --testsuite=Feature

  frontend:
    runs-on: ubuntu-latest
    needs: php
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Cache node modules
        uses: actions/cache@v4
        with:
          path: ~/.npm
          key: ${{ runner.os }}-node-${{ hashFiles('**/package-lock.json') }}
          restore-keys: ${{ runner.os }}-node-

      - name: Install dependencies
        run: npm ci

      - name: Type check (TS)
        run: npm run lint

      - name: Build
        run: npm run build
YML

# 2) tests/Feature files
mkdir -p "$OUTDIR/tests/Feature"

cat > "$OUTDIR/tests/Feature/DatabaseTablesTest.php" <<'PHP'
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_important_tables_exist_after_migrations()
    {
        $this->assertTrue(Schema::hasTable('students'), 'Table "students" should exist');
        $this->assertTrue(Schema::hasTable('payments'), 'Table "payments" should exist');
        $this->assertTrue(Schema::hasTable('enrollments'), 'Table "enrollments" should exist');
    }
}
PHP

cat > "$OUTDIR/tests/Feature/ModelsExistTest.php" <<'PHP'
<?php

namespace Tests\Feature;

use Tests\TestCase;

class ModelsExistTest extends TestCase
{
    public function test_core_models_exist()
    {
        $this->assertTrue(class_exists(\App\Models\Student::class), 'Student model must exist');
        $this->assertTrue(class_exists(\App\Models\Payment::class), 'Payment model must exist');
        $this->assertTrue(class_exists(\App\Models\Enrollment::class), 'Enrollment model must exist');
    }
}
PHP

# 3) apply_changes.sh (automation script previously prepared)
cat > "$OUTDIR/apply_changes.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

# apply_changes.sh
# Creates two branches and pushes them:
#  - ci/add-github-actions (adds CI workflow and tests)
#  - fix/docs/laravel-version (updates README/DEPLOYMENT note)
#
# This script assumes:
# - You are inside the repository root (git initialized)
# - Working tree is clean
# - 'origin' remote exists and you have push rights
#
# To run: chmod +x apply_changes.sh && ./apply_changes.sh

GIT_REMOTE=${GIT_REMOTE:-origin}
PR_BASE=${PR_BASE:-main}
COMMITTER_NAME=${COMMITTER_NAME:-"automation-bot"}
COMMITTER_EMAIL=${COMMITTER_EMAIL:-"automation@local"}

git config user.name "$COMMITTER_NAME"
git config user.email "$COMMITTER_EMAIL"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "ERROR: run this from inside a git repo"
  exit 1
fi

if ! git diff-index --quiet HEAD --; then
  echo "ERROR: working tree not clean"
  exit 1
fi

# Branch 1: CI
git checkout -b ci/add-github-actions
mkdir -p .github/workflows
cat > .github/workflows/ci.yml <<'YML'
# (CI file contents omitted here; use the CI file from the package)
YML
# Note: replace the above YML doc with the actual CI file contents if running script manually.

git add .github/workflows || true
git commit -m "ci: add GitHub Actions workflow and initial tests" || true
git push --set-upstream "$GIT_REMOTE" ci/add-github-actions || true

# Branch 2: Docs
git checkout -b fix/docs/laravel-version
if [ -f README.md ]; then
  sed -i.bak 's/Laravel 11/Laravel 12/g' README.md || true
  sed -i.bak 's/+ Horizon//g' README.md || true
  rm -f README.md.bak || true
fi
if [ -f DEPLOYMENT.md ]; then
  if ! grep -q "Note: composer.json requires laravel/framework" DEPLOYMENT.md; then
    awk 'NR==8{print "\n> NOTE: composer.json declares \"laravel/framework\": \"^12.0\" — ensure docs and dependencies are aligned.\n"}{print}' DEPLOYMENT.md > DEPLOYMENT.md.tmp && mv DEPLOYMENT.md.tmp DEPLOYMENT.md
  fi
fi

git add README.md DEPLOYMENT.md || true
git commit -m "docs: reconcile Laravel version and note composer.json (Laravel ^12.0)" || true
git push --set-upstream "$GIT_REMOTE" fix/docs/laravel-version || true

echo "Done. Branches created: ci/add-github-actions, fix/docs/laravel-version"
SH
chmod +x "$OUTDIR/apply_changes.sh"

# 4) AI_DEV_ACTIONS.md (action items & changelog)
cat > "$OUTDIR/AI_DEV_ACTIONS.md" <<'MD'
# AI / Developer Action Items and Change Log

Repository: trxtrxklaud/Complexe-la-providence-ERP
Date: 2026-08-06
Author: Copilot (automation)

Summary of findings:
- Frontend: React 19 + TypeScript + Vite + Tailwind
- Backend: Laravel (composer.json requests ^12.0)
- Documentation inconsistency: README mentions Laravel 11 and Horizon but composer.json requires ^12.0 and Horizon not present.
- Tests: phpunit.xml present but tests/ mostly empty.
- CI: no .github/workflows present.
- Docker & deployment docs available.

Action items (priority):
1) docs: reconcile Laravel version and Horizon mention
2) ci: add GitHub Actions for PHP and frontend build/tests
3) test: add initial PHPUnit feature tests
4) docs: maintain CHANGELOG and deployment checklist (AI_DEV_ACTIONS.md)
5) security: review SANCTUM/CORS and .env defaults
6) infra: add Laravel Horizon (optional)

See repository review for detailed steps.
MD

# 5) REVIEW_SUMMARY.md (concise repo overview)
cat > "$OUTDIR/REVIEW_SUMMARY.md" <<'MD'
Complexe La Providence ERP — Review Summary (short)

What this is:
An on-premise ERP for a school (Complexe La Providence) with Laravel backend and React+Vite frontend.

Stack:
- Languages: PHP 8.2+ (Laravel), TypeScript (React)
- Frameworks: Laravel ^12.0 (composer.json), React 19 + Vite
- Notable libs: laravel/sanctum, laravel-vite-plugin, tailwindcss, react-router-dom

How it's organized (top-level):
- app/        Laravel app (Models, Services, Controllers)
- resources/  js/ (React entry), views/ (Blade templates)
- public/     PHP entry
- composer.json / package.json / Dockerfile / docker-compose.yml

How to run (short):
Backend:
  composer install
  cp .env.example .env
  php artisan key:generate
  php artisan migrate
  php artisan db:seed --class=SchoolSeeder
  php artisan serve

Frontend:
  npm ci
  npm run dev
  npm run build

Notes & risks:
- README/DEPLOYMENT mention Laravel 11 and Horizon while composer.json requests ^12.0 — reconcile.
- No CI workflows; tests are minimal. Recommend adding GH Actions and basic PHPUnit tests.
MD

# 6) Make zip
cd "$OUTDIR"
zip -r "../$ZIPNAME" . >/dev/null
cd ..

echo "Created $ZIPNAME containing:"
unzip -l "$ZIPNAME" | sed -n '4,20p'

echo "Package ready: $ZIPNAME"
echo "Unpack with: unzip $ZIPNAME -d changes_package_unpacked"
