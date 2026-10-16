<?php
/**
 * Settings: stored in one option, read through one function.
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defaults. Adding fields to new forms is on by default: on a framework site
 * nobody should have to remember to do it.
 */
function sebo_attr_default_settings(): array {
	return array(
		'auto_add'        => true,
		'field_set'       => 'default',
		'require_consent' => false,
		'cookie_days'     => 90,
	);
}

/**
 * One setting, or all of them.
 *
 * @param string|null $key Setting name, or null for the whole array.
 * @return mixed
 */
function sebo_attr_setting( ?string $key = null ) {
	$saved    = get_option( SEBO_ATTR_OPTION, array() );
	$settings = array_merge( sebo_attr_default_settings(), is_array( $saved ) ? $saved : array() );

	return null === $key ? $settings : ( $settings[ $key ] ?? null );
}

/**
 * Clean a settings array from the admin form.
 */
function sebo_attr_sanitize_settings( $input ): array {
	$input = is_array( $input ) ? $input : array();
	$days  = (int) ( $input['cookie_days'] ?? 90 );

	return array(
		'auto_add'        => ! empty( $input['auto_add'] ),
		'field_set'       => 'full' === ( $input['field_set'] ?? '' ) ? 'full' : 'default',
		'require_consent' => ! empty( $input['require_consent'] ),
		// Google Ads accepts offline conversions up to 90 days after the click.
		'cookie_days'     => max( 1, min( 395, $days ) ),
	);
}
