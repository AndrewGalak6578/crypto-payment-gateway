# Repository Guidelines

## Project Structure & Module Organization
Core backend code lives in `app/` (controllers, services, jobs, models). HTTP routes are split across `routes/api.php` and `routes/web.php`. Frontend code is in `resources/js/` with portal-specific entry points under `admin-portal/` and `merchant-portal/`, shared UI in `components/`, and page modules in `pages/`. Database migrations, factories, and seeders are in `database/`. Tests are organized by intent: `tests/Feature`, `tests/Unit`, and `tests/Integration`.

## Build, Test, and Development Commands
- `composer setup`: one-time bootstrap (`composer install`, `.env` creation, app key, migrations, npm install, production asset build).
- `composer dev`: runs API server, queue listener, log stream, and Vite in one process group for local development.
- `npm run dev`: frontend hot-reload only.
- `npm run build`: production frontend bundle via Vite.
- `composer test`: clears config cache and runs default Laravel tests.
- `composer test:fast`: focused API/service/webhook suite.
- `composer test:integration`: real-RPC integration tests (`RUN_REAL_RPC_TESTS=true`).

## Coding Style & Naming Conventions
Follow `.editorconfig`: UTF-8, LF, spaces, 4-space indent (2 for `*.yml`/`*.yaml`). Use PSR-4 namespaces and keep class/file names aligned (`App\\Services\\InvoiceCreator` -> `app/Services/InvoiceCreator.php`). Use descriptive, domain-based names (`EvmGasTopUpService`, `MerchantWebhookDeliveriesPage.vue`). Prefer small service classes over controller-heavy logic. Format PHP with `./vendor/bin/pint` before opening a PR.

## Testing Guidelines
Write PHPUnit tests as `*Test.php`, matching target layers (`tests/Unit/Services/...`, `tests/Feature/Api/...`). Keep unit tests isolated (fakes/mocks), feature tests focused on HTTP behavior, and integration tests for real chain/RPC flows only. Run `composer test:fast` before every push; run `composer test:all` when touching settlement, forwarding, or RPC integrations.

## Commit & Pull Request Guidelines
Recent history follows concise, imperative commits with optional Conventional Commit prefixes (`feat(...)`, `fix(...)`) plus occasional merge commits. Prefer: `feat(admin-portal): add merchant wallet filters`. Keep commits scoped to one change. PRs should include: purpose, impacted areas (API/UI/jobs), test evidence (exact command output summary), linked issue, and screenshots for portal UI updates.

## Security & Configuration Tips
Never commit secrets from `.env`. Use safe defaults for local runs (for example `FORWARDING_ENABLED=false` when testing flows). Treat `tests/Integration` and RPC node configs under `nodes/` as development-only infrastructure, not production settings.
