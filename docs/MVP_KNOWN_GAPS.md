# MVP Known Gaps

## Scope
Ниже только ограничения, которые остаются актуальными для текущего состояния репозитория.

## Remaining Real Gaps

### 1) Test webhook routes остаются в runtime
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

### 4) Settlement ledger требует backfill для старых invoice rows
- Факт: settlement ledger заполняется новыми flow, а для старых данных есть команда `settlements:backfill-ledger`.
- Риск: старые инвойсы с `forward_txids` или internal-credit итогом не появятся в ledger без backfill.
- Статус: перед demo/release на существующей базе выполнить dry-run и затем backfill.

### 5) Real-chain integration tests не гарантированно выполняются в каждом прогоне
- Факт: `tests/Integration/RealRpcSmokeTest.php` и `tests/Integration/RealChains/RealChainForwardingTest.php` используют `markTestSkipped` при отсутствии `RUN_REAL_RPC_TESTS=true`/финансирования кошельков.
- Риск: базовый прогон может остаться без полного real-chain сигнала.
- Статус: это не дефект кода, а ограничение режима запуска.

### 6) Нет browser E2E-пака для portal flows в репозитории
- Факт: в `tests/` есть `Feature/Unit/Integration` (PHPUnit), но нет UI E2E набора (Cypress/Playwright/Dusk).
- Риск: регрессии Merchant Portal v2/Admin Portal маршрутизации и адаптивного UI ловятся в основном ручным smoke + `npm run build`.
- Статус: для текущего verification pack закрывается manual smoke разделом.

### 7) Activity logging есть, но нет отдельного глобального audit console
- Факт: `merchant_activity_logs` пишутся для auth/team/settings/developer/wallet/invoice actions и видны через teammate dossier.
- Риск: поиск по всем действиям мерчанта пока не вынесен в отдельный audit-log модуль.
- Статус: teammate dossier закрывает персональный audit сценарий; глобальный audit console остается будущим модулем.

### 8) Mainnet/custody hardening не заявлен
- Факт: EVM local/dev и UTXO testnet/local flows есть, но production custody/signing/compliance contour не является целью текущего MVP.
- Риск: нельзя позиционировать проект как готовый regulated custody/payment gateway.
- Статус: проект демонстрирует backend architecture и operational flows; production hardening отдельный этап.
