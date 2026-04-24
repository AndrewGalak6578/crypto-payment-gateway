# MVP Verification Checklist (current `main`)

Цель: проверить текущий функционал без разработки новых фич.
Источник шагов: текущие `routes/*`, `resources/js/*`, `tests/*`, `config/*`, `composer.json` (не `README`).

## 0) Scope Lock
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 0.1 | `git branch --show-current && git rev-parse --short HEAD` | Ветка `main`, фиксирован commit hash | Ветка не `main` или грязный/непонятный контекст |

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
| 2.1 | `composer test:fast` | Feature/Unit для API/Services/Webhooks проходят | `FAILURES!`, `ERRORS!` |
| 2.2 | `php ./vendor/bin/phpunit tests/Feature/Api/AdminPortal/AdminMerchantApiTest.php tests/Feature/Api/AdminPortal/AdminMerchantWalletApiTest.php tests/Feature/Api/InvoiceApiTest.php` | Критичные admin/merchant API проверки зелёные | Падения по CRUD/auth/refresh |
| 2.3 | `php ./vendor/bin/phpunit tests/Unit/Services/InvoiceStatusRefresherTest.php tests/Unit/Services/InvoiceForwarderTest.php tests/Unit/Webhooks/EnqueueInvoiceWebhookTest.php tests/Unit/Webhooks/WebhookDeliverySenderTest.php` | Статусы, forwarding, webhooks проходят | Падения state-machine/доставки webhook |
| 2.4 (optional real RPC) | `RUN_REAL_RPC_TESTS=true COIN_RPC_MODE=real php ./vendor/bin/phpunit tests/Integration/RealRpcSmokeTest.php tests/Integration/RealChains/RealChainForwardingTest.php` | Интеграция с реальными нодами проходит | `Skipped` (флаги/нод нет) или test failures |

## 3) Frontend Verification
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 3.1 | `npm run build` | Сборка Vite успешна, assets в `public/build` | Ошибки сборки/импорта |

## 4) Route/Config Verification
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 4.1 | `php artisan route:list --except-vendor > /tmp/mvp-routes.txt` | Роуты выгружены | Ошибка bootstrap Laravel |
| 4.2 | `rg "api/admin/(dashboard|merchants|merchant-users|invoices|webhook-deliveries|merchant-api-keys)" /tmp/mvp-routes.txt` | Видны ключевые admin endpoints | Нет одной/нескольких admin групп |
| 4.3 | `rg "api/merchant/(dashboard|invoices|wallets|webhook-settings|webhook-deliveries|api-keys)" /tmp/mvp-routes.txt` | Видны ключевые merchant endpoints | Нет одной/нескольких merchant групп |
| 4.4 | `rg "api/v1/invoices|i/\{publicId\}|i/\{publicId\}/status|admin/\{path\?\}|merchant/\{path\?\}" /tmp/mvp-routes.txt` | Видны merchant API v1 + hosted invoice + SPA entrypoints | Пропали публичные/портальные роуты |

## 5) Manual Smoke
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 5.1 Admin create merchant | `/admin/login` (`ADMIN_BOOTSTRAP_EMAIL/PASSWORD`) → `/admin/merchants` → `Create merchant` | Merchant появляется в списке и открывается `/admin/merchants/{id}` | Ошибка формы/merchant не создан |
| 5.2 Admin merchant wallet CRUD | `/admin/merchants/{id}` → блок `Wallets`: `Create wallet` → `Edit` → `Delete` | Сообщения `Wallet created/updated/deleted`, таблица обновляется | 4xx/5xx, запись не меняется |
| 5.3 Admin merchant users | `/admin/merchant-users`: создать пользователя, затем `Save role`, `Disable/Enable` | Пользователь создан, роль и статус меняются | Ошибки валидации/обновления |
| 5.4 Admin invoices/detail/refresh | `/admin/invoices` → открыть invoice detail → `Refresh/Recheck invoice` | Данные инвойса перезагружены без ошибок | Кнопка даёт ошибку, статус не обновляется |
| 5.5 Admin webhook deliveries/retry | `/admin/webhook-deliveries` → detail → `Retry / Redeliver` | Попытка доставки ставится в очередь, `attempts`/status двигаются | Retry не создаёт новую попытку |
| 5.6 Merchant dashboard | Логин merchant user на `/merchant/login` → `/merchant` | Dashboard грузится, checklist/статистика отображаются | 401/403/пустой экран |
| 5.7 Merchant invoice create/detail/refresh | `/merchant/test-invoice` → `Create test invoice` → открыть detail → `Refresh data` | Есть `public_id`, `hosted_url`, detail доступен | Ошибка создания/refresh |
| 5.8 Merchant wallet CRUD | `/merchant/wallets`: `Save wallet` → `Edit` → `Delete` | Успешные сообщения, состояние таблицы корректно | CRUD не применился |
| 5.9 Merchant webhook pages | `/merchant/webhook-settings` сохранить URL/secret; `/merchant/webhook-deliveries` открыть list/details | `Webhook settings saved`, deliveries доступны | Сохранение/чтение webhook не работает |
| 5.10 Hosted invoice page | Открыть `hosted_url` (или `/i/{publicId}`), затем `GET /i/{publicId}/status` | Страница отображает сумму/адрес/статус, status endpoint отвечает | 404/500/неконсистентный статус |

## 6) EVM/Local Verification
| Step | Exact command or action | Expected result | Fail signal |
|---|---|---|---|
| 6.1 Local HD derivation | `php ./vendor/bin/phpunit tests/Unit/Providers/AppServiceProviderEvmDeriverSelectionTest.php tests/Unit/Services/PaymentAddresses/Evm/LocalHdMnemonicEvmDeriverTest.php` | Выбор deriver + локальная HD деривация проходят | Падения выбора deriver/derive path |
| 6.2 Gas station prerequisites | `grep -E '^(EVM_LOCAL_RPC_URL|PAYMENT_EVM_LOCAL_HD_ENABLED|PAYMENT_EVM_LOCAL_GAS_STATION_KEY_REF|PAYMENT_EVM_GAS_TOPUP_ENABLED|PAYMENT_EVM_LOCAL_HD_KEYREF_ANVIL_GAS_STATION_MNEMONIC)=' .env` | Ключи для gas sponsorship заполнены | Пустой `PAYMENT_EVM_LOCAL_GAS_STATION_KEY_REF`/mnemonic |
| 6.3 EVM RPC reachability | `curl -s -X POST "$EVM_LOCAL_RPC_URL" -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":1,"method":"eth_blockNumber","params":[]}'` | Возвращается JSON с `result` | timeout/connection refused/invalid JSON |
| 6.4 ERC-20 gas sponsorship smoke | `php ./vendor/bin/phpunit --filter test_erc20_forward_is_deferred_after_gas_topup_submission tests/Unit/Services/InvoiceForwarderTest.php` | Тест deferred-topup сценария зелёный | Падение логики gas sponsorship |
| 6.5 Local signer smoke | `php ./vendor/bin/phpunit tests/Unit/Services/Evm/Signers/DevRpcAccountEvmTransactionSignerTest.php` | Локальный signer сценарий проходит | Ошибки Anvil impersonation/signing |

---

Примечание: для webhook/invoice state transitions очередь (`queue:listen`) обязательна.
