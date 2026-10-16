<?php
/**
 * Adding tracking hidden fields to forms, and adopting ones added earlier.
 *
 * Everything here is idempotent: a form that already has a tracking field for
 * a key is never given a second one, so any of the entry points (form settings
 * button, bulk action, WP-CLI, new-form hook) can be run repeatedly.
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read a property from a field that may be a GF_Field object or a plain array.
 * Gravity Forms hands over both, depending on where in its pipeline we are.
 *
 * @param GF_Field|array $field Field.
 * @param string         $prop  Property.
 * @return mixed
 */
function sebo_attr_prop( $field, string $prop ) {
	if ( is_object( $field ) ) {
		return $field->$prop ?? null;
	}
	return is_array( $field ) ? ( $field[ $prop ] ?? null ) : null;
}

/**
 * Catalog key for a field, if it is one of our tracking fields.
 *
 * @param GF_Field|array $field Field.
 */
function sebo_attr_field_key( $field ): string {
	$name = (string) sebo_attr_prop( $field, 'inputName' );
	if ( 0 !== strpos( $name, SEBO_ATTR_PREFIX ) ) {
		return '';
	}
	$key = substr( $name, strlen( SEBO_ATTR_PREFIX ) );
	return array_key_exists( $key, sebo_attr_catalog() ) ? $key : '';
}

/**
 * The form's tracking fields, keyed by catalog key. First one wins.
 *
 * @return array<string,GF_Field|array>
 */
function sebo_attr_form_tracking_fields( array $form ): array {
	$found = array();
	foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
		$key = sebo_attr_field_key( $field );
		if ( $key && ! isset( $found[ $key ] ) ) {
			$found[ $key ] = $field;
		}
	}
	return $found;
}

/**
 * Highest field id in use, including fields nested inside repeaters.
 */
function sebo_attr_max_field_id( array $fields ): int {
	$max = 0;
	foreach ( $fields as $field ) {
		$max    = max( $max, (int) sebo_attr_prop( $field, 'id' ) );
		$nested = sebo_attr_prop( $field, 'fields' );
		if ( is_array( $nested ) ) {
			$max = max( $max, sebo_attr_max_field_id( $nested ) );
		}
	}
	return $max;
}

/**
 * A new hidden field definition.
 */
function sebo_attr_new_field( string $key, int $id, int $form_id ): array {
	return array(
		'type'              => 'hidden',
		'id'                => $id,
		'formId'            => $form_id,
		'label'             => $key,
		'inputName'         => SEBO_ATTR_PREFIX . $key,
		'allowsPrepopulate' => true,
		'cssClass'          => 'sebo-attribution',
	);
}

/**
 * Fields an earlier setup already created for a key, which we should take over
 * instead of adding a duplicate: hidden fields labeled with the key, such as
 * "gclid" or "utm source".
 *
 * @return array<string,int> Catalog key => field id.
 */
function sebo_attr_adoptable( array $form ): array {
	$form_id = (int) ( $form['id'] ?? 0 );
	$by_id   = array();
	foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
		$by_id[ (int) sebo_attr_prop( $field, 'id' ) ] = $field;
	}

	$adopt = array();

	foreach ( $by_id as $fid => $field ) {
		if ( 'hidden' !== sebo_attr_prop( $field, 'type' ) || '' !== (string) sebo_attr_prop( $field, 'inputName' ) ) {
			continue;
		}
		$label = strtolower( trim( (string) sebo_attr_prop( $field, 'label' ) ) );
		$label = preg_replace( '/[\s-]+/', '_', $label );
		if ( array_key_exists( $label, sebo_attr_catalog() ) && ! isset( $adopt[ $label ] ) && ! in_array( $fid, $adopt, true ) ) {
			$adopt[ $label ] = $fid;
		}
	}

	// A field that is already one of ours for another key is never re-pointed.
	foreach ( $adopt as $key => $fid ) {
		if ( sebo_attr_field_key( $by_id[ $fid ] ) ) {
			unset( $adopt[ $key ] );
		}
	}

	return $adopt;
}

/**
 * Plan the changes for a form, without saving.
 *
 * @param array  $form Form, as returned by GFAPI::get_form().
 * @param string $set  'default' or 'full'.
 * @return array{form: array, added: string[], adopted: array<string,string>, present: string[]}
 */
function sebo_attr_plan( array $form, string $set ): array {
	$present = array_keys( sebo_attr_form_tracking_fields( $form ) );
	$adopted = array();
	$added   = array();

	foreach ( sebo_attr_adoptable( $form ) as $key => $fid ) {
		if ( in_array( $key, $present, true ) ) {
			continue;
		}
		foreach ( $form['fields'] as $i => $field ) {
			if ( (int) sebo_attr_prop( $field, 'id' ) !== $fid ) {
				continue;
			}
			$adopted[ $key ] = (string) sebo_attr_prop( $field, 'label' );
			if ( is_object( $field ) ) {
				$field->inputName         = SEBO_ATTR_PREFIX . $key;
				$field->allowsPrepopulate = true;
			} else {
				$form['fields'][ $i ]['inputName']         = SEBO_ATTR_PREFIX . $key;
				$form['fields'][ $i ]['allowsPrepopulate'] = true;
			}
			$present[] = $key;
		}
	}

	$next = sebo_attr_max_field_id( (array) $form['fields'] ) + 1;
	foreach ( sebo_attr_set_keys( $set ) as $key ) {
		if ( in_array( $key, $present, true ) ) {
			continue;
		}
		$def = sebo_attr_new_field( $key, $next++, (int) ( $form['id'] ?? 0 ) );
		if ( class_exists( 'GF_Fields' ) && isset( $form['fields'][0] ) && is_object( $form['fields'][0] ) ) {
			$def = GF_Fields::create( $def );
		}
		$form['fields'][] = $def;
		$added[]          = $key;
	}

	return array(
		'form'    => $form,
		'added'   => $added,
		'adopted' => $adopted,
		'present' => array_values( array_diff( $present, array_keys( $adopted ) ) ),
	);
}

/**
 * Add tracking fields to one form and save it.
 *
 * @param int    $form_id Form.
 * @param string $set     'default' or 'full'; '' uses the site setting.
 * @param bool   $dry_run Report without saving.
 * @return array{title: string, added: string[], adopted: array<string,string>, present: string[]}|WP_Error
 */
function sebo_attr_add_fields( int $form_id, string $set = '', bool $dry_run = false ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return new WP_Error( 'sebo_attr_no_gf', 'Gravity Forms is not active.' );
	}
	$form = GFAPI::get_form( $form_id );
	if ( ! $form ) {
		return new WP_Error( 'sebo_attr_no_form', sprintf( 'Form %d not found.', $form_id ) );
	}
	$form['fields'] = (array) ( $form['fields'] ?? array() );

	$plan = sebo_attr_plan( $form, $set ? $set : (string) sebo_attr_setting( 'field_set' ) );

	if ( ! $dry_run && ( $plan['added'] || $plan['adopted'] ) ) {
		// get_form() includes is_active. update_form() writes exactly what it
		// is given, and a missing is_active deactivates the form (GOTCHAS.md).
		$result = GFAPI::update_form( $plan['form'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return array(
		'title'   => (string) $form['title'],
		'added'   => $plan['added'],
		'adopted' => $plan['adopted'],
		'present' => $plan['present'],
	);
}

/**
 * Add the fields to brand-new forms as they are created.
 *
 * Hooks the one filter every creation path goes through: the form editor's
 * "Create Form", templates, imports, GFAPI::add_form() and the REST API.
 * Adding the fields here, before the editor first loads the form, matters: the
 * editor saves the whole form from the browser, so fields added to the
 * database behind an open editor are deleted on its next save.
 *
 * A form is new when its display_meta has never been written. The
 * notifications row may already exist by then, so the row's existence is not
 * the test.
 *
 * @param mixed  $form_meta Meta being written.
 * @param int    $form_id   Form.
 * @param string $meta_name Which meta column.
 * @return mixed
 */
function sebo_attr_on_new_form( $form_meta, $form_id, $meta_name ) {
	if ( 'display_meta' !== $meta_name || ! is_array( $form_meta ) || ! sebo_attr_setting( 'auto_add' ) ) {
		return $form_meta;
	}

	global $wpdb;
	$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( 'SELECT display_meta FROM %i WHERE form_id = %d', GFFormsModel::get_meta_table_name(), (int) $form_id )
	);
	if ( ! empty( $existing ) ) {
		return $form_meta;
	}

	/**
	 * Skip adding tracking fields to a particular new form.
	 *
	 * @param bool  $add       Whether to add them.
	 * @param array $form_meta The form being created.
	 */
	if ( ! apply_filters( 'sebo_attribution_auto_add', true, $form_meta ) ) {
		return $form_meta;
	}

	$form_meta['id']     = (int) $form_id;
	$form_meta['fields'] = (array) ( $form_meta['fields'] ?? array() );
	$plan                = sebo_attr_plan( $form_meta, (string) sebo_attr_setting( 'field_set' ) );

	return $plan['form'];
}
add_filter( 'gform_form_update_meta', 'sebo_attr_on_new_form', 10, 3 );
