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

### 3. Synchronous webhook delivery

WooCommerce normally delivers webhooks via WP-Cron, which only fires on page loads. This causes a delay: the order doesn't reach the Pi until the customer navigates away from the thank-you page.

The mu-plugin hooks `woocommerce_checkout_order_created` to fire the webhook synchronously during checkout — the order reaches the API before the thank-you page renders. It also hooks `woocommerce_order_status_changed` to keep WC's own webhook system in sync and handles the COD-specific messaging.

### 4. Pause / resume

On every storefront page request, the mu-plugin calls `GET /public/{subdomain}/status` (cached in a WP transient, 30s TTL). When paused:

- Sets `woocommerce_demo_store = yes` → WooCommerce store notice banner shown to customers
- Hooks `woocommerce_add_to_cart_validation` → blocks add-to-cart
- Hooks `woocommerce_checkout_process` → blocks checkout with a notice

Fail-open: if the API is unreachable, the store stays open.

### 5. Order type → shipping method pre-selection

When a customer selects Collection or Delivery on the order-type landing page, the choice is stored in a cookie. At checkout, the mu-plugin reads the cookie and pre-selects the matching WooCommerce shipping method. When the order type changes, the previous WC session shipping choice is cleared so the new method takes effect cleanly.

### 6. Live order tracking banner (thank-you page)

After checkout, the thank-you page shows a live status banner polling `GET /track/{subdomain}/{woo_order_id}?key={order_key}` every few seconds. The banner updates as the order moves through `NEW → ACCEPTED → PRINTED → COMPLETED`, giving the customer real-time feedback without requiring a login.

### Configuration

| Constant / Env var | Default | Description |
|---|---|---|
| `ORDERBOX_API_URL` | `http://orderbox_api:3000` | OrderBox API base URL. In prod, set to the Cloud Run URL via the Docker env var or `wp-config.php` constant. |
| `ORDERBOX_SUBDOMAIN` | `demo` | Tenant subdomain. Must match `tenants.subdomain` in the API database. |

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
