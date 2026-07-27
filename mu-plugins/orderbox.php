<?php
/**
 * WordPress's default pseudo-cron only runs when a page load happens to
 * trigger it, and Action Scheduler's own instant self-dispatch (a separate,
 * non-blocking fire-and-forget request) isn't firing reliably in this
 * environment — confirmed via Action Scheduler's own logs showing webhook
 * deliveries completing "via WP Cron" up to ~90s after being scheduled,
 * instead of near-instantly. A real system cron (crontab entry hitting
 * wp-cron.php on an interval) replaces it with a predictable upper bound on
 * delay instead of "whenever someone happens to load a page."
 */
if ( ! defined( 'DISABLE_WP_CRON' ) ) define( 'DISABLE_WP_CRON', true );

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

// Cap revisions at 5 per post. Elementor stores the full page JSON in every
// revision (content + _elementor_data meta), and unlimited revisions grew two
// tables to ~95MB for an 11-page site. 5 undo states is plenty.
if ( ! defined( 'WP_POST_REVISIONS' ) ) define( 'WP_POST_REVISIONS', 5 );

// Keep completed/cancelled/failed Action Scheduler rows for 7 days instead of
// the default 31 — with async webhook delivery every order generates several
// actions (each with ~3 log rows), and a month of retention bloated the DB to
// the point of slowing every AS query and tripling the nightly dumps.
add_filter( 'action_scheduler_retention_period', function () {
	return 7 * DAY_IN_SECONDS;
} );

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
			// httponly is deliberately FALSE: the checkout script reads this to
			// decide whether to show the address fields. It holds nothing but
			// "delivery" or "collection", so there is nothing to protect, and
			// leaving it httponly silently broke the collection checkout —
			// the script could never read it, so the fields never hid.
			setcookie( 'orderbox_order_type', $type, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
			$_COOKIE['orderbox_order_type'] = $type;
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'chosen_shipping_methods', [] );
			}
		}
	}
} );

// Pre-select the matching shipping method at checkout based on the cookie.
// Keyed on local_pickup — collection is the local_pickup rate, delivery is
// whatever else is offered. Never hardcode the delivery method id: this store
// delivers via `city_zip_based_shipping_method`, not flat_rate, and an earlier
// version of this filter looked for a flat_rate that does not exist, so
// delivery was never pre-selected and customers fell through to whatever
// WooCommerce defaulted to. Same rule as the API's delivery_type
// classification, so the two can't disagree.
add_filter( 'woocommerce_shipping_chosen_method', function ( $default, $rates ) {
	$type = $_COOKIE['orderbox_order_type'] ?? '';
	if ( ! $type ) return $default;

	$want_pickup = ( $type === 'collection' );
	foreach ( $rates as $rate_id => $rate ) {
		$is_pickup = strpos( $rate->method_id, 'local_pickup' ) !== false;
		if ( $is_pickup === $want_pickup ) return $rate_id;
	}
	return $default;
}, 10, 2 );

// Clear the cookie once the order is placed.
add_action( 'woocommerce_thankyou', function () {
	setcookie( 'orderbox_order_type', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
	unset( $_COOKIE['orderbox_order_type'] );
} );

// ── Checkout shipping label ───────────────────────────────────────────────────
// The checkout's shipping section is titled "Shipping" by default — rename it,
// since the choice it presents is Delivery vs Collection.
add_filter( 'woocommerce_shipping_package_name', function () {
	return 'Delivery Options';
}, 20 );

// ── Delivery minimum order ────────────────────────────────────────────────────
// Per-tenant minimum food total for delivery orders (collection is exempt),
// set via ORDERBOX_DELIVERY_MINIMUM in the tenant's .env. 0/unset disables.
if ( ! defined( 'ORDERBOX_DELIVERY_MINIMUM' ) ) define( 'ORDERBOX_DELIVERY_MINIMUM', (float) ( getenv( 'ORDERBOX_DELIVERY_MINIMUM' ) ?: 0 ) );

/**
 * Post-discount, tax-inclusive food total — what the customer actually pays
 * for the food itself, excluding delivery fees. (The displayed pre-discount
 * subtotal would let a couponed £15 basket pass a £20 minimum.)
 */
function orderbox_food_total(): float {
	return (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
}

/**
 * True when the customer's order is collection (local_pickup). The chosen
 * shipping method in the session is authoritative; before one exists (first
 * checkout render) the order-type cookie is used. Anything unknown returns
 * false — callers treat that as delivery, the conservative default for both
 * the minimum (enforce it) and the trimmed checkout form (show all fields).
 */
function orderbox_is_collection(): bool {
	if ( ! function_exists( 'WC' ) ) return false;
	$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : [];
	$method = (string) ( $chosen[0] ?? '' );
	if ( $method !== '' ) {
		return strpos( $method, 'local_pickup' ) !== false;
	}
	return ( $_COOKIE['orderbox_order_type'] ?? '' ) === 'collection';
}

/**
 * True when the cart is a delivery order below the tenant's minimum.
 * Collection is exempt; an unknown state counts as delivery — fail closed:
 * the customer can always switch to collection, but a session hiccup must
 * not waive the minimum.
 */
function orderbox_delivery_below_minimum(): bool {
	if ( ORDERBOX_DELIVERY_MINIMUM <= 0 ) return false;
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) return false;
	if ( ! WC()->cart->needs_shipping() ) return false;
	if ( orderbox_is_collection() ) return false;

	return orderbox_food_total() < ORDERBOX_DELIVERY_MINIMUM;
}

function orderbox_delivery_minimum_message( bool $html = true ): string {
	$remaining = max( 0, ORDERBOX_DELIVERY_MINIMUM - orderbox_food_total() );
	$msg = sprintf(
		'The minimum order for delivery is %1$s. Please add another %2$s to your basket, or switch to collection.',
		wc_price( ORDERBOX_DELIVERY_MINIMUM ),
		wc_price( $remaining )
	);
	return $html ? $msg : html_entity_decode( wp_strip_all_tags( $msg ), ENT_QUOTES );
}

// Inline warning directly under the Delivery/Collection choice, rendered
// inside the order-review table — one of the fragments WooCommerce replaces
// on every update_checkout, so it appears/disappears live as the customer
// switches method. (Do NOT use woocommerce_review_order_before_payment for
// this: payment.php only fires that hook on the initial page render and
// skips it in ajax context, so a warning rendered at page load would sit
// there stale after the customer switched to Collection.)
add_action( 'woocommerce_review_order_after_shipping', function () {
	if ( ! orderbox_delivery_below_minimum() ) return;
	echo '<tr class="orderbox-delivery-minimum"><td colspan="2">'
		. '<div class="woocommerce-error" role="alert" style="margin:0;">'
		. orderbox_delivery_minimum_message() . '</div></td></tr>';
} );

// Replace the Place order button while below the minimum.
add_filter( 'woocommerce_order_button_html', function ( $button_html ) {
	if ( ! orderbox_delivery_below_minimum() ) return $button_html;
	return '<button type="button" class="button alt" disabled="disabled" aria-disabled="true"'
		. ' style="opacity:0.55; cursor:not-allowed; width:100%;">'
		. sprintf( 'Minimum %s required for delivery', wp_strip_all_tags( wc_price( ORDERBOX_DELIVERY_MINIMUM ) ) )
		. '</button>';
} );

// Server-side enforcement — classic checkout.
add_action( 'woocommerce_checkout_process', function () {
	if ( orderbox_delivery_below_minimum() ) {
		wc_add_notice( orderbox_delivery_minimum_message(), 'error' );
	}
} );

// Server-side enforcement — Store API checkout. Apple/Google Pay express
// checkout never runs woocommerce_checkout_process, so without this hook the
// minimum is unenforced for exactly those orders. The error surfaces inside
// the payment sheet.
add_action( 'woocommerce_store_api_cart_errors', function ( $errors ) {
	if ( orderbox_delivery_below_minimum() ) {
		$errors->add( 'orderbox_delivery_minimum', orderbox_delivery_minimum_message( false ) );
	}
}, 10, 1 );

// ── Delivery is always offered ────────────────────────────────────────────────
// The delivery rate (city_zip_based_shipping_method) only appears once the
// customer has typed a town we cover — it matches on CITY, not postcode. That
// meant Delivery simply did not exist as a choice for anyone who hadn't given
// an address yet, so a customer who picked Collection could never switch to it:
// no Delivery option to click, and the address fields hidden, so no way to
// enter the town that would create one.
//
// Both options must always be selectable. When the plugin isn't offering a real
// delivery rate we add a stand-in, so the customer can always choose Delivery,
// reveal the address fields, and type their town. The moment the town matches,
// the plugin's real rate appears and this stand-in disappears. Checking out on
// the stand-in is blocked below — it means we don't deliver there (or the town
// is still blank), so it must never become a real order.
const ORDERBOX_PENDING_DELIVERY = 'orderbox_delivery_pending';

function orderbox_has_real_delivery_rate( array $rates ): bool {
	foreach ( $rates as $rate ) {
		$id = $rate->get_method_id();
		if ( ORDERBOX_PENDING_DELIVERY !== $id && strpos( $id, 'local_pickup' ) === false ) {
			return true;
		}
	}
	return false;
}

add_filter( 'woocommerce_package_rates', function ( $rates ) {
	if ( orderbox_has_real_delivery_rate( (array) $rates ) ) return $rates;
	$rates[ ORDERBOX_PENDING_DELIVERY ] = new WC_Shipping_Rate(
		ORDERBOX_PENDING_DELIVERY, 'Delivery', 0, [], ORDERBOX_PENDING_DELIVERY
	);
	return $rates;
}, 20 );

// Without this the stand-in renders as "Delivery: Free!", which is a promise we
// are not making. Show what it actually is: a price we can't work out yet.
add_filter( 'woocommerce_cart_shipping_method_full_label', function ( $label, $method ) {
	if ( ORDERBOX_PENDING_DELIVERY === $method->get_method_id() ) {
		return 'Delivery <small>(enter your town to see the price)</small>';
	}
	return $label;
}, 10, 2 );

/**
 * The customer has Delivery selected but we have no real rate for them —
 * either the town is still blank, or it's outside the delivery area.
 * Returns the reason to show them, or '' when there is nothing wrong.
 */
function orderbox_pending_delivery_problem(): string {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) return '';

	$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : [];
	if ( ! in_array( ORDERBOX_PENDING_DELIVERY, $chosen, true ) ) return '';

	$city = WC()->customer
		? trim( WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city() )
		: '';

	return '' === $city
		? 'Please enter your town so we can check whether we deliver to you — or choose Collection.'
		: sprintf( "Sorry, we don't deliver to %s. Please choose Collection, or change the address.", $city );
}

// Block the classic checkout.
add_action( 'woocommerce_checkout_process', function () {
	$problem = orderbox_pending_delivery_problem();
	if ( '' !== $problem ) wc_add_notice( $problem, 'error' );
} );

// Block Apple/Google Pay too — express checkout never runs the hook above, so
// without this an out-of-area delivery could be paid for with no shipping cost.
add_action( 'woocommerce_store_api_cart_errors', function ( $errors ) {
	$problem = orderbox_pending_delivery_problem();
	if ( '' !== $problem ) $errors->add( 'orderbox_delivery_area', $problem );
}, 10, 1 );

// Tell the customer, inline in the order summary, why Delivery isn't costed
// yet — either their town is blank or we don't cover it. Rendered inside the
// review table because that is one of the fragments WooCommerce re-renders on
// every checkout update, so it tracks what they type live.
add_action( 'woocommerce_review_order_after_shipping', function () {
	$problem = orderbox_pending_delivery_problem();
	if ( '' === $problem ) return;
	echo '<tr class="orderbox-no-delivery"><td colspan="2">'
		. '<div class="woocommerce-info" role="status" style="margin:0;">'
		. esc_html( $problem )
		. '</div></td></tr>';
} );

// ── Collection checkout: name + contact only ──────────────────────────────────
// A collection order needs no address, so the billing address fields are
// hidden and made optional — only name, phone and email remain. (WooCommerce
// already suppresses the separate shipping-address form for local_pickup.)

// The billing_* field wrappers hidden for collection. Kept in one place so
// the PHP filter and the JS toggle below can never disagree.
// Every address field, hidden and made optional together for collection —
// a collection customer should not be asked for an address at all.
//
// This can safely include the town and postcode now that a Delivery option is
// always offered: the customer can always click Delivery to bring these fields
// back. When Delivery only appeared once a town was known, hiding them here
// left no way to enter one, which trapped customers on Collection.
const ORDERBOX_COLLECTION_HIDDEN_FIELDS = [
	'billing_company',
	'billing_country',
	'billing_address_1',
	'billing_address_2',
	'billing_state',
	'billing_city',
	'billing_postcode',
];

// Server side: drop the required flag when the order is collection. This
// filter runs again during process_checkout with the customer's final
// shipping choice in the session, so validation is always computed against
// what they actually picked — switching methods mid-checkout can't produce a
// stale requirement in either direction.
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( ! orderbox_is_collection() ) return $fields;
	foreach ( ORDERBOX_COLLECTION_HIDDEN_FIELDS as $key ) {
		if ( isset( $fields['billing'][ $key ] ) ) {
			$fields['billing'][ $key ]['required'] = false;
		}
	}
	return $fields;
} );

// Client side: show/hide the address fields live as the customer switches
// between Delivery and Collection (the billing form is NOT one of the
// fragments WooCommerce re-renders on update_checkout, so this needs JS), and
// relabel the orddd_lite date field to "Collection Date" to match.
//
// The relabel is deliberately visual-only. It must NEVER be done by changing
// the plugin's `orddd_lite_delivery_date_field_label` option, because orddd
// uses that option string verbatim as the ORDER META KEY when it saves the
// date — on both the classic path (class-orddd-lite-process.php) and the
// Store API path (class-orddd-lite-delivery-blocks.php). The Pi receipt
// printer and the API's delivery-time extraction both look the value up by
// the literal key "Delivery Date", so renaming the option would silently drop
// the date from every receipt. The field also can't simply be hidden: it's
// mandatory, so checkout would fail validation.
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) return;
	$selectors = '#' . implode( '_field, #', ORDERBOX_COLLECTION_HIDDEN_FIELDS ) . '_field';
	?>
	<script>
	jQuery( function ( $ ) {
		var addressFields = <?php echo wp_json_encode( $selectors ); ?>;
		var origDateLabel = null;

		// The label is "Delivery Date" followed by &nbsp; and, since the field
		// is mandatory, a <abbr class="required">*</abbr>. Swap only the text
		// node so the asterisk survives.
		function dateLabelTextNode() {
			var el = document.querySelector( '#e_deliverydate_field label' );
			if ( ! el ) return null;
			for ( var i = 0; i < el.childNodes.length; i++ ) {
				var n = el.childNodes[ i ];
				if ( n.nodeType === 3 && n.nodeValue.trim() !== '' ) return n;
			}
			return null;
		}

		// What the customer picked on the order-type page. This does NOT depend
		// on their address, which is the whole point: reading the *selected
		// shipping rate* instead was the original bug. The city-based delivery
		// rate only appears once a town is known, so before that the only rate
		// on offer is local_pickup — we read that as "collection", hid the
		// address fields, and removed the only way to enter the town that would
		// have made Delivery appear. A closed loop that trapped customers on
		// Collection.
		// PHP hands us the value it can see server-side. That matters for
		// customers still carrying the older httponly cookie, which the browser
		// will not expose to script — without this fallback the fields would
		// never hide for them until they passed through the order-type page
		// again. The live cookie wins when it is readable, so a change made
		// here on the checkout page takes effect straight away.
		var orderboxType = <?php echo wp_json_encode( (string) ( $_COOKIE['orderbox_order_type'] ?? '' ) ); ?>;

		function orderboxOrderType() {
			var m = document.cookie.match( /(?:^|;\s*)orderbox_order_type=([^;]*)/ );
			return m ? decodeURIComponent( m[1] ) : orderboxType;
		}

		// Read the selected shipping option. Safe now that a Delivery option is
		// always present: previously, before a town was entered the only option
		// was Collection, so this read "collection" for everyone and hid the
		// address fields — removing the only way to enter the town. With
		// Delivery always selectable that loop is gone, and the option the
		// customer can actually see is the honest source of truth. Falls back to
		// the order-type cookie only before the options have rendered.
		function orderboxApplyOrderType() {
			var $checked = $( 'input[name^="shipping_method"]:checked' );
			var collection = $checked.length
				? String( $checked.val() || '' ).indexOf( 'local_pickup' ) !== -1
				: orderboxOrderType() === 'collection';

			$( addressFields ).toggle( ! collection );

			var node = dateLabelTextNode();
			if ( node ) {
				if ( origDateLabel === null ) origDateLabel = node.nodeValue;
				node.nodeValue = collection
					? 'Collection Date' + origDateLabel.match( /\s*$/ )[ 0 ]
					: origDateLabel;
			}
		}

		// Changing the shipping option by hand is a genuine change of mind —
		// record it so it survives the next refresh and stays in step with the
		// order-type page.
		$( document ).on( 'change', 'input[name^="shipping_method"]', function () {
			var val = String( $( this ).val() || '' );
			var type = val.indexOf( 'local_pickup' ) !== -1 ? 'collection' : 'delivery';
			orderboxType = type;
			document.cookie = 'orderbox_order_type=' + type + ';path=/;max-age=86400;samesite=lax';
			orderboxApplyOrderType();
		} );

		$( document.body ).on( 'updated_checkout', orderboxApplyOrderType );
		orderboxApplyOrderType();
	} );
	</script>
	<?php
} );

// ── Payment method wording for collection orders ──────────────────────────────
// The COD gateway is configured with delivery wording ("Pay with cash upon
// delivery."), which collection customers saw too. WooCommerce runs the
// description through woocommerce_gateway_description, and the payment box is
// inside the #order_review fragment that update_checkout re-renders, so a PHP
// filter here tracks the customer's Delivery/Collection choice live with no JS.
add_filter( 'woocommerce_gateway_description', function ( $description, $gateway_id ) {
	if ( 'cod' !== $gateway_id || ! orderbox_is_collection() ) return $description;
	return 'Pay with cash when you collect your order.';
}, 10, 2 );

// ── Stripe gateway label ──────────────────────────────────────────────────────
// With Stripe's Optimized Checkout Suite enabled, the gateway renders as the
// literal string "Stripe" until its JS mounts and swaps in the real payment UI.
// That string is a hardcoded private constant (DEFAULT_TITLE in
// class-wc-stripe-upe-payment-method-oc.php) returned by a get_title() override
// that never calls parent::get_title(), so it is reachable by neither a gateway
// setting (the field no longer exists in Stripe 10.x) nor the standard
// woocommerce_gateway_title filter. Relabelling in JS is the only seam left.
// Re-applied on updated_checkout and on payment-method change because Stripe's
// own script rewrites this area when the method is selected.
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) return;
	?>
	<script>
	jQuery( function ( $ ) {
		function orderboxRelabelStripe() {
			var el = document.querySelector( 'label[for="payment_method_stripe"]' );
			if ( ! el ) return;
			// Swap only the leading text node so the gateway's card icons survive.
			for ( var i = 0; i < el.childNodes.length; i++ ) {
				var n = el.childNodes[ i ];
				if ( n.nodeType === 3 && n.nodeValue.trim() !== '' ) {
					if ( n.nodeValue.trim() === 'Stripe' ) {
						n.nodeValue = n.nodeValue.replace( 'Stripe', 'Pay With Card' );
					}
					return;
				}
			}
		}
		$( document.body ).on( 'updated_checkout payment_method_selected', orderboxRelabelStripe );
		$( document ).on( 'change', 'input[name="payment_method"]', function () {
			setTimeout( orderboxRelabelStripe, 0 );
		} );
		orderboxRelabelStripe();
	} );
	</script>
	<?php
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

		// The overlay must never trap the customer on their own confirmation
		// page: if the API is unreachable or the restaurant is slow to respond,
		// drop the blocking overlay after 90s and show a soft "order received"
		// banner instead. Polling continues in the background, so a later
		// accept/decline still updates the banner via resolve().
		setTimeout(function () {
			if (resolved) return;
			overlay.style.display = 'none';
			result.style.display  = 'block';
			result.style.background  = '#f5f9ff';
			result.style.borderColor = '#1a73e8';
			result.style.color       = '#0d47a1';
			result.innerHTML = 'Your order has been received. The restaurant will confirm it shortly &mdash; updates will appear here. If you need to make changes, please call the restaurant.';
		}, 90000);

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
