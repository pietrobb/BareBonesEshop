# Contributing

## Setup

```bash
git clone --recurse-submodules https://github.com/pietrobb/BareBonesEshop.git
cd BareBonesEshop
cp config.example.php config.php
php cli/test.php
```

PHP 8.1–8.5 with `pdo_sqlite`, `sodium`, `mbstring`, `dom` and `gd` (the extensions CI installs). In a clone without the `vendor/bbf`
submodule run `git submodule update --init`. For the browser checks install Node 22 or 24 and run `npm ci`
(npm 10 or 11; the lock file is valid for both).

## Branches and pull requests

- Work on a branch named `fix/<topic>` or `feat/<topic>`: CI runs on pushes to these and on pull requests to `main`.
- One topic per pull request. A bug fix comes with a test that fails without the fix (`cli/tests/scenarios/`,
  a new `*Regression.php` file is picked up automatically).
- Add a line to `CHANGELOG.md` under `## Unreleased` describing what changes for a shop owner.
- Shop-specific code belongs in `extensions/<code>/` (see `docs/EXTENSIONS.md`), a storefront design in a theme
  (`docs/THEMES.md`), not in core files.
- Released migrations are immutable (their checksums are pinned in `cli/tests/fixtures/`); change data with a
  new migration.

## Checks a pull request must pass

```bash
php cli/test.php                 # full suite on SQLite; TEST_MYSQL_DSN=... also runs it on MySQL/MariaDB
php cli/static-analysis.php      # architecture rules (thin entrypoints, module boundaries, events)
php cli/i18n-parity.php          # every language has every key
php cli/quality-gates.php        # WCAG, i18n and performance budgets
cli/phpstan.sh                   # PHPStan level 5 with the committed baseline, as in CI
npx eslint --max-warnings=0 .    # storefront scripts and tooling
npm run test:e2e                 # Playwright (start the shop first: php -S localhost:8080 router.php)
```

CI additionally runs the suite on PHP 8.1–8.5, MySQL 8.4 and MariaDB 11.4, the Docker deployment, Lighthouse and
the Bookshop package acceptance (`tests/bookshop/run.sh`).

## Security

Do not open a public issue for a vulnerability; see `SECURITY.md`.
