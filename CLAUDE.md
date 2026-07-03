# laravel-data-table — server-side DataTable builder за Laravel с Vue 3 + Inertia frontend

PHP 8.4+ / Laravel 10–13 backend пакет (`givanov95/laravel-data-table`) + Vue 3 / TypeScript frontend пакет (`@givanov95/vue-data-table`) в едно repo. Комуникация с потребителя: български. Код, commit-и и PR-и: английски.

Работният флоу (issue-та, PR-и) идва от плъгина `gws@claude-flow` — `/gws:issue <N>`. Този файл носи само спецификите на проекта.

## Branch-ове
- Базов branch: `main`. Issue branch-ове: `fix|feat|chore/N-kratko-ime` от него, PR към него, squash merge.
- Issue-то се затваря с `Fixes #N` в тялото на commit-а (базовият branch е default — затваря се при merge на PR-а).

## Deploy
- Няма — проектът не се качва на сървър. `/gws:ship` не е приложим тук; доставката е merge в базовия branch.

## Build и commit-и
- Няма build стъпка. Тестове: `composer test` (PHPUnit) за backend, `npm test` (Vitest) и `npm run typecheck` за frontend.
- Pre-commit hook от `givanov95/laravel-git-hooks`: php-cs-fixer, debug-statement guard, тестове. Прескачане: `SKIP_HOOK=1` или частично `SKIP_CSFIXER=1` / `SKIP_TESTS=1`.
- Commit стил: Conventional Commits на английски (`fix(scope): ...`).

## GitHub
- Нови issue-та се добавят в project board „gws".
