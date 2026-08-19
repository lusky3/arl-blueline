<?php
/**
 * Plugin Name: ARL staging guard — no outbound mail, push or third-party API calls
 * Description: Hard containment for the staging clone. Deliberately a FILE-BASED mu-plugin
 *              so it survives a production database import.
 *
 * Why this is not optional: the staging database is a copy of production. It carries
 *   - FluentSMTP credentials for Mailgun (real sending, real customers),
 *   - the Follow-Up Emails queue (~3,700 unsent rows, including batches scheduled to go out),
 *   - OneSignal push credentials,
 *   - UpdraftPlus credentials for the production Backblaze B2 bucket,
 *   - PayPal gateway credentials.
 * Nothing here may be allowed to reach the outside world.
 *
 * Replaces the previous one-line disable-emails.php, which returned FALSE from pre_wp_mail.
 * That blocks the send but reports failure to the caller, so WooCommerce/FUE take their
 * error paths (logging, retries, "email failed" notices). Returning TRUE is a clean no-op.
 */

if ( ! defined( 'ARL_STAGING_GUARD_LOG' ) ) {
	define( 'ARL_STAGING_GUARD_LOG', WP_CONTENT_DIR . '/staging-guard.log' );
}

function arl_staging_guard_log( $kind, $detail ) {
	@file_put_contents(
		ARL_STAGING_GUARD_LOG,
		sprintf( "[%s] %-14s %s\n", gmdate( 'Y-m-d H:i:s' ), $kind, $detail ),
		FILE_APPEND
	);
}

/* ---------------------------------------------------------------------------
 * 1. Mail. Short-circuit wp_mail() before PHPMailer or FluentSMTP is constructed.
 * ------------------------------------------------------------------------- */

add_filter( 'pre_wp_mail', function ( $short_circuit, $atts ) {
	$to = isset( $atts['to'] ) ? $atts['to'] : '';
	$to = is_array( $to ) ? implode( ',', $to ) : (string) $to;
	arl_staging_guard_log( 'MAIL', $to . ' :: ' . (string) ( isset( $atts['subject'] ) ? $atts['subject'] : '' ) );
	return true; // Pretend success: no send, no retry, no error path.
}, -PHP_INT_MAX, 2 );

/* Second layer, in case anything builds PHPMailer directly instead of via wp_mail(). */
add_action( 'phpmailer_init', function ( $phpmailer ) {
	$to = method_exists( $phpmailer, 'getToAddresses' ) ? $phpmailer->getToAddresses() : array();
	arl_staging_guard_log( 'PHPMAILER', 'recipients stripped: ' . count( $to ) );
	$phpmailer->clearAllRecipients();
	$phpmailer->clearAttachments();
	$phpmailer->Body    = '';
	$phpmailer->AltBody = '';
}, PHP_INT_MAX );

/* ---------------------------------------------------------------------------
 * 2. Outbound HTTP. wp-config sets WP_HTTP_BLOCK_EXTERNAL, which is the real
 *    enforcement; this adds an explicit deny for the hosts that could do damage
 *    even if that constant is ever removed, and logs every attempt so we can see
 *    what the clone tried to phone home to.
 * ------------------------------------------------------------------------- */

add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return $preempt;
	}

	$deny = array(
		'mailgun.net', 'sendgrid.net', 'sendgrid.com', 'api.postmarkapp.com',
		'onesignal.com', 'paypal.com', 'paypalobjects.com',
		'backblazeb2.com', 'api.cloudflare.com', 'api-ssl.bitly.com', 'api.bitly.com',
		'rookiehockey.ca', // never let the clone talk to production
	);

	// Deliberate, staging-only exception: the order -> Google Sheet sync must reach Apps Script.
	// Real enforcement is WP_ACCESSIBLE_HOSTS in wp-config; this branch exists so the traffic is
	// logged rather than invisible. See docs/specs/2026-08-14-order-to-google-sheet-design.md.
	if ( 'script.google.com' === $host || 'script.googleusercontent.com' === $host ) {
		arl_staging_guard_log( 'HTTP ALLOW', $host . ' (order sheet sync)' );
		return $preempt;
	}

	foreach ( $deny as $bad ) {
		if ( $host === $bad || substr( $host, -( strlen( $bad ) + 1 ) ) === '.' . $bad ) {
			// staging.rookiehockey.ca is this site — allow it to reach itself.
			if ( 'staging.rookiehockey.ca' === $host ) {
				return $preempt;
			}
			arl_staging_guard_log( 'HTTP DENY', $host . ' <- ' . $url );
			return new WP_Error( 'arl_staging_guard', 'Blocked by staging guard: ' . $host );
		}
	}
	return $preempt;
}, -PHP_INT_MAX, 3 );

/* ---------------------------------------------------------------------------
 * 3. Make it obvious in wp-admin that this is not production.
 * ------------------------------------------------------------------------- */

add_action( 'admin_notices', function () {
	echo '<div class="notice notice-warning"><p><strong>STAGING CLONE.</strong> '
		. 'Outbound email, push notifications and third-party API calls are blocked by '
		. '<code>mu-plugins/00-staging-guard.php</code>. Blocked attempts are logged to '
		. '<code>wp-content/staging-guard.log</code>.</p></div>';
} );
