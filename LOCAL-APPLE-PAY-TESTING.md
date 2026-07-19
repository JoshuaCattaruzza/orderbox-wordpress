# Local Apple/Google Pay testing setup

This is a from-scratch runbook (and a prompt you can hand to Claude Code) for
standing up a full local environment — WordPress+Traefik, orderbox-api+Postgres,
the Pi app, real production-like data, and working HTTPS — specifically to test
express checkout (Apple Pay / Google Pay via the Stripe Payment Request Button).

None of this is needed for normal local dev (`docker compose up` in each repo
works fine on its own over plain HTTP). This extra setup exists only because
Apple Pay's domain registration requires HTTPS and a domain shaped like a real
one — plain `http://localhost` checkout doesn't exercise that code path.

If you're pasting this to a fresh Claude Code session as a prompt, paste the
whole file and say "set up the local Apple Pay testing environment following
this guide."

## Prerequisites

- Docker Desktop running
- `gcloud` authenticated (`gcloud auth login`), with access to project `orderbox-487000`
- The three repos checked out as siblings: `orderbox-wordpress`, `orderbox-api`, `orderbox-pi`

## 1. Base stack

```bash
# Auth Docker for the private WordPress base image
gcloud auth configure-docker europe-west2-docker.pkg.dev --quiet

# Bring up all three projects
cd ~/orderbox-terraform && ./orderbox-up.sh
```

Known snags:
- **Arch mismatch**: `orderbox-wordpress-base` is amd64-only. On Apple Silicon,
  prefix with `DOCKER_DEFAULT_PLATFORM=linux/amd64 ./orderbox-up.sh` (uses
  Rosetta emulation, slower but works).
- **Port 5000 conflict**: macOS AirPlay Receiver squats on port 5000, which the
  Pi app needs. Disable it: System Settings → General → AirDrop & Handoff →
  turn off "AirPlay Receiver". Then `docker start orderbox-pi-app-1`.
- **Postgres schema**: the local `init.sql` doesn't include later migrations.
  Run them:
  ```bash
  docker run --rm --network orderbox_net \
    -v ~/orderbox-api/docker/postgres/migrations:/migrations \
    migrate/migrate -path=/migrations \
    -database "postgres://postgres:postgres@db:5432/orderbox?sslmode=disable" up
  ```

## 2. Restore real data (optional but recommended)

The seeded `demo` tenant is empty. To test against real content:

```bash
# Latest backup:
gsutil ls -l gs://orderbox-backups/wordpress/rajmahal/ | tail -5
gsutil cp gs://orderbox-backups/wordpress/rajmahal/rajmahal-<date>.sql /tmp/

# Import into the local WP MySQL container
docker exec -i orderbox-wordpress-db-1 mysql -u root -proot wordpress < /tmp/rajmahal-<date>.sql

# Rewrite URLs (including serialized WooCommerce/plugin data) to whatever
# local domain you're using — see step 3 for picking the domain first.
docker run --rm --network orderbox-wordpress_default --user 33:33 \
  -v wp_demo_data:/var/www/html \
  -e WORDPRESS_DB_HOST=db:3306 -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
  wordpress:cli php -d memory_limit=512M /usr/local/bin/wp search-replace \
  'https://rajmahal.orderbox.me' 'https://<your-local-domain>' --all-tables
```

The DB restore does **not** bring theme/plugin files — the base image only
ships default WP themes and a couple of plugins. To get everything (themes,
premium plugins, uploads) matching production exactly, pull `wp-content`
straight off the VM:

```bash
# On the VM: tar up wp-content (skip cache/mu-plugins/upgrade cruft)
gcloud compute ssh orderbox-wp-vm --zone=europe-west2-b --project=orderbox-487000 -- \
  "sudo docker run --rm -v wp_rajmahal_data:/data -v /tmp:/backup alpine \
    tar czf /backup/wp-content-transfer.tar.gz -C /data wp-content \
    --exclude=wp-content/mu-plugins --exclude=wp-content/cache \
    --exclude='wp-content/upgrade*' --exclude=wp-content/backups-dup-lite"

# Pull it down and extract into the local volume
gcloud compute scp orderbox-wp-vm:/tmp/wp-content-transfer.tar.gz /tmp/ \
  --zone=europe-west2-b --project=orderbox-487000
docker run --rm --user 33:33 -v wp_demo_data:/var/www/html \
  -v /tmp/wp-content-transfer.tar.gz:/tmp/transfer.tar.gz:ro \
  alpine sh -c "cd /var/www/html && rm -rf wp-content/plugins wp-content/themes wp-content/uploads && tar xzf /tmp/transfer.tar.gz"

docker restart orderbox-wordpress-wordpress-1
```

Note: `wordpress:cli` (Alpine, UID 82 for www-data) doesn't match this base
image's UID (33) — always pass `--user 33:33` to wp-cli containers, or file
writes fail with permission errors. Also bump `-d memory_limit=512M`; the
default CLI memory limit is too low for Elementor/WooCommerce to load.

## 3. HTTPS with a domain Stripe will actually accept

Apple Pay needs a secure context, and Stripe's domain-registration API
(`/v1/payment_method_domains`) rejects both reserved TLDs (`.test`, `.local`)
and bare single-label hosts (`localhost`) — confirmed by testing both. It only
checks the domain's *shape*, not real reachability, so a fake-but-normal-looking
domain works fine for getting the button to render (full end-to-end Apple Pay
completion still needs Apple's servers to reach the domain for merchant
validation, which they can't for a local domain — Google Pay doesn't have this
requirement and works fully locally).

Pick a domain, e.g. `orderbox-local-test.com`, and:

```bash
# Add to your Mac's /etc/hosts
echo "127.0.0.1 orderbox-local-test.com" | sudo tee -a /etc/hosts
echo "127.0.0.1 api.orderbox.test" | sudo tee -a /etc/hosts

# Self-signed cert
mkdir -p ~/orderbox-wordpress/certs
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout ~/orderbox-wordpress/certs/orderbox.test.key \
  -out ~/orderbox-wordpress/certs/orderbox.test.crt \
  -days 365 -subj "/CN=orderbox-local-test.com" \
  -addext "subjectAltName=DNS:orderbox-local-test.com,DNS:api.orderbox.test,IP:127.0.0.1"

cat > ~/orderbox-wordpress/certs/dynamic.yml << 'EOF'
tls:
  certificates:
    - certFile: /certs/orderbox.test.crt
      keyFile: /certs/orderbox.test.key
EOF
```

Edit `orderbox-wordpress/docker-compose.override.yml` (don't commit this —
it's local-only) to add TLS to Traefik and the WordPress router:

```yaml
services:
  traefik:
    command:
      - "--providers.file.filename=/etc/traefik/dynamic.yml"   # add
      - "--entrypoints.websecure.address=:443"                  # add
      # ...existing lines...
    volumes:
      - "./certs:/certs:ro"                                     # add
      - "./certs/dynamic.yml:/etc/traefik/dynamic.yml:ro"        # add
    networks:
      traefik_proxy:
        aliases:
          - api.orderbox.test
          - orderbox-local-test.com   # add — needed for WP-Cron/Action
                                       # Scheduler self-loopback to work

  wordpress:
    labels:
      - "traefik.http.routers.wp-${SUBDOMAIN:-demo}.rule=Host(`orderbox-local-test.com`)"  # was orderbox.test
      - "traefik.http.routers.wp-${SUBDOMAIN:-demo}.entrypoints=web,websecure"              # add websecure
      - "traefik.http.routers.wp-${SUBDOMAIN:-demo}.tls=true"                               # add
```

Do the same for `orderbox-api/docker-compose.override.yml`, adding a router so
the API is reachable at `https://api.orderbox.test` (needed because
`wp_http_validate_url()` only allows outbound requests on ports 80/443/8080 —
hitting the API's port 3000 directly gets rejected with "A valid URL was not
provided" when WordPress tries to deliver a webhook to it):

```yaml
services:
  api:
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.api.rule=Host(`api.orderbox.test`)"
      - "traefik.http.routers.api.entrypoints=web,websecure"
      - "traefik.http.routers.api.tls=true"
```

Recreate both: `docker compose up -d --force-recreate traefik wordpress`
(orderbox-wordpress dir) and `docker compose -p orderbox up -d --force-recreate api`
(orderbox-api dir).

WordPress's own SSL verification will reject the self-signed cert for any
outbound request (webhook delivery, WP-Cron self-loopback). Add this to
`mu-plugins/orderbox.php` for local testing only — **do not commit it**:

```php
add_filter( 'http_request_args', function ( $args, $url ) {
	if ( strpos( $url, '://api.orderbox.test' ) !== false || strpos( $url, '://orderbox-local-test.com' ) !== false ) {
		$args['sslverify'] = false;
	}
	return $args;
}, 10, 2 );
```

Update `siteurl`/`home` to the new domain (same wp-cli search-replace pattern
as step 2), rewriting from whatever the DB currently has to
`https://orderbox-local-test.com`.

## 4. Tenant/webhook config

```bash
# Set pi_api_key and webhook_secret for the demo tenant to match your local .env files
docker exec -i postgres_db psql -U postgres -d orderbox -c \
  "UPDATE tenants SET pi_api_key='test', webhook_secret='<generate one>' WHERE subdomain='demo';"
```

- `orderbox-pi/.env`: set `ORDERBOX_API_URL=http://host.docker.internal:3000`,
  `ORDERBOX_PI_API_KEY=test`.
- In WP admin (WooCommerce → Settings → Advanced → Webhooks), add **two**
  webhooks — topic `Order created` and topic `Order updated` — both pointing
  at `https://api.orderbox.test/webhooks/demo/woocommerce`, with the secret
  matching what you set above. Both topics matter: Stripe's deferred-payment
  flow (used by Apple/Google Pay) creates the order as a draft and only fires
  `order.updated` when it confirms, not `order.created`.

## 5. Stripe test-mode config

Confirm in WooCommerce → Settings → Payments → Stripe that test mode is on and
Payment Request Buttons are enabled. The Apple Pay domain registration should
succeed automatically on the next `wp-admin` page load once `siteurl` matches
your chosen domain (check `wp-content/uploads/wc-logs/woocommerce-gateway-stripe-*.log`
for `"Your domain has been registered with Apple Pay!"`). If it's stuck in a
failed state from an earlier attempt, clear it and retry:

```bash
docker exec -u root orderbox-wordpress-wordpress-1 php -d memory_limit=512M -r '
define("WP_USE_THEMES", false);
require "/var/www/html/wp-load.php";
$s = get_option("woocommerce_stripe_settings");
unset($s["apple_pay_verified_domain"], $s["apple_pay_domain_set"]);
update_option("woocommerce_stripe_settings", $s);
$reg = new WC_Stripe_Apple_Pay_Registration();
$reg->register_domain_if_configured();
'
```

## 6. Async delivery / WP-Cron

`mu-plugins/orderbox.php` now sets `DISABLE_WP_CRON` and delivers webhooks
async during REST/Store-API checkouts (see the "Fix express checkout" and
"Disable pseudo-cron" commits). Locally there's no system cron to pick up the
slack, so pending jobs won't run themselves. After a Stripe/Apple Pay test
order, run:

```bash
docker exec -u root orderbox-wordpress-wordpress-1 php -d memory_limit=512M -r '
define("WP_USE_THEMES", false);
require "/var/www/html/wp-load.php";
echo "Processed: " . ActionScheduler_QueueRunner::instance()->run() . "\n";
'
```

## Known limitation

Real Apple Pay completion (tapping the button through to a charged order)
can't fully work locally — Apple's servers must reach the domain over the
public internet to validate the merchant session, and a local/fake domain
can't satisfy that. The setup above gets you far enough to exercise the same
`wc-stripe-is-deferred-intent` code path via **Google Pay** (which has no such
requirement) or via a real payment attempt that fails only at the final
Apple-side validation step — useful for testing everything up to and
including the webhook/delivery-date/order-sync logic. For genuine end-to-end
Apple Pay, tunnel the local stack through something like ngrok and register
that temporary public domain with Stripe instead.

## Tearing down

```bash
cd ~/orderbox-wordpress && docker compose down -v
cd ~/orderbox-api && docker compose -p orderbox down -v
cd ~/orderbox-pi && docker compose down -v
docker network rm orderbox_net
sudo sed -i "" "/orderbox.test$/d;/orderbox-local-test.com$/d" /etc/hosts
rm -rf ~/orderbox-wordpress/certs
git -C ~/orderbox-wordpress checkout -- docker-compose.override.yml
git -C ~/orderbox-api checkout -- docker-compose.override.yml
```
