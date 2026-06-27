# orderbox-wordpress

WordPress/WooCommerce storefront for OrderBox. Runs in Docker, managed by Traefik.

## Architecture

```
Customer browser → Traefik → wp_app (WordPress/WooCommerce)
                                  ↓ webhooks
                          orderbox_api (Node/Fastify)
                                  ↓
                          orderbox-pi (Raspberry Pi kiosk)
```

## Local dev

```bash
docker compose up -d          # uses docker-compose.yml + docker-compose.override.yml automatically
```

- WordPress available at `http://orderbox.test` (add to `/etc/hosts`: `127.0.0.1 orderbox.test`)
- Traefik dashboard at `http://localhost:8080`
- The `mu-plugins/` directory is bind-mounted into the container — changes take effect immediately without rebuild

### Required: shared Docker network

The container must be on the `orderbox_net` network alongside `orderbox_api` and `postgres_db`:

```bash
docker network create orderbox_net   # once, if it doesn't exist
```

## Production

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

- HTTPS via Let's Encrypt (Traefik ACME, stored in `traefik/acme.json`)
- Cert email is set in `docker-compose.prod.yml`
- The `mu-plugins/` bind-mount is **not** present in prod — the mu-plugin is baked into the Docker image

## mu-plugin: `orderbox-local.php`

Loaded automatically by WordPress from `wp-content/mu-plugins/`. Does three things:

### 1. Docker internal HTTP allowlist
```php
add_filter( 'http_request_host_is_external', '__return_true' );
```
WordPress blocks HTTP requests to non-public hostnames by default. This allows `wp_remote_get` to reach `orderbox_api` on the internal Docker network.

### 2. HTTPS spoof for WooCommerce Basic Auth (local only)
```php
if ( $_SERVER['HTTP_HOST'] === 'wp_app' ) { $_SERVER['HTTPS'] = 'on'; }
```
WooCommerce REST API only accepts Basic Auth credentials when `is_ssl()` returns true. In local Docker, traffic arrives over plain HTTP, so we spoof the `HTTPS` server variable. This condition only fires when the request comes from inside Docker (host = `wp_app`), so it is a no-op in production where real HTTPS is in use.

### 3. Pause / resume (checkout block + store notice)

When the Pi operator pauses the restaurant via the OrderBox dashboard, the storefront must:
- Show a notice banner to customers
- Block add-to-cart
- Block checkout

**How it works:**

On every storefront page request, the mu-plugin calls `GET /public/{subdomain}/status` on the OrderBox API. The response is cached in a WP transient for 30 seconds to keep page loads fast. If paused:

- Sets `woocommerce_demo_store = yes` and `woocommerce_demo_store_notice` → customers see a yellow banner at the top of every page (the same mechanism as WooCommerce's built-in "Store Notice" under WooCommerce → Settings → General)
- Hooks `woocommerce_add_to_cart_validation` to return false
- Hooks `woocommerce_checkout_process` to add a WC error notice

**Fail-open:** if the API is unreachable, the store stays open (no false positives).

### Configuration constants

Set these in `wp-config.php` to override the defaults. Both have sensible local dev defaults built in.

| Constant | Default | Description |
|---|---|---|
| `ORDERBOX_API_URL` | `http://orderbox_api:3000` | Base URL of the OrderBox API. In prod, set to your Cloud Run URL (e.g. `https://api.yourdomain.com`) |
| `ORDERBOX_SUBDOMAIN` | `demo` | The tenant subdomain. Must match the `subdomain` column in the OrderBox `tenants` table |

**Example `wp-config.php` additions for production:**
```php
define( 'ORDERBOX_API_URL',   'https://api.yourdomain.com' );
define( 'ORDERBOX_SUBDOMAIN', 'demo' );
```

In local dev these can be omitted — the defaults point to `orderbox_api:3000` on the shared Docker network.

## WooCommerce API keys

The OrderBox API authenticates to WooCommerce using consumer key + secret (Basic Auth over HTTPS).

Generate keys at: **WooCommerce → Settings → Advanced → REST API → Add key**
- User: an admin account
- Permissions: Read/Write

Store them in the OrderBox `tenants` table (`woo_consumer_key`, `woo_consumer_secret`) and ensure `woo_url` points to the correct WordPress base URL:
- Local: `http://wp_app`
- Prod: `https://orderbox.me` (or your domain)
