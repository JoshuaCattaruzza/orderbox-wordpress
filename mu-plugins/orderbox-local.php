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
// These constants can be overridden in wp-config.php for production.
// Local dev defaults point to the orderbox_api container on the shared Docker network.
if ( ! defined( 'ORDERBOX_API_URL' ) )   define( 'ORDERBOX_API_URL',   'http://orderbox_api:3000' );
if ( ! defined( 'ORDERBOX_SUBDOMAIN' ) ) define( 'ORDERBOX_SUBDOMAIN', 'demo' );

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
