# Settlane QA / UX / Frontend Audit

Дата проверки: 2026-07-08  
Production URL: https://settlane.tech  
Пользователь: mail@gsht.com  
Артефакты: `codex-artifacts/*.png`, `settlane-audit-raw.json`, `settlane-extra-checks.json`

## 1. Generated checkout links use `http://` on HTTPS site

- Страница/раздел: Merchant -> Create payment link, Payments API.
- Что делал: залогинился, создал payment link.
- Что произошло: UI и API показали `hosted_url` вида `http://settlane.tech/i/i4ksies4celqqq9o`; pagination API также отдает `http://settlane.tech/...`.
- Что должно было произойти: все публичные payment links и API links на production должны быть `https://`.
- Серьезность: high.
- Тип: bug / logic / security.
- Шаги воспроизведения: Login -> `/merchant/payments/new` -> Create payment link -> посмотреть Hosted link.
- Рекомендация: настроить `APP_URL=https://settlane.tech`, trusted proxies / forwarded headers за Cloudflare, либо принудительно генерировать HTTPS URL для production. Кодовая точка: `app/Http/Controllers/Api/MerchantPortal/InvoiceController.php:303`.
- Скриншот: `codex-artifacts/payment-created.png`.

## 2. Team page polls users every 500 ms

- Страница/раздел: Merchant -> Team.
- Что делал: анализировал страницу и код.
- Что произошло: `setInterval(() => loadUsers({ silent: true }), 500)` делает запросы к users endpoint два раза в секунду.
- Что должно было произойти: обновление по действию пользователя, после мутаций, либо polling с разумным интервалом и visibility pause.
- Серьезность: high.
- Тип: performance / logic.
- Шаги воспроизведения: открыть Team и смотреть network; запросы будут повторяться постоянно.
- Рекомендация: убрать постоянный polling или поднять интервал до 30-60 сек, останавливать при hidden tab, обновлять после create/update/delete. Код: `resources/js/merchant-v2/pages/team/TeamPage.vue:481-483`.
- Скриншот: не нужен; подтверждено кодом.

## 3. Team desktop layout clips controls

- Страница/раздел: Merchant -> Team, desktop 1440.
- Что делал: открыл Team.
- Что произошло: правая колонка Add team member визуально пересекается с directory area; `Clear` и `Disable` выглядят обрезанными.
- Что должно было произойти: фильтры, список и правая колонка должны занимать непересекающиеся grid tracks.
- Серьезность: medium.
- Тип: UI / adaptive.
- Шаги воспроизведения: Login -> `/merchant/team` на 1440px.
- Рекомендация: пересчитать grid: `.team-workspace` и `.team-user-card`; не держать `minmax(300px, 390px)` actions внутри колонки, которая уже конкурирует с sticky sidebar.
- Скриншот: `codex-artifacts/merchant-team-1440.png`.

## 4. Mobile bottom nav overlaps page content

- Страница/раздел: Dashboard, Team, Settings, Create payment на mobile-like viewport.
- Что делал: открыл страницы на узкой ширине.
- Что произошло: нижняя навигация перекрывает нижний контент; на Team/Settings видны элементы, уходящие под nav.
- Что должно было произойти: последний контент должен оставаться полностью доступным над nav с учетом safe area.
- Серьезность: medium.
- Тип: adaptive / UI.
- Шаги воспроизведения: открыть `/merchant/settings` или `/merchant/team` на mobile viewport и прокрутить.
- Рекомендация: добавить общий bottom spacer/padding с `calc(nav height + env(safe-area-inset-bottom) + page gap)`, не только `92px`; проверить страницы с fixed docks.
- Скриншоты: `merchant-settings-390.png`, `merchant-team-390.png`, `merchant-dashboard-390.png`.

## 5. Create payment has competing fixed controls on mobile

- Страница/раздел: Merchant -> Create payment, mobile.
- Что делал: открыл форму создания платежа.
- Что произошло: есть global bottom nav и отдельный `create-mobile-dock`; они занимают нижнюю часть экрана и сжимают форму. Asset picker частично оказывается под fixed panel.
- Что должно было произойти: один понятный нижний action area без перекрытия контента.
- Серьезность: medium.
- Тип: UX / adaptive.
- Шаги воспроизведения: Login -> `/merchant/payments/new` на узкой ширине.
- Рекомендация: либо интегрировать primary action в общий bottom area, либо скрывать global nav на форме; добавить page padding под оба fixed элемента. Код: `resources/js/merchant-v2/pages/payments/CreatePaymentPage.vue:811-824`, `resources/js/merchant-v2/styles/app.css:406-425`.
- Скриншот: `codex-artifacts/merchant-payments-new-390.png`.

## 6. Validation feedback is inconsistent and partly invisible

- Страница/раздел: Register Merchant, Create payment.
- Что делал: отправлял регистрацию с invalid email/short password/mismatched confirmation; отправлял payment с `Amount USD = 0` и malformed JSON.
- Что произошло: browser-native validation stopped submit; unified inline errors were not shown. On payment form the top-level error did not appear in the captured state because native min validation blocked submit first.
- Что должно было произойти: consistent inline field errors near each field, including email, password length/confirmation, amount min, JSON parse.
- Серьезность: medium.
- Тип: validation / UX.
- Шаги воспроизведения: `/merchant/register` -> enter invalid email + mismatched passwords -> submit. `/merchant/payments/new` -> amount 0 -> submit.
- Рекомендация: use `novalidate` and render controlled field-level validation, or mirror native validity into visible Vue errors before submit.
- Скриншоты: `merchant-register-invalid.png`, `payment-invalid-metadata.png`.

## 7. Repeated payment creation is too easy

- Страница/раздел: Merchant -> Create payment.
- Что делал: создал payment link and inspected form/API rules.
- Что произошло: после success форма остается заполненной, submit остается активным; `external_id` не уникален в `CreateInvoiceRequest`.
- Что должно было произойти: user should get a clear next step, or repeated submit should be idempotent / require confirmation / reset external ID.
- Серьезность: medium.
- Тип: logic / UX.
- Шаги воспроизведения: create a payment link, then press Create payment link again.
- Рекомендация: after success disable repeated submit until form changes, reset form, or enforce idempotency/unique external_id per merchant.
- Скриншот: `codex-artifacts/payment-created.png`.

## 8. Developer health says endpoint is ready while deliveries are failing

- Страница/раздел: Merchant -> Developers.
- Что делал: открыл Developers.
- Что произошло: Webhook endpoint shows `Ready`, Send test is enabled, but Delivery health shows `20 failed` and latest result failed.
- Что должно было произойти: failed delivery state should downgrade integration status or show a stronger warning before sending more tests.
- Серьезность: medium.
- Тип: UX / logic.
- Шаги воспроизведения: Login -> `/merchant/developers`.
- Рекомендация: split “configured” from “healthy”; use warning/failed status when recent failures exist, show clear remediation CTA.
- Скриншот: `codex-artifacts/merchant-developers-1440.png`.

## 9. Mobile bottom nav “More” is misleading

- Страница/раздел: Merchant mobile navigation.
- Что делал: inspected mobile nav.
- Что произошло: bottom nav item `More` routes directly to Developers, not a menu that exposes Team/Settings/Admin-like secondary pages.
- Что должно было произойти: `More` should open a menu/sheet or be labeled `Developers`.
- Серьезность: low.
- Тип: UX / content.
- Шаги воспроизведения: mobile viewport -> tap More.
- Рекомендация: rename to Developers or implement a More sheet with Developers, Team, Settings, Sign out.
- Скриншот: any merchant mobile screenshot, e.g. `merchant-dashboard-390.png`.

## 10. Public production page is `noindex,nofollow`

- Страница/раздел: Home page.
- Что делал: inspected production HTML.
- Что произошло: `<meta name="robots" content="noindex,nofollow">`.
- Что должно было произойти: if this is intended as a public marketing/demo site, it should be indexable; if private demo, this is acceptable.
- Серьезность: low.
- Тип: content / SEO.
- Шаги воспроизведения: `curl -L https://settlane.tech`.
- Рекомендация: decide intentionally per environment; avoid shipping noindex on public marketing production.
- Скриншот: not needed.

## Summary

### Critical / Highest Impact

- No critical blocker found.
- Highest impact: HTTP checkout links on HTTPS production, Team 500 ms polling, mobile/fixed navigation overlap.

### Quick Wins

- Fix HTTPS URL generation / trusted proxy config.
- Remove or slow Team polling.
- Add safe bottom padding for mobile and resolve Create payment fixed dock conflict.
- Improve validation UI for auth/payment forms.
- Rename `More` or implement actual More menu.

### Looks Unprofessional

- Cropped controls on Team desktop.
- Content hidden behind bottom nav on mobile.
- `http://` checkout links in a payment product.
- “Ready” webhook status next to 20 failed deliveries.
- Native browser validation mixed with custom portal UI.

### Broken / Risky Scenarios

- Copy/open generated hosted checkout link gives non-HTTPS URL.
- Team page can produce excessive network traffic.
- Mobile users can miss content/actions covered by fixed nav.
- Repeated payment creation can create duplicate operational records.

### Working Scenarios

- Home, Architecture, Merchant and Admin entry pages load.
- Protected merchant routes redirect to login when unauthenticated.
- Valid merchant login works.
- Invalid merchant login shows `Invalid credentials.`
- Authenticated merchant API endpoints checked returned 200.
- Payment link creation succeeds.

### Recheck After Fixes

- Generate payment links and verify HTTPS in UI/API.
- Watch Network on Team for polling behavior.
- Re-run mobile screenshots at 390/768/1024/1440.
- Re-test validation for register/login/create payment/settings/team.
- Re-test create payment repeated submit/idempotency.

### Recommended Code Changes, Not Applied

- Add trusted proxy / HTTPS URL configuration for Laravel behind Cloudflare; verify `route()` output.
- Adjust `InvoiceController::hostedUrl()` only if environment config cannot guarantee HTTPS.
- Remove or throttle Team auto-refresh in `TeamPage.vue`.
- Rework Team desktop grid sizing.
- Add mobile bottom safe-area spacing and resolve Create payment fixed dock vs global nav.
- Implement controlled field-level validation for auth and payment forms.
