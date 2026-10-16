<?php
/**
 * What gets recorded, and reading it back out of the visitor's cookie.
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every value the plugin records, in display order.
 *
 * The key is the hidden field's label and, prefixed with `sebo_`, its
 * parameter name and entry meta key. `default` marks the short set added to
 * forms unless the site opts into the full set.
 *
 * "Last" values are the most recent touch that was not Direct; "first" values
 * are the first touch we saw. See assets/capture.js.
 */
function sebo_attr_catalog(): array {
	return array(
		'channel'            => array( 'default' => true, 'help' => 'Channel of the most recent non-direct visit: Paid Search, Organic Search, Paid Social, Organic Social, Email, Referral, Display, Direct…' ),
		'utm_source'         => array( 'default' => true, 'help' => 'utm_source, or derived when absent (google for an Ads click, the referring site otherwise).' ),
		'utm_medium'         => array( 'default' => true, 'help' => 'utm_medium, or derived when absent (cpc, organic, social, referral, (none)).' ),
		'utm_campaign'       => array( 'default' => true, 'help' => 'utm_campaign.' ),
		'utm_term'           => array( 'default' => false, 'help' => 'utm_term.' ),
		'utm_content'        => array( 'default' => false, 'help' => 'utm_content.' ),
		'gclid'              => array( 'default' => true, 'help' => 'Google Ads click ID. The most recent one, even if a later visit came from elsewhere.' ),
		'gbraid'             => array( 'default' => false, 'help' => 'Google Ads click ID used for some iOS traffic instead of gclid.' ),
		'wbraid'             => array( 'default' => false, 'help' => 'Google Ads click ID used for some iOS web-to-app traffic.' ),
		'msclkid'            => array( 'default' => false, 'help' => 'Microsoft Ads click ID.' ),
		'fbclid'             => array( 'default' => false, 'help' => 'Facebook click ID. Facebook adds it to organic link clicks as well as ads.' ),
		'referrer'           => array( 'default' => false, 'help' => 'The external page that linked to the site, when the browser reported one.' ),
		'landing_page'       => array( 'default' => true, 'help' => 'The first page of the most recent non-direct visit.' ),
		'first_channel'      => array( 'default' => false, 'help' => 'Channel of the very first visit we recorded.' ),
		'first_utm_source'   => array( 'default' => false, 'help' => 'Source of the very first visit.' ),
		'first_utm_campaign' => array( 'default' => false, 'help' => 'Campaign of the very first visit.' ),
		'first_landing_page' => array( 'default' => false, 'help' => 'Landing page of the very first visit.' ),
		'first_visit'        => array( 'default' => false, 'help' => 'Date and time of the very first visit we recorded.' ),
		'ga_client_id'       => array( 'default' => false, 'help' => 'Google Analytics client ID, read from GA\'s own _ga cookie. Empty when GA is not on the site.' ),
		'ga_first_visit'     => array( 'default' => false, 'help' => 'When Google Analytics first saw this browser, from the _ga cookie.' ),
	);
}

/**
 * Keys in a field set.
 *
 * @param string $set 'default' or 'full'.
 * @return string[]
 */
function sebo_attr_set_keys( string $set ): array {
	$keys = array();
	foreach ( sebo_attr_catalog() as $key => $def ) {
		if ( 'full' === $set || $def['default'] ) {
			$keys[] = $key;
		}
	}
	return $keys;
}

/**
 * The visitor's attribution values for this request, keyed by catalog key.
 *
 * Read once per request. Every key is present; missing values are ''.
 *
 * @return array<string,string>
 */
function sebo_attr_values(): array {
	static $values = null;
	if ( null !== $values ) {
		return $values;
	}

	$values = array_fill_keys( array_keys( sebo_attr_catalog() ), '' );
	$data   = sebo_attr_cookie_data();

	$last  = is_array( $data['last'] ?? null ) ? $data['last'] : array();
	$first = is_array( $data['first'] ?? null ) ? $data['first'] : array();
	$ids   = is_array( $data['ids'] ?? null ) ? $data['ids'] : array();

	$values['channel']      = sebo_attr_clean( $last['channel'] ?? '' );
	$values['utm_source']   = sebo_attr_clean( $last['source'] ?? '' );
	$values['utm_medium']   = sebo_attr_clean( $last['medium'] ?? '' );
	$values['utm_campaign'] = sebo_attr_clean( $last['campaign'] ?? '' );
	$values['utm_term']     = sebo_attr_clean( $last['term'] ?? '' );
	$values['utm_content']  = sebo_attr_clean( $last['content'] ?? '' );
	$values['referrer']     = sebo_attr_clean_url( $last['referrer'] ?? '' );
	$values['landing_page'] = sebo_attr_clean_url( $last['landing'] ?? '' );

	foreach ( array( 'gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid' ) as $id ) {
		$values[ $id ] = sebo_attr_clean( is_array( $ids[ $id ] ?? null ) ? ( $ids[ $id ]['v'] ?? '' ) : '' );
	}

	$values['first_channel']      = sebo_attr_clean( $first['channel'] ?? '' );
	$values['first_utm_source']   = sebo_attr_clean( $first['source'] ?? '' );
	$values['first_utm_campaign'] = sebo_attr_clean( $first['campaign'] ?? '' );
	$values['first_landing_page'] = sebo_attr_clean_url( $first['landing'] ?? '' );
	$values['first_visit']        = sebo_attr_format_time( $first['t'] ?? 0 );

	// GA4 still writes the _ga cookie as GA1.1.<random>.<first-seen timestamp>.
	$ga = isset( $_COOKIE['_ga'] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE['_ga'] ) ) : '';
	if ( preg_match( '/^GA\d\.\d+\.(\d+)\.(\d+)$/', $ga, $m ) ) {
		$values['ga_client_id']   = $m[1] . '.' . $m[2];
		$values['ga_first_visit'] = sebo_attr_format_time( (int) $m[2] );
	}

	/**
	 * Filter the values before they are saved to an entry.
	 *
	 * @param array<string,string> $values Keyed by catalog key.
	 * @param array                $data   The decoded cookie.
	 */
	$values = (array) apply_filters( 'sebo_attribution_values', $values, $data );

	return $values;
}

/**
 * Decoded cookie, or an empty array when absent or unreadable.
 */
function sebo_attr_cookie_data(): array {
	if ( empty( $_COOKIE[ SEBO_ATTR_COOKIE ] ) ) {
		return array();
	}
	// WordPress adds slashes to $_COOKIE; PHP has already URL-decoded it.
	$raw = wp_unslash( (string) $_COOKIE[ SEBO_ATTR_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitized per value below.
	if ( strlen( $raw ) > 4096 ) {
		return array();
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * A plain text value: sanitized, capped, and safe to open in a spreadsheet.
 *
 * @param mixed $value Untrusted input.
 */
function sebo_attr_clean( $value, int $max = 255 ): string {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$value = sanitize_text_field( (string) $value );
	$value = mb_substr( $value, 0, $max );

	// Entries get exported to CSV and opened in Excel. A value starting with
	// one of these characters can execute as a formula there. Gravity Forms
	// only guards against "=".
	if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
		$value = "'" . $value;
	}
	return $value;
}

/**
 * A URL value: only http(s), capped.
 *
 * @param mixed $value Untrusted input.
 */
function sebo_attr_clean_url( $value ): string {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$url = esc_url_raw( (string) $value, array( 'http', 'https' ) );
	return mb_substr( $url, 0, 500 );
}

/**
 * A Unix timestamp as a site-local date, or '' for nonsense.
 *
 * @param mixed $ts Untrusted input.
 */
function sebo_attr_format_time( $ts ): string {
	$ts = is_numeric( $ts ) ? (int) $ts : 0;
	// Anything before 2005 or more than a day in the future is not a real visit.
	if ( $ts < 1104537600 || $ts > time() + DAY_IN_SECONDS ) {
		return '';
	}
	return (string) wp_date( 'Y-m-d H:i:s', $ts );
}
