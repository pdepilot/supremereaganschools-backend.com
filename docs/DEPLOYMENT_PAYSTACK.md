# Paystack (Phase 8A) — Hostinger deployment

## Scope

This document covers the **online payment foundation only** (`online_payments` + Paystack).
It does **not** cover CBT Result Checker product configuration.

## 1. Pre-flight

On the server (SSH / Hostinger terminal), from the Laravel app root:

```bash
php artisan migrate:status
```

Review pending migrations:

- `2026_09_08_140000_create_online_payment_tables`
- `2026_09_08_150000_add_channel_fields_to_online_payments`

Do **not** run `migrate:fresh` or `db:wipe`.

## 2. Environment (server `.env` only)

Set on Hostinger — never commit real keys:

```env
PAYSTACK_PUBLIC_KEY=pk_test_...   # or pk_live_... in production
PAYSTACK_SECRET_KEY=sk_test_...   # or sk_live_...
PAYSTACK_BASE_URL=https://api.paystack.co
PAYSTACK_CURRENCY=NGN
PAYSTACK_CALLBACK_URL=https://YOUR-DOMAIN/payments/paystack/callback
PAYSTACK_TEST_AMOUNT_KOBO=10000
PAYSTACK_TEST_PAYMENTS_ENABLED=false
```

Rules:

- Do not mix test and live keys.
- Never put `PAYSTACK_SECRET_KEY` in JavaScript, Git, or chat logs.
- Use HTTPS for callback and webhook URLs.

## 3. Migrate

After reviewing migrations:

```bash
php artisan migrate --force
```

## 4. Config cache

If this project normally caches config in production:

```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan about
```

Do not print secret keys to the terminal.

## 5. Paystack dashboard

Configure:

| Setting | Value |
|--------|--------|
| Mode | Test first, then Live |
| Callback URL | `https://YOUR-DOMAIN/payments/paystack/callback` |
| Webhook URL | `https://YOUR-DOMAIN/payments/paystack/webhook` |

Enable channels as required (card, bank transfer, etc.).

## 6. Smoke checks

1. `GET /payments/test` (only when `PAYSTACK_TEST_PAYMENTS_ENABLED=true` or local/testing)
2. Initialize → Paystack checkout → return to callback
3. Confirm `online_payments.status = paid` after server verify / webhook
4. Re-send the same webhook — still one paid row

## 7. Turn off test checkout in production

```env
PAYSTACK_TEST_PAYMENTS_ENABLED=false
```

Then clear/rebuild config cache again.
