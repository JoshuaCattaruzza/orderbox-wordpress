<?php
/**
 * Allows WordPress HTTP requests to reach internal Docker services.
 * Required for WooCommerce webhook delivery to the OrderBox API on the local network.
 */
add_filter( 'http_request_host_is_external', '__return_true' );

/**
 * WooCommerce REST API Basic Auth requires is_ssl() to return true.
 * When the API calls wp_app directly over HTTP within Docker, spoof HTTPS
 * so WooCommerce will accept the credentials.
 */
if ( isset( $_SERVER['HTTP_HOST'] ) && $_SERVER['HTTP_HOST'] === 'wp_app' ) {
	$_SERVER['HTTPS'] = 'on';
}

// Deliver webhooks synchronously so orders reach the OrderBox API immediately
// on checkout — except when the checkout itself is a REST API request
// (WooCommerce Blocks / Store API: Stripe, Apple Pay, Google Pay). WooCommerce
// builds the webhook payload via its own nested rest_do_request() call, which
// lazy-loads the /wc/v3/ namespace through a rest_pre_dispatch hook; firing
// that nested call while already inside the Store API's own REST dispatch
// breaks the lazy-load handshake and 404s with rest_no_route, so the webhook
// body ends up containing that error instead of the order data (confirmed via
// a live checkout test). Classic/COD checkout never runs inside a REST
// dispatch, so it keeps the original instant-delivery behavior; Blocks/Store
// API checkouts fall back to async, which self-triggers within the same
// request via a loopback HTTP call and is near-instant in practice.
add_filter( 'woocommerce_webhook_deliver_async', function ( $async ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true; // let Blocks/Store API checkouts queue async instead of crashing
	}
	return false; // synchronous everywhere else, matching the original intent
} );

// ── Pause / resume ────────────────────────────────────────────────────────────
// Config is read from environment variables, with wp-config.php constants as
// an override and sensible local-dev defaults as the final fallback.
if ( ! defined( 'ORDERBOX_API_URL' ) )   define( 'ORDERBOX_API_URL',   getenv( 'ORDERBOX_API_URL' )   ?: 'http://orderbox_api:3000' );
if ( ! defined( 'ORDERBOX_SUBDOMAIN' ) ) define( 'ORDERBOX_SUBDOMAIN', getenv( 'ORDERBOX_SUBDOMAIN' ) ?: 'demo' );

// ORDERBOX_API_URL above is used for server-side calls and, in production, is
// already a public URL the browser can reach too. In local dev it's a
// Docker-internal hostname the browser can't resolve, so client-side fetches
// (the order-tracking banner below) need a separately reachable URL.
if ( ! defined( 'ORDERBOX_PUBLIC_API_URL' ) ) define( 'ORDERBOX_PUBLIC_API_URL', getenv( 'ORDERBOX_PUBLIC_API_URL' ) ?: ORDERBOX_API_URL );

/**
 * Returns true if the restaurant is currently paused.
 * Result is cached in a WP transient for 30 seconds to avoid hitting the API
 * on every page request.
 */
function orderbox_is_paused(): bool {
	$cached = get_transient( 'orderbox_pause_status' );
	if ( $cached !== false ) {
		return (bool) $cached;
	}

	$response = wp_remote_get(
		ORDERBOX_API_URL . '/public/' . ORDERBOX_SUBDOMAIN . '/status',
		[ 'timeout' => 3 ]
	);

	if ( is_wp_error( $response ) ) {
		// If the API is unreachable, fail open so the store keeps working.
		set_transient( 'orderbox_pause_status', 0, 30 );
		return false;
	}

	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$paused = ! empty( $body['paused'] );

	set_transient( 'orderbox_pause_status', $paused ? 1 : 0, 30 );
	return $paused;
}

/**
 * Show the WooCommerce store notice banner when paused, clear it when not.
 * Runs on every storefront request (transient keeps it cheap).
 */
add_action( 'wp', function () {
	if ( is_admin() ) return;

	if ( orderbox_is_paused() ) {
		update_option( 'woocommerce_demo_store', 'yes' );
		update_option( 'woocommerce_demo_store_notice', 'We are temporarily not accepting orders. Please check back soon.' );
	} else {
		if ( get_option( 'woocommerce_demo_store' ) === 'yes' ) {
			update_option( 'woocommerce_demo_store', 'no' );
		}
	}
} );

/**
 * Block checkout submission when paused.
 */
add_action( 'woocommerce_checkout_process', function () {
	if ( orderbox_is_paused() ) {
		wc_add_notice( 'Sorry, we are not accepting orders right now. Please check back soon.', 'error' );
	}
} );

/**
 * Block add-to-cart when paused so customers cannot queue items.
 */
add_filter( 'woocommerce_add_to_cart_validation', function ( bool $passed ): bool {
	if ( orderbox_is_paused() ) {
		wc_add_notice( 'Sorry, we are not accepting orders right now.', 'error' );
		return false;
	}
	return $passed;
}, 10, 1 );

// ── Order type pre-selection ──────────────────────────────────────────────────
// The order-type page links to /menu?order_type=delivery|collection.
// We persist that choice in a cookie and use it to pre-select the right
// WooCommerce shipping method at checkout so the customer doesn't have to
// choose again.

// Capture ?order_type= from the URL and store it in a cookie.
// Also clear the WC session's cached shipping choice so the filter below
// can re-apply — otherwise WC reuses the previous session value.
add_action( 'wp', function () {
	if ( isset( $_GET['order_type'] ) ) {
		$type = sanitize_key( $_GET['order_type'] );
		if ( in_array( $type, [ 'delivery', 'collection' ], true ) ) {
			setcookie( 'orderbox_order_type', $type, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE['orderbox_order_type'] = $type;
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'chosen_shipping_methods', [] );
			}
		}
	}
} );

// Pre-select the matching shipping method at checkout based on the cookie.
// flat_rate:2 = Delivery, local_pickup:1 = Collection.
add_filter( 'woocommerce_shipping_chosen_method', function ( $default, $rates ) {
	$type = $_COOKIE['orderbox_order_type'] ?? '';
	if ( ! $type ) return $default;

	$prefer = $type === 'delivery' ? 'flat_rate' : 'local_pickup';
	foreach ( $rates as $rate_id => $rate ) {
		if ( $rate->method_id === $prefer ) return $rate_id;
	}
	return $default;
}, 10, 2 );

// Clear the cookie once the order is placed.
add_action( 'woocommerce_thankyou', function () {
	setcookie( 'orderbox_order_type', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	unset( $_COOKIE['orderbox_order_type'] );
} );

// ── Express checkout (Apple/Google Pay) delivery-date defaults ────────────────
// WooCommerce Blocks Store API checkout (used by the Stripe Payment Request
// Button for Apple/Google Pay) never touches the visible checkout form, so the
// "Order Delivery Date for WooCommerce (Lite)" plugin's required fields arrive
// empty and fail validation with "Delivery Date is a required field." Detect
// express checkout via the `express_payment_type` key WooCommerce Blocks adds
// to payment_data, and inject defaults into extensions['order-delivery-date']
// before orddd_lite's validation callback runs.
add_filter( 'rest_request_before_callbacks', function ( $response, $handler, $request ) {
	if ( strpos( $request->get_route(), 'wc/store/v1/checkout' ) === false ) {
		return $response;
	}

	$is_express = false;
	foreach ( (array) $request->get_param( 'payment_data' ) as $item ) {
		if ( ( $item['key'] ?? '' ) === 'express_payment_type' && ! empty( $item['value'] ) ) {
			$is_express = true;
			break;
		}
	}
	if ( ! $is_express ) {
		return $response;
	}

	$extensions = $request->get_param( 'extensions' );
	if ( ! is_array( $extensions ) ) {
		$extensions = [];
	}
	if ( ! isset( $extensions['order-delivery-date'] ) || ! is_array( $extensions['order-delivery-date'] ) ) {
		$extensions['order-delivery-date'] = [];
	}

	// e_deliverydate and h_deliverydate aren't duplicates of the same value —
	// on a real checkout, block.js binds the visible jQuery UI datepicker to
	// #e_deliverydate (display format, e.g. "19 July, 2026", matching the site's
	// orddd_lite_delivery_date_format option) while #h_deliverydate is a hidden
	// field always in the fixed 'dd-mm-y' shape that
	// Orddd_Lite_Common::orddd_lite_get_timestamp() parses via mktime(). Sending
	// the display format for h_deliverydate throws a TypeError there (confirmed
	// via a live checkout crash); sending the numeric format for e_deliverydate
	// works but shows wrong everywhere it's displayed verbatim (e.g. the Pi's
	// receipt printer, which prints the "Delivery Date" order meta as-is).
	if ( empty( $extensions['order-delivery-date']['e_deliverydate'] ) ) {
		$extensions['order-delivery-date']['e_deliverydate'] = date( 'j F, Y' );
	}
	if ( empty( $extensions['order-delivery-date']['h_deliverydate'] ) ) {
		$extensions['order-delivery-date']['h_deliverydate'] = date( 'd-m-Y' );
	}
	if ( empty( $extensions['order-delivery-date']['orddd_lite_time_slot'] ) ) {
		$extensions['order-delivery-date']['orddd_lite_time_slot'] = 'asap';
	}

	$request->set_param( 'extensions', $extensions );

	return $response;
}, 5, 3 );

// ── Order tracking banner ──────────────────────────────────────────────────────
/**
 * Full-page pending overlay while the restaurant hasn't responded yet.
 * Swaps to an inline result banner once the order is confirmed or declined.
 * Polls every 5 seconds until a terminal status is reached.
 */
add_action( 'woocommerce_before_thankyou', function ( int $order_id ) {
	$order     = wc_get_order( $order_id );
	$order_key = $order ? $order->get_order_key() : '';
	$api_url   = rtrim( ORDERBOX_PUBLIC_API_URL, '/' );
	$subdomain = ORDERBOX_SUBDOMAIN;

	?>
	<style>
	#ob-overlay {
		position: fixed;
		inset: 0;
		z-index: 99999;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		background: rgba(255,255,255,0.93);
		gap: 28px;
	}
	#ob-overlay p {
		margin: 0;
		font-size: 18px;
		font-weight: 600;
		color: #333;
		text-align: center;
		line-height: 1.5;
	}
	#ob-overlay small {
		display: block;
		font-size: 13px;
		font-weight: 400;
		color: #888;
		margin-top: 6px;
	}
	@keyframes ob-spin { to { transform: rotate(360deg); } }
	.ob-spinner {
		width: 72px;
		height: 72px;
		border: 7px solid #e8e8e8;
		border-top-color: #1a73e8;
		border-radius: 50%;
		animation: ob-spin 0.85s linear infinite;
		flex-shrink: 0;
	}
	#ob-result {
		display: none;
		margin-bottom: 24px;
		padding: 18px 22px;
		border-radius: 6px;
		border: 2px solid #e0e0e0;
		font-size: 15px;
		line-height: 1.5;
	}
	</style>

	<div id="ob-overlay">
		<div class="ob-spinner"></div>
		<p>
			Waiting for the restaurant to confirm your order&hellip;
			<small>This usually takes less than a minute.</small>
		</p>
	</div>

	<div id="ob-result"></div>

	<script>
	(function () {
		var overlay = document.getElementById('ob-overlay');
		var result  = document.getElementById('ob-result');
		var url     = <?php echo json_encode( $api_url . '/track/' . $subdomain . '/' . $order_id . '?key=' . $order_key ); ?>;
		var timer;
		var resolved = false;

		function resolve(html, bg, border, color) {
			if (resolved) return;
			resolved = true;
			clearInterval(timer);
			overlay.style.display = 'none';
			result.style.display  = 'block';
			result.style.background   = bg;
			result.style.borderColor  = border;
			result.style.color        = color || '#000';
			result.innerHTML = html;
		}

		function poll() {
			fetch(url)
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					if (!data) return;

					if (data.status === 'COMPLETED') {
						resolve('&#10003; Your order is ready!', '#f0faf0', '#4caf50', '#1b5e20');
					} else if (data.status === 'ACCEPTED' || data.status === 'PRINTED') {
						var eta = data.eta_minutes
							? ' Estimated preparation time: <strong>' + data.eta_minutes + ' minutes</strong>.'
							: '';
						resolve('&#10003; Your order has been confirmed!' + eta, '#f0faf0', '#4caf50', '#1b5e20');
					} else if (data.status === 'CANCELLED') {
						var isCod = data.payment_method === 'cod';
						var msg;
						if (isCod) {
							msg = 'Unfortunately your order was declined by the restaurant.';
						} else {
							var amount = data.total_amount
								? ' of &pound;' + parseFloat(data.total_amount).toFixed(2)
								: '';
							msg = 'Unfortunately your order was declined. A refund' + amount + ' has been initiated and will appear within 3&ndash;5 business days.';
						}
						resolve(msg, '#fff5f5', '#e53935', '#7f0000');
					}
				})
				.catch(function () { /* network blip — keep polling */ });
		}

		timer = setInterval(poll, 5000);
		poll();
	})();
	</script>
	<?php
} );
