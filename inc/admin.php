<?php
/**
 * wp-admin: settings page, per-form tab, bulk action on the Forms list.
 *
 * @package SeboAttribution
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Capability for everything here: the one Gravity Forms uses to edit forms. */
const SEBO_ATTR_CAP = 'gravityforms_edit_forms';

/**
 * Whether the current user may manage attribution. Administrators hold Gravity
 * Forms' umbrella `gform_full_access` capability rather than the individual
 * ones, so a plain current_user_can() check locks them out.
 */
function sebo_attr_user_can(): bool {
	return class_exists( 'GFCommon' ) ? GFCommon::current_user_can_any( SEBO_ATTR_CAP ) : current_user_can( 'manage_options' );
}

/** The capability to register menus and settings with: whichever this user holds. */
function sebo_attr_menu_cap(): string {
	return current_user_can( 'gform_full_access' ) ? 'gform_full_access' : SEBO_ATTR_CAP;
}

/** The Forms list screen. Gravity Forms registers it as a top-level menu. */
const SEBO_ATTR_FORMS_SCREEN = 'toplevel_page_gf_edit_forms';

/* -------------------------------------------------------------------------
 * Settings page: Forms > Attribution
 * ---------------------------------------------------------------------- */

function sebo_attr_admin_menu(): void {
	if ( ! class_exists( 'GFForms' ) ) {
		return;
	}
	add_submenu_page(
		'gf_edit_forms',
		__( 'Attribution', 'sebo-attribution' ),
		__( 'Attribution', 'sebo-attribution' ),
		sebo_attr_menu_cap(),
		'sebo-attribution',
		'sebo_attr_render_settings_page'
	);
}
add_action( 'admin_menu', 'sebo_attr_admin_menu', 20 );

function sebo_attr_register_settings(): void {
	register_setting(
		'sebo_attribution',
		SEBO_ATTR_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'sebo_attr_sanitize_settings',
			'default'           => sebo_attr_default_settings(),
		)
	);
}
add_action( 'admin_init', 'sebo_attr_register_settings' );

/**
 * Let Gravity Forms editors (not only administrators) save these settings.
 */
add_filter( 'option_page_capability_sebo_attribution', static fn() => sebo_attr_menu_cap() );

function sebo_attr_render_settings_page(): void {
	if ( ! sebo_attr_user_can() ) {
		return;
	}
	$s     = sebo_attr_setting();
	$name  = SEBO_ATTR_OPTION;
	$forms = class_exists( 'GFAPI' ) ? GFAPI::get_forms( null, false ) : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Attribution', 'sebo-attribution' ); ?></h1>
		<?php settings_errors(); // Only Settings-menu pages print "Settings saved" on their own. ?>
		<p style="max-width:60em">
			<?php esc_html_e( 'Every Gravity Forms entry records where the lead came from: channel, UTM tags, ad click IDs and landing page. Look for the Attribution box on an entry, or add the columns to the Entries list. Hidden fields are only needed where a notification, CRM feed or Zapier needs the values inside the form.', 'sebo-attribution' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'sebo_attribution' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'New forms', 'sebo-attribution' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_add]" value="1" <?php checked( $s['auto_add'] ); ?>>
							<?php esc_html_e( 'Add tracking fields to every new form automatically', 'sebo-attribution' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fields to add', 'sebo-attribution' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[field_set]" value="default" <?php checked( 'default', $s['field_set'] ); ?>>
								<?php
								/* translators: %s: list of field names. */
								printf( esc_html__( 'Standard: %s', 'sebo-attribution' ), '<code>' . esc_html( implode( ', ', sebo_attr_set_keys( 'default' ) ) ) . '</code>' );
								?>
							</label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[field_set]" value="full" <?php checked( 'full', $s['field_set'] ); ?>>
								<?php
								/* translators: %d: number of fields. */
								printf( esc_html__( 'Full set (%d fields)', 'sebo-attribution' ), count( sebo_attr_catalog() ) );
								?>
							</label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Applies to the buttons below, the bulk action, and new forms. Entry meta always records everything.', 'sebo-attribution' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Consent', 'sebo-attribution' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[require_consent]" value="1" <?php checked( $s['require_consent'] ); ?>>
							<?php esc_html_e( 'Wait for marketing-cookie consent before recording anything', 'sebo-attribution' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'For sites with a cookie banner. Works with any banner that supports the WP Consent API (Complianz, CookieYes, Cookiebot and others).', 'sebo-attribution' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="sebo-attr-days"><?php esc_html_e( 'Remember visits for', 'sebo-attribution' ); ?></label></th>
					<td>
						<input id="sebo-attr-days" type="number" min="1" max="395" class="small-text" name="<?php echo esc_attr( $name ); ?>[cookie_days]" value="<?php echo esc_attr( (string) $s['cookie_days'] ); ?>"> <?php esc_html_e( 'days', 'sebo-attribution' ); ?>
						<p class="description"><?php esc_html_e( 'Google Ads accepts offline conversions up to 90 days after the click.', 'sebo-attribution' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Forms', 'sebo-attribution' ); ?></h2>
		<?php if ( ! $forms ) : ?>
			<p><?php esc_html_e( 'No forms yet.', 'sebo-attribution' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr>
					<th><?php esc_html_e( 'Form', 'sebo-attribution' ); ?></th>
					<th><?php esc_html_e( 'Tracking fields', 'sebo-attribution' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $forms as $form ) : ?>
					<?php $have = array_keys( sebo_attr_form_tracking_fields( $form ) ); ?>
					<tr>
						<td><a href="<?php echo esc_url( sebo_attr_form_tab_url( (int) $form['id'] ) ); ?>"><?php echo esc_html( $form['title'] ); ?></a> <span class="description">#<?php echo (int) $form['id']; ?></span></td>
						<td><?php echo $have ? '<code>' . esc_html( implode( ', ', $have ) ) . '</code>' : '<em>' . esc_html__( 'none', 'sebo-attribution' ) . '</em>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
				<?php wp_nonce_field( 'sebo_attr_add_fields' ); ?>
				<input type="hidden" name="action" value="sebo_attr_add_fields">
				<input type="hidden" name="form_ids" value="all">
				<input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=sebo-attribution' ) ); ?>">
				<?php submit_button( __( 'Add tracking fields to all forms', 'sebo-attribution' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Safe to repeat: forms that already have a field are skipped.', 'sebo-attribution' ); ?></span>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Per-form tab: Form Settings > Attribution
 * ---------------------------------------------------------------------- */

function sebo_attr_form_tab_url( int $form_id ): string {
	return admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=sebo_attribution&id=' . $form_id );
}

function sebo_attr_form_settings_tab( array $tabs, $form_id ): array {
	$tabs[] = array(
		'name'         => 'sebo_attribution',
		'label'        => __( 'Attribution', 'sebo-attribution' ),
		'icon'         => 'gform-icon--analytics',
		'query'        => array(),
		'capabilities' => array( SEBO_ATTR_CAP ),
	);
	return $tabs;
}
add_filter( 'gform_form_settings_menu', 'sebo_attr_form_settings_tab', 10, 2 );

function sebo_attr_render_form_tab(): void {
	$form_id = absint( rgget( 'id' ) );
	$form    = GFAPI::get_form( $form_id );

	GFFormSettings::page_header( __( 'Attribution', 'sebo-attribution' ) );

	if ( ! $form || ! sebo_attr_user_can() ) {
		echo '<p>' . esc_html__( 'Form not found.', 'sebo-attribution' ) . '</p>';
		GFFormSettings::page_footer();
		return;
	}

	$have  = sebo_attr_form_tracking_fields( $form );
	$adopt = sebo_attr_adoptable( $form );
	?>
	<div class="gform-settings-panel">
		<header class="gform-settings-panel__header"><h4 class="gform-settings-panel__title"><?php esc_html_e( 'Tracking fields', 'sebo-attribution' ); ?></h4></header>
		<div class="gform-settings-panel__content">
			<p><?php esc_html_e( 'Every entry on this form records attribution as entry meta whether or not these fields exist. Add hidden fields when a notification, CRM feed or Zapier needs the values in the form itself. They are filled when the form is submitted.', 'sebo-attribution' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Field label', 'sebo-attribution' ); ?></th>
					<th><?php esc_html_e( 'Status', 'sebo-attribution' ); ?></th>
					<th><?php esc_html_e( 'What it holds', 'sebo-attribution' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( sebo_attr_catalog() as $key => $def ) : ?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code><?php echo $def['default'] ? '' : ' <span class="description">(' . esc_html__( 'full set', 'sebo-attribution' ) . ')</span>'; ?></td>
						<td>
							<?php
							if ( isset( $have[ $key ] ) ) {
								/* translators: 1: field label, 2: field id. */
								printf( esc_html__( 'In form: "%1$s" (field %2$d)', 'sebo-attribution' ), esc_html( (string) sebo_attr_prop( $have[ $key ], 'label' ) ), (int) sebo_attr_prop( $have[ $key ], 'id' ) );
							} elseif ( isset( $adopt[ $key ] ) ) {
								/* translators: %d: field id. */
								printf( esc_html__( 'Existing field %d will be used', 'sebo-attribution' ), (int) $adopt[ $key ] );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td><?php echo esc_html( $def['help'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5em;display:flex;gap:.75em;align-items:center;flex-wrap:wrap">
				<?php wp_nonce_field( 'sebo_attr_add_fields' ); ?>
				<input type="hidden" name="action" value="sebo_attr_add_fields">
				<input type="hidden" name="form_ids" value="<?php echo (int) $form_id; ?>">
				<input type="hidden" name="redirect" value="<?php echo esc_url( sebo_attr_form_tab_url( $form_id ) ); ?>">
				<button type="submit" name="set" value="default" class="button primary"><?php esc_html_e( 'Add standard tracking fields', 'sebo-attribution' ); ?></button>
				<button type="submit" name="set" value="full" class="button"><?php esc_html_e( 'Add full set', 'sebo-attribution' ); ?></button>
			</form>
		</div>
	</div>
	<?php
	GFFormSettings::page_footer();
}
add_action( 'gform_form_settings_page_sebo_attribution', 'sebo_attr_render_form_tab' );

/* -------------------------------------------------------------------------
 * The add-fields action, shared by the settings page and the form tab.
 * ---------------------------------------------------------------------- */

function sebo_attr_handle_add_fields(): void {
	if ( ! sebo_attr_user_can() ) {
		wp_die( esc_html__( 'You do not have permission to edit forms.', 'sebo-attribution' ), 403 );
	}
	check_admin_referer( 'sebo_attr_add_fields' );

	$raw = sanitize_text_field( wp_unslash( $_POST['form_ids'] ?? '' ) );
	$set = sanitize_key( wp_unslash( $_POST['set'] ?? '' ) );
	$ids = 'all' === $raw
		? wp_list_pluck( GFAPI::get_forms( null, false ), 'id' )
		: array( absint( $raw ) );

	sebo_attr_store_results( sebo_attr_add_to_forms( array_map( 'intval', $ids ), in_array( $set, array( 'default', 'full' ), true ) ? $set : '' ) );

	$redirect = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) ), admin_url( 'admin.php?page=sebo-attribution' ) );
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_sebo_attr_add_fields', 'sebo_attr_handle_add_fields' );

/**
 * Run add_fields over several forms.
 *
 * @param int[]  $ids Form ids.
 * @param string $set Field set, or '' for the site setting.
 * @return array<int,array|WP_Error>
 */
function sebo_attr_add_to_forms( array $ids, string $set ): array {
	$results = array();
	foreach ( array_filter( $ids ) as $id ) {
		$results[ $id ] = sebo_attr_add_fields( $id, $set );
	}
	return $results;
}

/* -------------------------------------------------------------------------
 * Bulk action on the Forms list.
 * ---------------------------------------------------------------------- */

add_filter(
	'bulk_actions-' . SEBO_ATTR_FORMS_SCREEN,
	static function ( array $actions ): array {
		if ( sebo_attr_user_can() && 'trash' !== rgget( 'filter' ) ) {
			$actions['sebo_attr_add_fields'] = __( 'Add tracking fields', 'sebo-attribution' );
		}
		return $actions;
	}
);

/**
 * Handle the bulk action before Gravity Forms' own list handler sees it.
 * Gravity's handler ignores actions it does not know, but would then render
 * the list with an empty notice, so this redirects away first.
 */
function sebo_attr_handle_bulk_action(): void {
	// Only the Forms list itself, not the editor or settings views under it.
	if ( rgget( 'view' ) || rgget( 'id' ) ) {
		return;
	}
	$action = (string) rgpost( 'action' );
	if ( '' === $action || '-1' === $action ) {
		$action = (string) rgpost( 'action2' );
	}
	if ( 'sebo_attr_add_fields' !== $action ) {
		return;
	}
	if ( ! sebo_attr_user_can() ) {
		wp_die( esc_html__( 'You do not have permission to edit forms.', 'sebo-attribution' ), 403 );
	}
	check_admin_referer( 'gforms_update_forms', 'gforms_update_forms' );

	$ids = array_map( 'intval', (array) rgpost( 'form' ) );
	sebo_attr_store_results( sebo_attr_add_to_forms( $ids, '' ) );

	wp_safe_redirect( admin_url( 'admin.php?page=gf_edit_forms' ) );
	exit;
}
add_action( 'load-' . SEBO_ATTR_FORMS_SCREEN, 'sebo_attr_handle_bulk_action' );

/* -------------------------------------------------------------------------
 * Notices.
 * ---------------------------------------------------------------------- */

function sebo_attr_store_results( array $results ): void {
	set_transient( 'sebo_attr_results_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS );
}

/**
 * Report the last add-fields run.
 *
 * Gravity Forms hides other plugins' notices on its own screens. To decide
 * which, it calls every admin_notices callback once inside an output buffer
 * and keeps only those whose markup contains "gf-notice". So this callback
 * runs twice per request: it carries that class, builds its output once, and
 * clears the stored result at shutdown instead of on first call.
 */
function sebo_attr_results_notice(): void {
	static $html = null;
	if ( null === $html ) {
		$html = sebo_attr_results_html();
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
}
add_action( 'admin_notices', 'sebo_attr_results_notice' );

function sebo_attr_results_html(): string {
	$key     = 'sebo_attr_results_' . get_current_user_id();
	$results = get_transient( $key );
	if ( ! is_array( $results ) ) {
		return '';
	}
	add_action( 'shutdown', static fn() => delete_transient( $key ) );

	$lines = array();
	$error = false;
	foreach ( $results as $id => $r ) {
		if ( is_wp_error( $r ) ) {
			$error   = true;
			$lines[] = sprintf( '#%d: %s', $id, $r->get_error_message() );
			continue;
		}
		$parts = array();
		if ( $r['added'] ) {
			/* translators: %s: field names. */
			$parts[] = sprintf( __( 'added %s', 'sebo-attribution' ), implode( ', ', $r['added'] ) );
		}
		if ( $r['adopted'] ) {
			$adopted = array();
			foreach ( $r['adopted'] as $field_key => $label ) {
				$adopted[] = sprintf( '"%s" → %s', $label, $field_key );
			}
			/* translators: %s: field names. */
			$parts[] = sprintf( __( 'reused existing fields %s', 'sebo-attribution' ), implode( ', ', $adopted ) );
		}
		$lines[] = sprintf( '%s (#%d): %s', $r['title'], $id, $parts ? implode( '; ', $parts ) : __( 'already up to date', 'sebo-attribution' ) );
	}

	return sprintf(
		'<div class="notice notice-%s gf-notice is-dismissible"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:2em">%s</ul></div>',
		$error ? 'warning' : 'success',
		esc_html__( 'Tracking fields', 'sebo-attribution' ),
		implode( '', array_map( static fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $lines ) )
	);
}
