<?php
/**
 * WP-CLI: `wp sebo-attribution …`
 *
 * For rolling out to existing sites from the framework repo:
 *
 *   scripts/wp sebo-attribution status
 *   scripts/wp sebo-attribution add-fields --all --dry-run
 *   scripts/wp sebo-attribution add-fields --all
 *   scripts/wp sebo-attribution add-fields 3 7 --set=full
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Sebo Attribution's tracking fields.
 */
class Sebo_Attribution_CLI {

	/**
	 * List forms and the tracking fields each one has.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sebo-attribution status
	 */
	public function status(): void {
		$this->require_gf();
		$rows = array();
		foreach ( GFAPI::get_forms( null, false ) as $form ) {
			$rows[] = array(
				'id'       => $form['id'],
				'title'    => $form['title'],
				'active'   => $form['is_active'] ? 'yes' : 'no',
				'fields'   => implode( ', ', array_keys( sebo_attr_form_tracking_fields( $form ) ) ),
				'reusable' => implode( ', ', array_keys( sebo_attr_adoptable( $form ) ) ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'title', 'active', 'fields', 'reusable' ) );

		$s = sebo_attr_setting();
		WP_CLI::log( sprintf( 'Settings: auto_add=%s field_set=%s require_consent=%s cookie_days=%d', $s['auto_add'] ? 'on' : 'off', $s['field_set'], $s['require_consent'] ? 'on' : 'off', $s['cookie_days'] ) );
	}

	/**
	 * Add tracking hidden fields to forms. Safe to repeat.
	 *
	 * ## OPTIONS
	 *
	 * [<form-id>...]
	 * : Forms to update.
	 *
	 * [--all]
	 * : Every form, including inactive ones.
	 *
	 * [--set=<set>]
	 * : Which fields. Defaults to the site setting.
	 * ---
	 * options:
	 *   - default
	 *   - full
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would change without saving.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sebo-attribution add-fields --all --dry-run
	 *     wp sebo-attribution add-fields 3 --set=full
	 *
	 * @subcommand add-fields
	 */
	public function add_fields( array $args, array $assoc ): void {
		$this->require_gf();
		$ids = ! empty( $assoc['all'] )
			? array_map( 'intval', wp_list_pluck( GFAPI::get_forms( null, false ), 'id' ) )
			: array_map( 'intval', $args );
		if ( ! $ids ) {
			WP_CLI::error( 'Name one or more form IDs, or pass --all.' );
		}
		$dry = ! empty( $assoc['dry-run'] );
		$set = (string) ( $assoc['set'] ?? '' );

		$failed = 0;
		foreach ( $ids as $id ) {
			$r = sebo_attr_add_fields( $id, $set, $dry );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( sprintf( '#%d: %s', $id, $r->get_error_message() ) );
				++$failed;
				continue;
			}
			$parts = array();
			if ( $r['adopted'] ) {
				$pairs = array();
				foreach ( $r['adopted'] as $key => $label ) {
					$pairs[] = sprintf( '"%s"->%s', $label, $key );
				}
				$parts[] = 'reuse ' . implode( ', ', $pairs );
			}
			if ( $r['added'] ) {
				$parts[] = 'add ' . implode( ', ', $r['added'] );
			}
			WP_CLI::log( sprintf( '%s #%-4d %-30s %s', $dry ? '[dry-run]' : '', $id, $r['title'], $parts ? implode( '; ', $parts ) : 'up to date' ) );
		}

		if ( $failed ) {
			WP_CLI::error( sprintf( '%d form(s) failed.', $failed ) );
		}
		WP_CLI::success( $dry ? 'Dry run complete. Nothing was saved.' : 'Done.' );
	}

	private function require_gf(): void {
		if ( ! class_exists( 'GFAPI' ) ) {
			WP_CLI::error( 'Gravity Forms is not active.' );
		}
	}
}

WP_CLI::add_command( 'sebo-attribution', 'Sebo_Attribution_CLI' );
