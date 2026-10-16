<?php
/**
 * Plugin Name:       Sebo Attribution
 * Description:       Records where each lead came from (channel, UTM tags, GCLID and other ad click IDs, landing page) and saves it with every Gravity Forms entry.
 * Version:           1.1.0
 * Plugin URI:        https://github.com/Sebo-Marketing/sebo-attribution
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Sebo Marketing
 * Author URI:        https://sebomarketing.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sebo-attribution
 *
 * ---------------------------------------------------------------------------
 * DESIGN NOTES — read before extending.
 *
 * 1. Capture happens in the browser, never at page render. Kinsta serves pages
 *    from an edge cache, so PHP does not run on most page views, and any
 *    visitor-specific value printed into cached HTML would be served to every
 *    other visitor. assets/capture.js writes a first-party cookie; PHP reads it
 *    only when a form is submitted, which is a POST and never cached.
 *
 * 2. Every entry gets the data as entry meta, with no setup per form. Hidden
 *    fields are optional, for notifications, CRM feeds and Zapier, and are
 *    filled at submission time from the same cookie. They are identified by
 *    their parameter name (`sebo_<key>`), not their label, so a client can
 *    rename the label without breaking anything.
 *
 * 3. Parameter names are prefixed on purpose. Gravity Forms also fills
 *    dynamically populated fields from the page's query string; a field named
 *    plain `gclid` would take today's URL over the stored ad click.
 *
 * 4. The cookie is visitor-controlled input. Every value is sanitized and
 *    length-capped before it is stored, and neutralized against spreadsheet
 *    formula injection because entries get exported to CSV.
 * ---------------------------------------------------------------------------
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SEBO_ATTR_VERSION = '1.1.0';
const SEBO_ATTR_FILE    = __FILE__;
const SEBO_ATTR_DIR     = __DIR__;

/** The first-party cookie capture.js writes. */
const SEBO_ATTR_COOKIE = 'sebo_attr';

/** Settings option. */
const SEBO_ATTR_OPTION = 'sebo_attribution_settings';

/** Prefix on every hidden field's parameter name and every entry meta key. */
const SEBO_ATTR_PREFIX = 'sebo_';

foreach ( array( 'settings', 'data', 'capture', 'entries', 'forms', 'admin' ) as $sebo_attr_module ) {
	require_once SEBO_ATTR_DIR . '/inc/' . $sebo_attr_module . '.php';
}
unset( $sebo_attr_module );

/**
 * Updates come from this repository's GitHub releases, like any plugin. The
 * repository is public, so no token is needed.
 */
require SEBO_ATTR_DIR . '/plugin-update-checker/plugin-update-checker.php';
YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/Sebo-Marketing/sebo-attribution/',
	__FILE__,
	'sebo-attribution'
)->setBranch( 'main' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SEBO_ATTR_DIR . '/inc/cli.php';
}
