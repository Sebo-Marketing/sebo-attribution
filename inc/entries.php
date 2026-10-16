<?php
/**
 * Saving attribution with each Gravity Forms entry.
 *
 * Two routes, both fed from the same cookie at submission time:
 *   - entry meta, on every entry of every form, with no setup;
 *   - hidden fields whose parameter name is `sebo_<key>`, where present.
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register entry meta. Gravity Forms then shows each key as an optional column
 * in the entries list, in CSV export, and in entry search, and calls the
 * callback once when an entry is created.
 */
function sebo_attr_entry_meta( array $entry_meta ): array {
	foreach ( sebo_attr_catalog() as $key => $def ) {
		$entry_meta[ SEBO_ATTR_PREFIX . $key ] = array(
			// Prefixed so the export and column pickers do not list two
			// identical "gclid" entries on a form that also has the hidden field.
			'label'                      => 'Attribution: ' . $key,
			'is_numeric'                 => false,
			'is_default_column'          => false,
			'update_entry_meta_callback' => 'sebo_attr_entry_meta_value',
			'filter'                     => array(
				'operators' => array( 'is', 'isnot', 'contains' ),
			),
		);
	}
	return $entry_meta;
}
add_filter( 'gform_entry_meta', 'sebo_attr_entry_meta' );

/**
 * Value for one meta key on a new entry.
 *
 * Gravity Forms also runs this callback when an admin edits an entry. The
 * person editing is not the person who submitted, so their cookie must never
 * replace what was recorded.
 *
 * @param string $key   Meta key, with prefix.
 * @param array  $entry The entry.
 * @param array  $form  The form.
 */
function sebo_attr_entry_meta_value( $key, $entry, $form ): string {
	$existing = (string) rgar( $entry, $key );
	if ( GFCommon::is_entry_detail_edit() || '' !== $existing ) {
		return $existing;
	}
	if ( is_admin() && ! wp_doing_ajax() ) {
		return '';
	}

	$values = sebo_attr_values();
	$short  = substr( (string) $key, strlen( SEBO_ATTR_PREFIX ) );
	return (string) ( $values[ $short ] ?? '' );
}

/**
 * Fill tracking hidden fields from the cookie just before the entry is saved.
 *
 * This runs after validation, on the POST, so nothing visitor-specific is ever
 * rendered into a page that Kinsta might cache. A value the browser posted is
 * kept only when we have nothing better.
 */
function sebo_attr_fill_fields( array $form ): void {
	$values = sebo_attr_values();

	foreach ( sebo_attr_form_tracking_fields( $form ) as $key => $field ) {
		$value = $values[ $key ] ?? '';
		if ( '' === $value ) {
			continue;
		}
		// $_POST is slashed by WordPress, and Gravity Forms unslashes it.
		$_POST[ 'input_' . $field->id ] = wp_slash( $value ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
}
add_action( 'gform_pre_submission', 'sebo_attr_fill_fields' );

/**
 * "Attribution" box in the sidebar of the entry detail screen.
 */
function sebo_attr_entry_meta_box( array $meta_boxes, $entry, $form ): array {
	$meta_boxes['sebo_attribution'] = array(
		'title'    => esc_html__( 'Attribution', 'sebo-attribution' ),
		'callback' => 'sebo_attr_render_entry_box',
		'context'  => 'side',
	);
	return $meta_boxes;
}
add_filter( 'gform_entry_detail_meta_boxes', 'sebo_attr_entry_meta_box', 10, 3 );

/**
 * Render the entry box.
 *
 * @param array $args Holds 'entry' and 'form'.
 */
function sebo_attr_render_entry_box( $args ): void {
	$entry = $args['entry'] ?? array();
	$rows  = array();
	foreach ( array_keys( sebo_attr_catalog() ) as $key ) {
		$value = (string) rgar( $entry, SEBO_ATTR_PREFIX . $key );
		if ( '' !== $value ) {
			$rows[ $key ] = $value;
		}
	}

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No attribution was recorded for this entry. The visitor may have blocked cookies, declined consent, or submitted before the plugin was installed.', 'sebo-attribution' ) . '</p>';
		return;
	}

	// Stacked rather than a two-column table: the sidebar is narrow, and URLs
	// and click IDs are long.
	echo '<dl style="margin:0">';
	foreach ( $rows as $key => $value ) {
		$shown = preg_match( '#^https?://#', $value )
			? '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>'
			: esc_html( $value );
		printf(
			'<dt style="margin-top:10px"><code>%s</code></dt><dd style="margin:2px 0 0;overflow-wrap:anywhere">%s</dd>',
			esc_html( $key ),
			$shown // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}
	echo '</dl>';
}
