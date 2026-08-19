/**
 * ARL: mirror W2026-27 registration orders into a Google Sheet.
 *
 * Spec: docs/specs/2026-08-14-order-to-google-sheet-design.md
 * Plan: docs/plans/2026-08-14-order-to-google-sheet.md
 *
 * One row per order, keyed on Order ID in column A. The Apps Script upserts, so pushing the same
 * order repeatedly is harmless -- that is what makes retries and backfill safe.
 */

/* ---------- config ---------- */

function arl_sheet_option( $key, $default = '' ) {
	$v = get_option( 'arl_sheet_' . $key, $default );
	return ( '' === $v || null === $v ) ? $default : $v;
}

function arl_sheet_product_ids() {
	$raw = arl_sheet_option( 'product_ids', '116522,116523' );
	return array_values( array_filter( array_map( 'intval', explode( ',', (string) $raw ) ) ) );
}

/* ---------- columns ---------- */

function arl_sheet_columns() {
	return array(
		'Order ID', 'Order Date', 'Product Name', 'Order Status',
		'First Name', 'Last Name', 'Email',
		'Gender', 'D.o.B.', 'Position', 'Experience', 'Division', 'Returning Player',
		'Restricted', 'Requested Team', 'Requested Partner',
		'Captain', 'Requested Partner 2', 'Requested Partner 3',
	);
}

/* ---------- logging ---------- */

function arl_sheet_log( $level, $order_id, $message ) {
	$line = sprintf( "[%s] %-5s order=%s %s\n", gmdate( 'Y-m-d H:i:s' ), $level, $order_id, $message );
	@file_put_contents( WP_CONTENT_DIR . '/arl-sheet-sync.log', $line, FILE_APPEND );
}

/* ---------- scope ---------- */

function arl_sheet_in_scope( $order ) {
	if ( ! $order instanceof WC_Order ) { return false; }
	$ids    = arl_sheet_product_ids();
	$prefix = (string) arl_sheet_option( 'sku_prefix', '116522-' );
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		if ( in_array( $pid, $ids, true ) ) { return true; }
		if ( '' !== $prefix ) {
			$sku = (string) get_post_meta( $pid, '_sku', true );
			if ( '' !== $sku && 0 === strpos( $sku, $prefix ) ) { return true; }
		}
	}
	return false;
}

/* ---------- Position: Player or Goalie, from the product ---------- */

function arl_sheet_position_for_product( $product_id ) {
	$tags = wp_get_post_terms( (int) $product_id, 'product_tag', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $tags ) ) {
		$tags = array_map( 'intval', (array) $tags );
		if ( in_array( 201, $tags, true ) ) { return 'Player'; }
		if ( in_array( 202, $tags, true ) ) { return 'Goalie'; }
	}
	$sku = (string) get_post_meta( (int) $product_id, '_sku', true );
	if ( '' !== $sku ) {
		if ( preg_match( '/-WP$/i', $sku ) ) { return 'Waitlist Player'; }
		if ( preg_match( '/-P$/i', $sku ) )  { return 'Player'; }
		if ( preg_match( '/-G$/i', $sku ) )  { return 'Goalie'; }
	}
	arl_sheet_log( 'WARN', 0, 'position unresolved for product ' . (int) $product_id );
	return '';
}

/* ---------- payload ---------- */

function arl_sheet_build_payload( $order_id ) {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order || ! arl_sheet_in_scope( $order ) ) { return null; }

	$ids       = arl_sheet_product_ids();
	$prefix    = (string) arl_sheet_option( 'sku_prefix', '116522-' );
	$names     = array();
	$positions = array();
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		$sku = (string) get_post_meta( $pid, '_sku', true );
		$hit = in_array( $pid, $ids, true ) || ( '' !== $prefix && '' !== $sku && 0 === strpos( $sku, $prefix ) );
		if ( ! $hit ) { continue; }
		$names[]     = $item->get_name();
		$positions[] = arl_sheet_position_for_product( $pid );
	}
	$names     = array_values( array_unique( array_filter( $names ) ) );
	$positions = array_values( array_unique( array_filter( $positions ) ) );

	$created = $order->get_date_created();

	$values = array(
		'Order ID'            => (string) $order->get_id(),
		'Order Date'          => $created ? $created->date( 'Y-m-d H:i' ) : '',
		'Product Name'        => implode( ' + ', $names ),
		'Order Status'        => wc_get_order_status_name( $order->get_status() ),
		'First Name'          => (string) $order->get_billing_first_name(),
		'Last Name'           => (string) $order->get_billing_last_name(),
		'Email'               => (string) $order->get_billing_email(),
		'Gender'              => (string) $order->get_meta( 'arl_gender' ),
		'D.o.B.'              => (string) $order->get_meta( 'arl_dob' ),
		'Position'            => implode( ' + ', $positions ),
		'Experience'          => (string) $order->get_meta( 'arl_experience' ),
		'Division'            => (string) $order->get_meta( 'arl_division' ),
		'Returning Player'    => (string) $order->get_meta( 'arl_returning' ),
		'Restricted'          => (string) $order->get_meta( 'arl_waitlist_restrictions' ),
		'Requested Team'      => (string) $order->get_meta( 'arl_team' ),
		'Requested Partner'   => (string) $order->get_meta( 'arl_request' ),
		'Captain'             => (string) $order->get_meta( 'arl_captain' ),
		'Requested Partner 2' => (string) $order->get_meta( 'arl_request2' ),
		'Requested Partner 3' => (string) $order->get_meta( 'arl_request3' ),
	);

	// Guarantee every column exists, in order, and nothing extra sneaks in.
	$ordered = array();
	foreach ( arl_sheet_columns() as $col ) {
		$ordered[ $col ] = isset( $values[ $col ] ) ? (string) $values[ $col ] : '';
	}

	return array( 'order_id' => (int) $order->get_id(), 'values' => $ordered );
}

/* ---------- kill switch ----------
 * Hooks that run on the checkout path are gated on this and default to OFF, so the snippet can be
 * installed and its transport exercised via wp-cli with zero checkout exposure. Turn on with:
 *   wp option update arl_sheet_enabled 1
 * Turn off instantly, without editing code, with:
 *   wp option update arl_sheet_enabled 0
 */

function arl_sheet_enabled() {
	return '1' === (string) arl_sheet_option( 'enabled', '0' );
}

/* ---------- worker ----------
 * Always callable directly (wp-cli, Action Scheduler) regardless of the kill switch.
 */

function arl_sheet_push_order( $order_id ) {
	$url    = (string) arl_sheet_option( 'webhook_url', '' );
	$secret = (string) arl_sheet_option( 'secret', '' );
	if ( '' === $url || '' === $secret ) {
		arl_sheet_log( 'SKIP', $order_id, 'webhook_url or secret not configured' );
		return false;
	}

	$payload = arl_sheet_build_payload( $order_id );
	if ( null === $payload ) {
		arl_sheet_log( 'SKIP', $order_id, 'out of scope or order missing' );
		return false;
	}

	$body = wp_json_encode( $payload );
	$sig  = hash_hmac( 'sha256', $body, $secret );

	/*
	 * Apps Script answers a POST with a 302 to script.googleusercontent.com/macros/echo, which is
	 * GET-only. WordPress's HTTP API re-sends the POST body when following a redirect, and Google
	 * answers that with 400 Bad Request. So: do not follow it here -- take the 302 and GET the
	 * Location ourselves. (curl does this method switch automatically, which is why a curl test
	 * passes where wp_remote_post fails.) The doPost side effect has already happened by the time
	 * the 302 is issued, so the row is written either way; we follow only to read {"ok","row"}.
	 */
	$res = wp_remote_post( add_query_arg( 'sig', $sig, $url ), array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => $body,
	) );

	if ( is_wp_error( $res ) ) {
		arl_sheet_log( 'ERR', $order_id, 'transport: ' . $res->get_error_message() );
		throw new Exception( 'arl_sheet transport failure: ' . $res->get_error_message() );
	}

	if ( in_array( (int) wp_remote_retrieve_response_code( $res ), array( 301, 302, 303, 307 ), true ) ) {
		$loc = wp_remote_retrieve_header( $res, 'location' );
		if ( '' === (string) $loc ) {
			arl_sheet_log( 'ERR', $order_id, 'redirect with no Location header' );
			throw new Exception( 'arl_sheet redirect without Location' );
		}
		$res = wp_remote_get( $loc, array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			arl_sheet_log( 'ERR', $order_id, 'redirect fetch: ' . $res->get_error_message() );
			throw new Exception( 'arl_sheet redirect fetch failed: ' . $res->get_error_message() );
		}
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$raw  = (string) wp_remote_retrieve_body( $res );
	$json = json_decode( $raw, true );

	if ( 200 !== $code || empty( $json['ok'] ) ) {
		arl_sheet_log( 'ERR', $order_id, 'http ' . $code . ' body ' . mb_substr( $raw, 0, 200 ) );
		throw new Exception( 'arl_sheet rejected: http ' . $code );
	}

	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->update_meta_data( '_arl_sheet_pushed_at', current_time( 'mysql' ) );
		$order->update_meta_data( '_arl_sheet_row', (int) ( $json['row'] ?? 0 ) );
		$order->save();
	}
	arl_sheet_log( 'OK', $order_id, 'row ' . (int) ( $json['row'] ?? 0 ) );
	return true;
}

/* ---------- triggers ----------
 * Everything here runs on the checkout path, so it is wrapped in try/catch: a failure must never
 * bubble into checkout. The only work done inline is one SELECT and one INSERT via Action
 * Scheduler; the HTTP call happens later in the queued job, never during checkout.
 */

function arl_sheet_enqueue( $order_id ) {
	try {
		if ( ! arl_sheet_enabled() ) { return; }
		$order_id = (int) $order_id;
		if ( ! $order_id || ! function_exists( 'as_enqueue_async_action' ) ) { return; }

		$order = wc_get_order( $order_id );
		if ( ! $order || ! arl_sheet_in_scope( $order ) ) { return; }

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( 'arl_sheet_push', array( $order_id ), 'arl-sheet' ) ) {
			return; // a push is already queued; collapse the burst
		}
		as_enqueue_async_action( 'arl_sheet_push', array( $order_id ), 'arl-sheet' );
	} catch ( Throwable $e ) {
		// Never let sheet syncing break a registration.
		arl_sheet_log( 'ERR', $order_id, 'enqueue: ' . $e->getMessage() );
	}
}

add_action( 'woocommerce_new_order', 'arl_sheet_enqueue', 10, 1 );
add_action( 'woocommerce_order_status_changed', 'arl_sheet_enqueue', 10, 1 );
// Priority 99 so Checkout Field Editor Pro has written its fields before we read them.
add_action( 'woocommerce_process_shop_order_meta', 'arl_sheet_enqueue', 99, 1 );

add_action( 'arl_sheet_push', 'arl_sheet_push_order', 10, 1 );

/* ---------- wp-cli ---------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	function arl_sheet_scope_order_ids() {
		global $wpdb;
		$pids = implode( ',', arl_sheet_product_ids() );
		if ( '' === $pids ) { return array(); }
		return array_map( 'intval', $wpdb->get_col(
			"SELECT DISTINCT i.order_id
			 FROM {$wpdb->prefix}woocommerce_order_items i
			 JOIN {$wpdb->prefix}woocommerce_order_itemmeta m
			   ON m.order_item_id = i.order_item_id AND m.meta_key = '_product_id'
			 WHERE m.meta_value IN ({$pids}) ORDER BY i.order_id" ) );
	}

	WP_CLI::add_command( 'arl sheet:sync', function ( $args, $assoc ) {
		$ids   = ! empty( $args[0] ) ? array( (int) $args[0] ) : arl_sheet_scope_order_ids();
		$force = ! empty( $assoc['force'] );
		$done = 0; $skipped = 0; $failed = 0; $n = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) { continue; }
			if ( ! $force ) {
				$pushed = $order->get_meta( '_arl_sheet_pushed_at' );
				$mod    = $order->get_date_modified();
				if ( $pushed && $mod && strtotime( $pushed ) >= strtotime( $mod->date( 'Y-m-d H:i:s' ) ) ) {
					$skipped++;
					continue;
				}
			}
			try {
				arl_sheet_push_order( $id ) ? $done++ : $skipped++;
			} catch ( Exception $e ) {
				$failed++;
				WP_CLI::warning( $id . ': ' . $e->getMessage() );
			}
			if ( 0 === ++$n % 50 ) { WP_CLI::log( "  ...{$n} processed" ); sleep( 2 ); }
		}
		WP_CLI::success( "pushed {$done}, skipped {$skipped}, failed {$failed}" );
	} );

	WP_CLI::add_command( 'arl sheet:status', function () {
		$ids = arl_sheet_scope_order_ids();
		$up = 0; $stale = 0; $never = 0;
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) { continue; }
			$at  = $o->get_meta( '_arl_sheet_pushed_at' );
			$mod = $o->get_date_modified();
			if ( ! $at ) { $never++; }
			elseif ( $mod && strtotime( $at ) < strtotime( $mod->date( 'Y-m-d H:i:s' ) ) ) { $stale++; }
			else { $up++; }
		}
		WP_CLI::log( 'hooks enabled:   ' . ( arl_sheet_enabled() ? 'YES' : 'no (kill switch off)' ) );
		WP_CLI::log( 'endpoint set:    ' . ( '' !== (string) arl_sheet_option( 'webhook_url', '' ) ? 'yes' : 'NO' ) );
		WP_CLI::log( 'secret set:      ' . ( '' !== (string) arl_sheet_option( 'secret', '' ) ? 'yes' : 'NO' ) );
		WP_CLI::log( 'in-scope orders: ' . count( $ids ) );
		WP_CLI::log( "  up to date:    {$up}" );
		WP_CLI::log( "  stale:         {$stale}" );
		WP_CLI::log( "  never pushed:  {$never}" );
		$log = WP_CONTENT_DIR . '/arl-sheet-sync.log';
		if ( file_exists( $log ) ) {
			WP_CLI::log( "\nlast 10 log lines:" );
			foreach ( array_slice( file( $log ), -10 ) as $l ) { WP_CLI::log( '  ' . rtrim( $l ) ); }
		}
	} );
}
