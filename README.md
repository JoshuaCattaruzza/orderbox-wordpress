# orderbox-wordpress

WordPress/WooCommerce storefront for OrderBox. Runs in Docker, managed by Traefik for TLS termination. Multi-tenant — each restaurant gets its own Docker Compose stack on the same VM.

## Architecture

```
Customer browser → Traefik (TLS) → WordPress/WooCommerce
                                         ↓ synchronous webhook
                                   orderbox-api (Cloud Run)
                                         ↓
                                   orderbox-pi (Raspberry Pi)
```

## Local dev

```bash
docker network create orderbox_net   # once, if it doesn't exist
docker compose up -d
```

- WordPress at `http://orderbox.test` (add `127.0.0.1 orderbox.test` to `/etc/hosts`)
- Traefik dashboard at `http://localhost:8080`
- The `mu-plugins/` directory is bind-mounted — changes take effect immediately without rebuild

## Production

Each tenant has its own env file at `/opt/orderbox-wp-<subdomain>/.env` on the VM. Start a tenant stack:

```bash
docker compose -f docker-compose.yml -f docker-compose.https.yml \
  --env-file /opt/orderbox-wp-<subdomain>/.env \
  -p <subdomain> up -d
```

- HTTPS via Let's Encrypt (Traefik ACME, stored in `traefik/acme.json`)
- Container names follow Docker Compose project naming: `<subdomain>-wordpress-1`, `<subdomain>-db-1`
- `MU_PLUGINS_PATH` in the env file points to `/opt/orderbox-wordpress/mu-plugins` (absolute path on VM)

Use `deploy.sh wp [subdomain]` from `orderbox-terraform` to build, push, and restart in one step.

---

## mu-plugin: `mu-plugins/orderbox.php`

Loaded automatically by WordPress from `wp-content/mu-plugins/`. Not optional — it's required for OrderBox to function.

### 1. Docker internal HTTP allowlist

```php
add_filter( 'http_request_host_is_external', '__return_true' );
```

WordPress blocks HTTP requests to internal hostnames by default. This allows `wp_remote_post` to reach `orderbox_api` on the Docker network and the OrderBox API on Cloud Run.

### 2. HTTPS spoof for WooCommerce Basic Auth

```php
if ( $_SERVER['HTTP_HOST'] === 'wp_app' ) { $_SERVER['HTTPS'] = 'on'; }
```

WooCommerce REST API only accepts Basic Auth over HTTPS. In local Docker, traffic is plain HTTP internally. This condition is a no-op in production where real HTTPS is in place.

### 3. Webhook delivery: synchronous, with a REST-context exception

WooCommerce normally delivers webhooks via WP-Cron/Action Scheduler, which
delays the order reaching the Pi. The mu-plugin forces synchronous delivery
(`woocommerce_webhook_deliver_async` → false) for classic checkouts — **except**
when the checkout itself runs inside a REST dispatch (WooCommerce Blocks /
Store API, i.e. Apple/Google Pay express checkout): synchronous delivery
there corrupts the payload via a nested `rest_do_request` that 404s, so
those fall back to async.

Backstopping the async path, `DISABLE_WP_CRON` is set and a real system cron
on the VM hits `wp-cron.php` every minute (see `orderbox-terraform/startup.sh`),
so Action Scheduler work has a predictable upper bound instead of "whenever a
page load happens". The API side also reconciles missed webhooks every 5
minutes as a final safety net.

### 4. Pause / resume

On every storefront page request, the mu-plugin calls `GET /public/{subdomain}/status` (cached in a WP transient, 30s TTL). When paused:

- Sets `woocommerce_demo_store = yes` → WooCommerce store notice banner shown to customers
- Hooks `woocommerce_add_to_cart_validation` → blocks add-to-cart
- Hooks `woocommerce_checkout_process` → blocks checkout with a notice

Fail-open: if the API is unreachable, the store stays open. The status the
API reports is `pause_active OR pi_offline_auto_paused` — the storefront also
pauses automatically when the restaurant's Pi has been offline for ~90s (and
resumes on its next request), indistinguishable from a staff pause on this side.

### 5. Order type → shipping method pre-selection

When a customer selects Collection or Delivery on the order-type landing page, the choice is stored in a cookie. At checkout, the mu-plugin reads the cookie and pre-selects the matching WooCommerce shipping method. When the order type changes, the previous WC session shipping choice is cleared so the new method takes effect cleanly.

### 6. Live order tracking (thank-you page)

After checkout, a full-page overlay polls
`GET /track/{subdomain}/{woo_order_id}?key={order_key}` (browser-side, so it
uses `ORDERBOX_PUBLIC_API_URL`) until the restaurant accepts or declines,
then swaps to an inline result banner. If no terminal status arrives within
90 seconds (API unreachable, slow restaurant), the overlay drops to a soft
"order received" banner rather than trapping the customer — polling continues
in the background.

### 7. Express checkout (Apple/Google Pay) delivery-date defaults

The Stripe Payment Request Button checks out via the Store API without ever
touching the visible checkout form, so orddd_lite's required delivery-date
fields arrive empty and fail validation. Detected via the
`express_payment_type` key in `payment_data`; defaults are injected before
the plugin's validation runs.

### 8. Delivery minimum order

Per-tenant minimum food total for **delivery** orders (collection exempt),
set via `ORDERBOX_DELIVERY_MINIMUM` (0/unset = disabled). The subtotal used
is post-discount and tax-inclusive. Enforced in three places: an inline
warning + disabled Place-order button on classic checkout, a
`woocommerce_checkout_process` notice, and — critically — a
`woocommerce_store_api_cart_errors` hook so Apple/Google Pay express checkout
is covered too (the classic hooks never fire for it). Unknown shipping state
counts as delivery (fail closed).

### 9. Collection checkout: name + contact only

For collection orders the billing address fields are hidden (JS toggle, live
on method switch) and made optional server-side — only name, phone, email
remain. The shared `orderbox_is_collection()` helper (chosen shipping method,
falling back to the order-type cookie) drives both this and the minimum.
Also renames the checkout shipping section to "Delivery Options".

### 10. Housekeeping

`WP_POST_REVISIONS = 5` (Elementor stores the full page JSON per revision —
unlimited revisions once grew the DB to ~95MB for 11 pages) and Action
Scheduler retention cut to 7 days.

### Configuration

| Constant / Env var | Default | Description |
|---|---|---|
| `ORDERBOX_API_URL` | `http://orderbox_api:3000` | OrderBox API base URL (server-side calls). In prod, set to the Cloud Run URL via the Docker env var or `wp-config.php` constant. |
| `ORDERBOX_PUBLIC_API_URL` | falls back to `ORDERBOX_API_URL` | Browser-reachable API origin for client-side polling (order tracking) — needed in local dev where `ORDERBOX_API_URL` is a Docker-internal hostname. |
| `ORDERBOX_SUBDOMAIN` | `demo` | Tenant subdomain. Must match `tenants.subdomain` in the API database. |
| `ORDERBOX_DELIVERY_MINIMUM` | `0` (disabled) | Minimum post-discount, tax-inclusive food total for delivery orders. Collection always exempt. |

Both can be set as environment variables (via Docker Compose `.env`) or as `wp-config.php` constants — constants take precedence.

---

## WooCommerce configuration

### REST API keys (for orderbox-api → WooCommerce calls)

WooCommerce → Settings → Advanced → REST API → **Add key**
- User: admin
- Permissions: **Read/Write**
- Copy both values immediately (secret is only shown once)

Store in the `tenants` table: `woo_consumer_key`, `woo_consumer_secret`, `woo_url`.

### Webhook (for WooCommerce → orderbox-api calls)

WooCommerce → Settings → Advanced → Webhooks — the webhook URL is:

```
https://orderbox-api-<hash>.a.run.app/webhooks/<subdomain>/woocommerce
```

Set the **Secret** to the same value as `webhook_secret` in the `tenants` table. The API validates the `X-Wc-Webhook-Signature` HMAC on every incoming webhook.

With synchronous delivery enabled via the mu-plugin, the built-in WooCommerce webhook is a fallback — but keep it configured and the secret set.
