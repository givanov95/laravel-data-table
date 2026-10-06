# laravel-data-table — server-side DataTable builder за Laravel с Vue 3 + Inertia frontend

PHP 8.3+ / Laravel 12–13 backend пакет (`givanov95/laravel-data-table`) + Vue 3 / TypeScript frontend пакет (`@givanov95/vue-data-table`) в едно repo. Комуникация с потребителя: български. Код, commit-и и PR-и: английски.

Работният флоу (issue-та, PR-и) идва от плъгина `gws@claude-flow` — `/gws:issue <N>`. Този файл носи само спецификите на проекта.

## Branch-ове
- Базов branch: `main`. Issue branch-ове: `fix|feat|chore/N-kratko-ime` от него, PR към него, squash merge.
- Issue-то се затваря с `Fixes #N` в тялото на commit-а (базовият branch е default — затваря се при merge на PR-а).

## Deploy
- Няма — проектът не се качва на сървър. `/gws:ship` не е приложим тук; доставката е merge в базовия branch.

## Release
- Backend: таг `vX.Y.Z` на `main` — Packagist го взема сам.
- npm (`@givanov95/vue-data-table`): първо вдигни `version` в `package.json` и `package-lock.json` (`npm version X.Y.Z --no-git-tag-version`), merge-ни, после таг `vX.Y.Z` на този commit. Тагът пуска `.github/workflows/publish-npm.yml` (npm Trusted Publishing, без токен и OTP); пада, ако тагът не съвпада с `package.json`. Ръчно: `gh workflow run publish-npm.yml` от `main`.
- Не пускай `npm publish` от машината — акаунтът иска OTP.

## Build и commit-и
- Няма build стъпка. Тестове: `composer test` (PHPUnit) за backend, `npm test` (Vitest) и `npm run typecheck` за frontend.
- По подразбиране PHPUnit върви на SQLite в паметта. `DT_DB=mysql` или `DT_DB=pgsql` (с `DT_DB_HOST`/`PORT`/`DATABASE`/`USERNAME`/`PASSWORD`) пуска същите тестове на реален сървър, като след всеки тест **трие всички таблици в базата** — ползвай празна, отделна база. CI върви така на MySQL 8.4 и PostgreSQL 17. Двигателите се различават (регистър при LIKE, `sql_mode` на MySQL, функциите за дати), затова промяна по търсенето или датите се проверява и на тях, не само на SQLite. `tests/MysqlSqlModeTest.php` се пропуска без `DT_DB=mysql`.
- Pre-commit hook от `givanov95/laravel-git-hooks`: php-cs-fixer, debug-statement guard, тестове. Прескачане: `SKIP_HOOK=1` или частично `SKIP_CSFIXER=1` / `SKIP_TESTS=1`.
- Commit стил: Conventional Commits на английски (`fix(scope): ...`).

## GitHub
- Нови issue-та се добавят в project board „gws".
