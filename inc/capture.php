<?php
/**
 * Front end: load the capture script, and let the server re-issue its cookie.
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load capture.js on every front-end page. It is about 4 KB gzipped, has no
 * dependencies, and is deferred, so it never blocks rendering.
 */
function sebo_attr_enqueue(): void {
	wp_enqueue_script(
		'sebo-attribution',
		plugins_url( 'assets/capture.js', SEBO_ATTR_FILE ),
		array(),
		SEBO_ATTR_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	// Nothing visitor-specific here: this config is baked into cached HTML.
	$config = array(
		'cookie'         => SEBO_ATTR_COOKIE,
		'days'           => (int) sebo_attr_setting( 'cookie_days' ),
		'requireConsent' => (bool) sebo_attr_setting( 'require_consent' ),
		'refreshUrl'     => rest_url( 'sebo-attribution/v1/refresh' ),
	);
	wp_add_inline_script( 'sebo-attribution', 'window.seboAttribution = ' . wp_json_encode( $config ) . ';', 'before' );
}
add_action( 'wp_enqueue_scripts', 'sebo_attr_enqueue' );

/**
 * POST /wp-json/sebo-attribution/v1/refresh
 *
 * Safari caps cookies written by JavaScript at 7 days, which would lose a
 * GCLID long before Google Ads stops accepting it. The same cookie re-issued
 * in an HTTP response from this site is not capped. capture.js calls this
 * only when it has just changed the cookie, so it is rare.
 *
 * It echoes back the visitor's own cookie and nothing else, so it needs no
 * authentication and cannot be used to set anything for anyone else.
 */
function sebo_attr_register_rest(): void {
	register_rest_route(
		'sebo-attribution/v1',
		'/refresh',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'sebo_attr_rest_refresh',
		)
	);
}
add_action( 'rest_api_init', 'sebo_attr_register_rest' );

/**
 * Re-issue the attribution cookie with a server-set expiry.
 */
function sebo_attr_rest_refresh(): WP_REST_Response {
	$data = sebo_attr_cookie_data();
	if ( ! $data ) {
		return new WP_REST_Response( null, 204 );
	}

	// Re-encode rather than echoing raw input, so only valid JSON goes back.
	// Unescaped slashes and Unicode keep it the size capture.js wrote: PHP's
	// default "\/" escaping inflates every URL in the cookie.
	$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json || strlen( $json ) > 4096 ) {
		return new WP_REST_Response( null, 204 );
	}

	// setrawcookie + rawurlencode, because setcookie() encodes spaces as "+",
	// which the browser's decodeURIComponent() leaves as a literal "+" and
	// would turn "Paid Search" into "Paid+Search" on the next write.
	setrawcookie(
		SEBO_ATTR_COOKIE,
		rawurlencode( $json ),
		array(
			'expires'  => time() + DAY_IN_SECONDS * (int) sebo_attr_setting( 'cookie_days' ),
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => false, // capture.js has to read and update it.
			'samesite' => 'Lax',
		)
	);

	$response = new WP_REST_Response( null, 204 );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}
