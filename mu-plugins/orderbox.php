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

// ── Pause / resume ────────────────────────────────────────────────────────────
// Config is read from environment variables, with wp-config.php constants as
// an override and sensible local-dev defaults as the final fallback.
if ( ! defined( 'ORDERBOX_API_URL' ) )   define( 'ORDERBOX_API_URL',   getenv( 'ORDERBOX_API_URL' )   ?: 'http://orderbox_api:3000' );
if ( ! defined( 'ORDERBOX_SUBDOMAIN' ) ) define( 'ORDERBOX_SUBDOMAIN', getenv( 'ORDERBOX_SUBDOMAIN' ) ?: 'demo' );

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

// ── Order tracking banner ──────────────────────────────────────────────────────
/**
 * Inject a live status banner above the standard WooCommerce thank you page.
 * Polls the OrderBox tracking endpoint every 5 seconds until a terminal status
 * (ACCEPTED or CANCELLED) is reached.
 */
add_action( 'woocommerce_before_thankyou', function ( int $order_id ) {
	$order     = wc_get_order( $order_id );
	$order_key = $order ? $order->get_order_key() : '';
	$api_url   = rtrim( ORDERBOX_API_URL, '/' );
	$subdomain = ORDERBOX_SUBDOMAIN;

	?>
	<div id="orderbox-status-banner" style="margin-bottom:24px;padding:18px 22px;border-radius:6px;border:2px solid #e0e0e0;background:#fafafa;font-size:15px;line-height:1.5;">
		<span id="orderbox-status-text">Waiting for the restaurant to confirm your order&hellip;</span>
	</div>

	<script>
	(function () {
		var banner  = document.getElementById('orderbox-status-banner');
		var text    = document.getElementById('orderbox-status-text');
		var url     = <?php echo json_encode( $api_url . '/track/' . $subdomain . '/' . $order_id . '?key=' . $order_key ); ?>;
		var timer;

		function applyStyle(bg, border, color) {
			banner.style.background  = bg;
			banner.style.borderColor = border;
			banner.style.color       = color || '#000';
		}

		function poll() {
			fetch(url)
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					if (!data) return;

					if (data.status === 'COMPLETED') {
						text.innerHTML = '&#10003; Your order is ready!';
						applyStyle('#f0faf0', '#4caf50', '#1b5e20');
						clearInterval(timer);
					} else if (data.status === 'ACCEPTED' || data.status === 'PRINTED') {
						var eta = data.eta_minutes ? ' Estimated preparation time: <strong>' + data.eta_minutes + ' minutes</strong>.' : '';
						text.innerHTML = '&#10003; Your order has been confirmed!' + eta;
						applyStyle('#f0faf0', '#4caf50', '#1b5e20');
					} else if (data.status === 'CANCELLED') {
						var amount = data.total_amount ? ' of &pound;' + parseFloat(data.total_amount).toFixed(2) : '';
						text.innerHTML = 'Unfortunately your order was declined. A refund' + amount + ' has been initiated and will appear within 3&ndash;5 business days.';
						applyStyle('#fff5f5', '#e53935', '#7f0000');
						clearInterval(timer);
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
