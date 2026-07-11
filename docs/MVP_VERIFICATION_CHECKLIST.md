# MVP Verification Checklist

Цель: проверить текущий функционал без разработки новых фич.
Источник шагов: текущие `routes/*`, `resources/js/*`, `tests/*`, `config/*`, `composer.json` (не `README`).

## 0) Scope Lock
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 0.1 | `git branch --show-current && git rev-parse --short HEAD && git status --short` | Ветка и commit hash понятны, рабочее дерево ожидаемо чистое | Грязный/непонятный контекст |

## 1) Prerequisites
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 1.1 | `composer install && npm install` | Зависимости установлены без ошибок | Ошибки install/lock/conflict |
| 1.2 | `cp .env.example .env` (если файла нет), затем `php artisan key:generate` | `APP_KEY` создан | Ошибка bootstrap/config |
| 1.3 | `php artisan migrate --force && php artisan db:seed && php artisan db:seed --class=AdminUserSeeder` | Миграции и сиды применены | SQL/seed ошибки |
| 1.4 | Проверить критичные env: `grep -E '^(APP_URL|DB_CONNECTION|QUEUE_CONNECTION|SESSION_DRIVER|COIN_RPC_MODE|FORWARDING_ENABLED|PAY_MONITOR_ENABLED|WEBHOOKS_ENABLED|RUN_REAL_RPC_TESTS|EVM_LOCAL_RPC_URL|PAYMENT_EVM_LOCAL_HD_ENABLED|PAYMENT_EVM_LOCAL_GAS_STATION_KEY_REF|PAYMENT_EVM_GAS_TOPUP_ENABLED)=' .env` | Все ключи присутствуют и осмысленно заполнены | Пустые/отсутствующие ключи |
| 1.5 | Запустить приложение: `php artisan serve` | HTTP сервер поднят | 500 на `/admin`/`/merchant` |
| 1.6 | Запустить очередь в отдельном окне: `php artisan queue:listen --tries=1 --timeout=0` | Worker слушает jobs | Jobs не обрабатываются, webhook/invoice refresh «зависают» |

## 2) Backend Verification (order)
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 2.1 | `composer test:fast` или `./vendor/bin/sail test --testsuite=Feature,Unit` | Feature/Unit для API/Services/Webhooks проходят | `FAILURES!`, `ERRORS!` |
| 2.2 | `./vendor/bin/sail test tests/Feature/Api/AdminPortal/AdminMerchantApiTest.php tests/Feature/Api/AdminPortal/AdminMerchantWalletApiTest.php tests/Feature/Api/InvoiceApiTest.php` | Критичные admin/API проверки зелёные | Падения по CRUD/auth/refresh |
| 2.3 | `./vendor/bin/sail test tests/Feature/Api/MerchantPortal/MerchantUserManagementApiTest.php tests/Feature/Api/MerchantPortal/MerchantSettingsApiTest.php tests/Feature/Api/MerchantPortal/MerchantRegistrationApiTest.php` | Merchant v2 users/settings/auth проверки зелёные | Падения team/settings/audit auth behavior |
| 2.4 | `./vendor/bin/sail test tests/Unit/Services/InvoiceStatusRefresherTest.php tests/Unit/Services/InvoiceForwarderTest.php tests/Unit/Webhooks/EnqueueInvoiceWebhookTest.php tests/Unit/Webhooks/WebhookDeliverySenderTest.php` | Статусы, forwarding, webhooks проходят | Падения state-machine/доставки webhook |
| 2.5 (optional real RPC) | `RUN_REAL_RPC_TESTS=true COIN_RPC_MODE=real ./vendor/bin/sail test tests/Integration/RealRpcSmokeTest.php tests/Integration/RealChains/RealChainForwardingTest.php` | Интеграция с реальными нодами проходит | `Skipped` (флаги/нод нет) или test failures |

## 3) Frontend Verification
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 3.1 | `npm run build` | Сборка Vite успешна, assets в `public/build` | Ошибки сборки/импорта |

## 4) Route/Config Verification
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 4.1 | `php artisan route:list --except-vendor > /tmp/mvp-routes.txt` | Роуты выгружены | Ошибка bootstrap Laravel |
| 4.2 | `rg "api/admin/(dashboard|merchants|merchant-users|invoices|webhook-deliveries|merchant-api-keys)" /tmp/mvp-routes.txt` | Видны ключевые admin endpoints | Нет одной/нескольких admin групп |
| 4.3 | `rg "api/merchant/(dashboard|settings|invoices|wallets|balances|settlement-entries|webhook-settings|webhook-deliveries|api-keys|merchant-users)" /tmp/mvp-routes.txt` | Видны ключевые Merchant Portal v2 endpoints | Нет одной/нескольких merchant групп |
| 4.4 | `rg "api/v1/invoices|i/\{publicId\}|i/\{publicId\}/status|admin/\{path\?\}|merchant/\{path\?\}" /tmp/mvp-routes.txt` | Видны merchant API v1 + hosted invoice + SPA entrypoints | Пропали публичные/портальные роуты |

## 5) Manual Smoke
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 5.1 Admin create merchant | `/admin/login` (`ADMIN_BOOTSTRAP_EMAIL/PASSWORD`) → `/admin/merchants` → `Create merchant` | Merchant появляется в списке и открывается `/admin/merchants/{id}` | Ошибка формы/merchant не создан |
| 5.2 Admin merchant wallet CRUD | `/admin/merchants/{id}` → блок `Wallets`: `Create wallet` → `Edit` → `Delete` | Сообщения `Wallet created/updated/deleted`, таблица обновляется | 4xx/5xx, запись не меняется |
| 5.3 Admin merchant users | `/admin/merchant-users`: создать пользователя, затем `Save role`, `Disable/Enable` | Пользователь создан, роль и статус меняются | Ошибки валидации/обновления |
| 5.4 Admin invoices/detail/refresh | `/admin/invoices` → открыть invoice detail → `Refresh/Recheck invoice` | Данные инвойса перезагружены без ошибок | Кнопка даёт ошибку, статус не обновляется |
| 5.5 Admin webhook deliveries/retry | `/admin/webhook-deliveries` → detail → `Retry / Redeliver` | Попытка доставки ставится в очередь, `attempts`/status двигаются | Retry не создаёт новую попытку |
| 5.6 Merchant dashboard v2 | Логин merchant user на `/merchant/login` → `/merchant/dashboard` | Dashboard грузится, monthly metrics/recent payments/setup health отображаются | 401/403/пустой экран |
| 5.7 Merchant settings / checkout defaults | `/merchant/settings`: изменить display name, brand color, allowed assets, redirects, partial payment policy, min/max amount | Settings сохраняются, preview обновляется, toast виден | 4xx/5xx, данные не применились |
| 5.8 Merchant payment create/detail/refresh | `/merchant/payments/new` → создать payment link → открыть `/merchant/payments` и detail/drawer → `Refresh` | Есть `public_id`, `hosted_url`, detail доступен, filters/page state не сбрасываются | Ошибка создания/refresh/detail |
| 5.9 Merchant payments desktop/mobile | `/merchant/payments`: проверить filters, status chips, page size, selected drawer, mobile cards/detail route | Desktop и mobile flows работают без layout jumps | Фильтры считают неверно, drawer/route ломается |
| 5.10 Merchant settlements | `/merchant/settlements`: balances, wallet estimate, destination wallets create/edit/delete, ledger txid link to invoice | Wallet rows не перекрываются, ledger entries/detail links работают | CRUD/ledger/detail link не работает |
| 5.11 Merchant developers | `/merchant/developers`: API key create/revoke, webhook settings save, send test signal, inspect payload, retry delivery | Toasts видны, inspector открывается под delivery, retry/test работает | Кнопки без feedback, inspector ломает layout |
| 5.12 Merchant team / dossier | `/merchant/team`: create/update/disable user, open `Dossier` | Team mobile/desktop UI работает, dossier показывает stats/activity/role/facts, filters sticky | Activity пустая после действий, route/API 500 |
| 5.13 Hosted checkout page | Открыть `hosted_url` (или `/i/{publicId}`), затем `GET /i/{publicId}/status` | Page shows choose-asset or payment instructions; partial/confirming/paid/expired states behave safely | 404/500/expired still allows sending funds |

## 6) Settlement Backfill Smoke
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 5.1.1 | `./vendor/bin/sail artisan settlements:backfill-ledger --dry-run` | Shows planned/skipped ledger entries without writing | Command fails or output is unclear |
| 5.1.2 | `./vendor/bin/sail artisan settlements:backfill-ledger` on disposable/local DB | Creates missing settlement entries, repeated run skips duplicates | Duplicate ledger rows or unexpected amounts |

## 7) EVM/Local Verification
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 6.1 Local HD derivation | `php ./vendor/bin/phpunit tests/Unit/Providers/AppServiceProviderEvmDeriverSelectionTest.php tests/Unit/Services/PaymentAddresses/Evm/LocalHdMnemonicEvmDeriverTest.php` | Выбор deriver + локальная HD деривация проходят | Падения выбора deriver/derive path |
| 6.2 Gas station prerequisites | `grep -E '^(EVM_LOCAL_RPC_URL|PAYMENT_EVM_LOCAL_HD_ENABLED|PAYMENT_EVM_LOCAL_GAS_STATION_KEY_REF|PAYMENT_EVM_GAS_TOPUP_ENABLED|PAYMENT_EVM_LOCAL_HD_KEYREF_ANVIL_GAS_STATION_MNEMONIC)=' .env` | Ключи для gas sponsorship заполнены | Пустой `PAYMENT_EVM_LOCAL_GAS_STATION_KEY_REF`/mnemonic |
| 6.3 EVM RPC reachability | `curl -s -X POST "$EVM_LOCAL_RPC_URL" -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":1,"method":"eth_blockNumber","params":[]}'` | Возвращается JSON с `result` | timeout/connection refused/invalid JSON |
| 6.4 ERC-20 gas sponsorship smoke | `php ./vendor/bin/phpunit --filter test_erc20_forward_is_deferred_after_gas_topup_submission tests/Unit/Services/InvoiceForwarderTest.php` | Тест deferred-topup сценария зелёный | Падение логики gas sponsorship |
| 6.5 Local signer smoke | `php ./vendor/bin/phpunit tests/Unit/Services/Evm/Signers/DevRpcAccountEvmTransactionSignerTest.php` | Локальный signer сценарий проходит | Ошибки Anvil impersonation/signing |

---

Примечание: для webhook/invoice state transitions очередь (`queue:listen`) обязательна.
