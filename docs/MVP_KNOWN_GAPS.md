# MVP Known Gaps (current `main`)

## Scope
Ниже только то, что подтверждается текущим кодом/тестами/конфигами репозитория, без опоры на `README`.

## Remaining Real Gaps

### 1) Временные test webhook routes остаются в runtime
- Факт: `routes/api.php` содержит `/api/test/webhook-receiver` и `/api/test/webhook-fail` с комментарием `TODO: Delete them when not needed`.
- Риск: лишние технические endpoints могут остаться включёнными в окружении.
- Статус: не блокирует verification, но требует явного контроля окружений.

### 2) Dev RPC EVM deriver — только local/testing и конечный пул
- Факт: `app/Services/PaymentAddresses/Evm/DevRpcAccountAddressDeriver.php` помечен как `Temporary dev-only`, запрещён вне `local/testing` и бросает ошибку при исчерпании `eth_accounts`.
- Риск: EVM allocation может падать по мере исчерпания пула адресов.
- Статус: допустимо для local smoke, не подходит как постоянный production-механизм.

### 3) Переходный слой legacy `coin` + `asset_key/network_key`
- Факт: есть backfill-команда `app/Console/Commands/BackfillAssetKeys.php`, `config/assets.php` хранит `legacy_coin`, и часть кода явно обрабатывает legacy ветку.
- Риск: при неполном backfill возможны неоднозначности в данных и фильтрах.
- Статус: управляемый риск, проверить миграции/данные перед релиз-пассом.

### 4) Real-chain integration tests не гарантированно выполняются в каждом прогоне
- Факт: `tests/Integration/RealRpcSmokeTest.php` и `tests/Integration/RealChains/RealChainForwardingTest.php` используют `markTestSkipped` при отсутствии `RUN_REAL_RPC_TESTS=true`/финансирования кошельков.
- Риск: базовый прогон может остаться без полного real-chain сигнала.
- Статус: это не дефект кода, а ограничение режима запуска.

### 5) Нет browser E2E-пака для portal flows в репозитории
- Факт: в `tests/` есть `Feature/Unit/Integration` (PHPUnit), но нет UI E2E набора (Cypress/Playwright/Dusk).
- Риск: регрессии UI/маршрутизации ловятся в основном ручным smoke.
- Статус: для текущего verification pack закрывается manual smoke разделом.
