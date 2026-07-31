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

### 9) Нет operator workflow для release held/manual settlement
- Факт: merchant Settlement Rules меняют только будущие policy evaluations; `held` и `manual` являются terminal для automatic retry и не снимаются автоматически даже после добавления destination-wallet, но авторизованного endpoint/command для release или регистрации external settlement пока нет.
- Риск: ручная смена `forward_status` обходит audit/idempotency semantics и может привести к повторному движению средств.
- Статус: нужен отдельный operator flow с invoice lock, actor/reason audit, policy re-resolution и атомарным выбором retry либо external completion.

### 10) `max_gas_cost` пока не enforced
- Факт: значение участвует в policy decision и ledger metadata, но EVM sender/gas sponsorship не проверяют его.
- Риск: поле нельзя считать production spending limit; автоматический sweep может быть дороже настроенного значения.
- Статус: требуется определить denomination, gas estimate interface и поведение при превышении лимита.

### 11) Нет operator UI для quarantined settlement/gas-funding evidence
- Факт: `SettlementAttemptReconciler` и `EvmGasFundingReconciler`, их jobs и Artisan commands автоматически проверяют tx identity, receipt/wallet evidence и confirmations. Inconclusive rows остаются `needs_reconciliation`.
- Риск: оператор пока должен запускать commands и исследовать RPC/DB evidence вручную; нет безопасного UI для просмотра evidence, attach proven tx hash или audited disposition.
- Статус: автоматический resend запрещён. Нужен отдельный авторизованный operator workflow, который не изменяет immutable accounting и не объявляет retry-safe без chain proof.

### 12) Webhook transport остаётся at-least-once
- Факт: `invoice.forwarded` delivery создаётся transactionally с settlement completion, имеет unique idempotency key и восстанавливается scheduler command. HTTP request может быть принят merchant endpoint до worker crash.
- Риск: повторная HTTP delivery возможна после ambiguous transport failure.
- Статус: merchant должен дедуплицировать по `X-Webhook-Delivery-Id`; exactly-once через внешний HTTP transport не обещается.

### 13) Legacy wallets с `network_key=null` требуют backfill
- Факт: settlement wallet resolver временно принимает legacy wallet row без `network_key` только для canonical network зарегистрированного asset.
- Риск: null-network compatibility ослабляет явную привязку destination wallet к сети и не должна оставаться в mainnet settlement path.
- Статус: до mainnet backfill всех существующих wallet rows должен установить явный `network_key`; после проверки данных null-network fallback и его compatibility tests нужно удалить.

### 14) Custody journal пока не включен в production routing
- Факт: Phase 1 добавляет gated append-only journal, postings и rebuildable projections, но invoice routing, payout requests и legacy backfill writes намеренно отсутствуют; все feature gates по умолчанию выключены.
- Риск: `merchant_balances` остается operational source текущих internal credits, а наличие journal schema само по себе не означает production custody accounting.
- Статус: перед включением нужны reviewed legacy migration, treasury collection/liquidity model, network-fee policy, approval controls, production signer и payout reconciliation.
