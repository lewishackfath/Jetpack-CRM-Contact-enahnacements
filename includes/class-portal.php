<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

/** Read-only customer access; staff capabilities and record mutations stay separate. */
final class Portal {
	const SLUG = 'jpcc-certificates';
	const READONLY_SETTING = 'portal_readonlyfields';

	public static function boot() {
		add_action( 'jpcrm_client_portal_register_endpoint', array( __CLASS__, 'register_endpoint' ) );
		add_filter( 'jpcrm_client_portal_detail_fields', array( __CLASS__, 'readonly_fields' ), 100 );
		// CRM redirects its customer role away from every wp-admin URL, including
		// admin-post.php. Stream customer certificates from the front end instead.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_certificate' ), 0 );
		add_action( 'admin_post_jpcc_save_portal', array( __CLASS__, 'save_settings' ) );
		add_action( 'template_redirect', array( __CLASS__, 'private_page' ) );
	}

	public static function available() {
		global $zbs;
		return CRM::available() && function_exists( 'zeroBSCRM_isExtensionInstalled' ) && zeroBSCRM_isExtensionInstalled( 'portal' )
			&& isset( $zbs->modules->portal ) && is_callable( array( $zbs->modules->portal, 'is_user_enabled' ) );
	}

	public static function register_endpoint( $portal ) {
		require_once __DIR__ . '/class-portal-endpoint.php';
		$portal->endpoints = Portal_Endpoint::register_endpoint( $portal->endpoints, $portal );
		// CRM flushes after registering all endpoints. Do this once per installation.
		if ( ! get_option( 'jpcc_portal_endpoint_installed' ) ) {
			jpcrm_client_portal_set_rewrite_rules_changed();
			update_option( 'jpcc_portal_endpoint_installed', 1, false );
		}
	}

	/** Match CRM's own portal identity lookup; never accept a contact ID from input. */
	public static function contact_id() {
		global $zbs;
		if ( ! self::available() || ! is_user_logged_in() || ! $zbs->modules->portal->is_user_enabled() ) {
			throw new \RuntimeException( __( 'Client Portal access is unavailable.', 'jpcrm-courses' ) );
		}
		$user = wp_get_current_user();
		$id = $user->user_email ? (int) zeroBS_getCustomerIDWithEmail( $user->user_email ) : 0;
		if ( $id < 1 || ! $zbs->DAL->contacts->getContact( $id, array( 'withCustomFields' => false ) ) || zeroBSCRM_isCustomerPortalDisabled( $id ) ) {
			throw new \RuntimeException( __( 'No enabled CRM contact is linked to your account.', 'jpcrm-courses' ) );
		}
		return $id;
	}

	public static function private_page() {
		global $zbs;
		if ( self::available() && $zbs->modules->portal->is_portal_page() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			nocache_headers();
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	public static function records( $page = 1 ) {
		global $wpdb;
		$contact_id = self::contact_id();
		$records = Store::table( 'records' );
		$types = Store::table( 'types' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $records r JOIN $types t ON t.id = r.course_type_id WHERE r.contact_id = %d", $contact_id ) );
		$pages = max( 1, (int) ceil( $total / 20 ) );
		$page = min( $pages, max( 1, (int) $page ) );
		// Select only customer-facing columns. Notes and staff attribution stay private.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.id, r.course_date, r.expires_on, r.certificate_id, t.name AS course_name FROM $records r JOIN $types t ON t.id = r.course_type_id WHERE r.contact_id = %d ORDER BY r.course_date DESC, r.id DESC LIMIT 20 OFFSET %d", $contact_id, ( $page - 1 ) * 20 ), ARRAY_A );
		if ( null === $rows || $wpdb->last_error ) { throw new \RuntimeException( __( 'Course history is temporarily unavailable.', 'jpcrm-courses' ) ); }
		return array( 'rows' => $rows, 'page' => $page, 'pages' => $pages );
	}

	public static function certificate_url( $id, $download = false ) {
		return wp_nonce_url( add_query_arg( array( 'jpcc_certificate' => $id, 'download' => $download ? 1 : 0 ), home_url( '/' ) ), 'jpcc_portal_certificate_' . $id );
	}

	public static function maybe_certificate() {
		if ( isset( $_GET['jpcc_certificate'] ) ) { self::certificate(); }
	}

	public static function certificate() {
		$id = absint( Plugin::input( 'jpcc_certificate', '', $_GET ) );
		check_admin_referer( 'jpcc_portal_certificate_' . $id );
		try {
			Plugin::serve_certificate( Certificates::get_for_portal( $id ), $id );
		} catch ( \Throwable $e ) {
			wp_die( esc_html__( 'Certificate unavailable or access denied.', 'jpcrm-courses' ), '', array( 'response' => 403 ) );
		}
	}

	public static function render( $endpoint ) {
		try {
			$result = self::records( absint( Plugin::input( 'jpcc_page', '1', $_GET ) ) );
		} catch ( \Throwable $e ) {
			echo '<p role="alert">' . esc_html( $e->getMessage() ) . '</p>';
			return;
		}
		do_action( 'zbs_enqueue_scripts_and_styles' );
		wp_enqueue_style( 'jpcc-portal', plugins_url( 'assets/portal.css', JPCRM_COURSES_FILE ), array(), JPCRM_COURSES_VERSION );
		echo '<div class="alignwide zbs-site-main zbs-portal-grid"><nav class="zbs-portal-nav">';
		$endpoint->portal->render->portal_nav( $endpoint->slug );
		echo '</nav><section class="zbs-portal-content jpcc-portal"><h2>' . esc_html__( 'Training & certificates', 'jpcrm-courses' ) . '</h2><p>' . esc_html__( 'Your training history and certificates. Please contact us if a record needs updating.', 'jpcrm-courses' ) . '</p>';
		if ( ! $result['rows'] ) {
			echo '<p>' . esc_html__( 'No training records are available yet.', 'jpcrm-courses' ) . '</p>';
		} else {
			echo '<div class="jpcc-portal-scroll" role="region" tabindex="0" aria-label="' . esc_attr__( 'Training records', 'jpcrm-courses' ) . '"><table><thead><tr>';
			foreach ( array( __( 'Course', 'jpcrm-courses' ), __( 'Training date', 'jpcrm-courses' ), __( 'Expiry', 'jpcrm-courses' ), __( 'Status', 'jpcrm-courses' ), __( 'Certificate', 'jpcrm-courses' ) ) as $heading ) { echo '<th scope="col">' . esc_html( $heading ) . '</th>'; }
			echo '</tr></thead><tbody>';
			foreach ( $result['rows'] as $row ) {
				$today = current_time( 'Y-m-d' );
				$status = ! $row['expires_on'] ? __( 'No expiry', 'jpcrm-courses' ) : ( $row['expires_on'] < $today ? __( 'Expired', 'jpcrm-courses' ) : __( 'Current', 'jpcrm-courses' ) );
				echo '<tr><th scope="row">' . esc_html( $row['course_name'] ) . '</th><td>' . esc_html( $row['course_date'] ) . '</td><td>' . esc_html( $row['expires_on'] ?: __( 'No expiry', 'jpcrm-courses' ) ) . '</td><td>' . esc_html( $status ) . '</td><td>';
				if ( $row['certificate_id'] ) {
					echo '<a href="' . esc_url( self::certificate_url( $row['id'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'jpcrm-courses' ) . '</a> <a href="' . esc_url( self::certificate_url( $row['id'], true ) ) . '">' . esc_html__( 'Download', 'jpcrm-courses' ) . '</a>';
				} else { echo esc_html__( 'Unavailable', 'jpcrm-courses' ); }
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		if ( $result['pages'] > 1 ) {
			$url = jpcrm_get_client_portal_root_url() . $endpoint->slug;
			echo '<nav class="jpcc-portal-pagination" aria-label="' . esc_attr__( 'Training history pages', 'jpcrm-courses' ) . '">';
			if ( $result['page'] > 1 ) { echo '<a href="' . esc_url( add_query_arg( 'jpcc_page', $result['page'] - 1, $url ) ) . '">' . esc_html__( 'Previous', 'jpcrm-courses' ) . '</a> '; }
			echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'jpcrm-courses' ), $result['page'], $result['pages'] ) ) . '</span> ';
			if ( $result['page'] < $result['pages'] ) { echo '<a href="' . esc_url( add_query_arg( 'jpcc_page', $result['page'] + 1, $url ) ) . '">' . esc_html__( 'Next', 'jpcrm-courses' ) . '</a>'; }
			echo '</nav>';
		}
		echo '</section><div class="zbs-portal-grid-footer">';
		$endpoint->portal->render->portal_footer();
		echo '</div></div>';
	}

	public static function field_choices() {
		global $zbsCustomerFields;
		$choices = array();
		foreach ( (array) $zbsCustomerFields as $key => $field ) {
			if ( isset( $field[0], $field[1] ) && 'autonumber' !== $field[0] ) { $choices[ $key ] = $field[1]; }
		}
		return $choices;
	}

	private static function stored_readonly_keys() {
		global $zbs;
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $zbs->settings->get( self::READONLY_SETTING ) ) ), 'strlen' ) );
	}

	/** Expand existing Pro address groups to individual keys, including custom fields. */
	public static function readonly_keys() {
		global $zbsCustomerFields;
		$keys = self::stored_readonly_keys();
		$groups = array( 'Main Address' => 'jpcrm-main-address', 'Second Address' => 'jpcrm-second-address' );
		foreach ( (array) $zbsCustomerFields as $key => $field ) {
			if ( isset( $field['area'], $groups[ $field['area'] ] ) && in_array( $groups[ $field['area'] ], $keys, true ) ) { $keys[] = $key; }
		}
		return array_values( array_unique( $keys ) );
	}

	public static function readonly_fields( $fields ) {
		if ( ! self::available() ) { return $fields; }
		$keys = self::readonly_keys();
		foreach ( $fields as $key => &$field ) {
			if ( in_array( $key, $keys, true ) ) { $field['read_only'] = true; }
		}
		unset( $field );
		return $fields;
	}

	public static function update_readonly( $selected ) {
		global $zbs;
		if ( ! CRM::available() || ! CRM::can_configure() ) { throw new \RuntimeException( __( 'CRM settings permission is required.', 'jpcrm-courses' ) ); }
		if ( ! is_array( $selected ) || array_filter( $selected, static function ( $value ) { return ! is_string( $value ); } ) ) {
			throw new \InvalidArgumentException( __( 'Invalid field selection.', 'jpcrm-courses' ) );
		}
		$choices = self::field_choices();
		if ( array_diff( $selected, array_keys( $choices ) ) ) { throw new \InvalidArgumentException( __( 'Unknown field. Reload this page and try again.', 'jpcrm-courses' ) ); }
		// Preserve unrecognised extension settings. Known address groups become explicit
		// selections, avoiding CRM 6.8.5's second-address group save inconsistency.
		$preserved = array_diff( self::stored_readonly_keys(), array_keys( $choices ), array( 'jpcrm-main-address', 'jpcrm-second-address' ) );
		$value = implode( ',', array_unique( array_merge( $preserved, $selected ) ) );
		$zbs->settings->update( self::READONLY_SETTING, $value );
		if ( (string) $zbs->settings->get( self::READONLY_SETTING ) !== $value ) { throw new \RuntimeException( __( 'Could not save portal settings.', 'jpcrm-courses' ) ); }
	}

	public static function save_settings() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! CRM::available() || ! CRM::can_configure() ) {
			wp_die( esc_html__( 'CRM settings permission is required.', 'jpcrm-courses' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'jpcc_save_portal' );
		try {
			self::update_readonly( wp_unslash( $_POST['readonly_fields'] ?? array() ) );
		} catch ( \Throwable $e ) { wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=jpcc-client-portal&saved=1' ), 303 );
		exit;
	}

	public static function settings_page() {
		if ( ! CRM::available() || ! CRM::can_configure() ) { wp_die( esc_html__( 'CRM settings permission is required.', 'jpcrm-courses' ), '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Client Portal', 'jpcrm-courses' ) . '</h1>';
		if ( Plugin::input( 'saved', '', $_GET ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Portal settings saved.', 'jpcrm-courses' ) . '</p></div>'; }
		echo '<p>' . esc_html( self::available() ? __( 'Client Portal is active. Customers can view their own training dates and view or download certificates under Training & certificates.', 'jpcrm-courses' ) : __( 'Client Portal is inactive. Enable it in Jetpack CRM Extensions to display training and certificates. You can configure read-only fields now.', 'jpcrm-courses' ) ) . '</p>';
		echo '<h2>' . esc_html__( 'Read-only contact fields', 'jpcrm-courses' ) . '</h2><p>' . esc_html__( 'Selected fields remain visible in Your Details, but customers cannot change them. Hidden fields stay hidden. CRM staff can still edit contacts normally. These selections apply to all portal customers.', 'jpcrm-courses' ) . '</p><p>' . esc_html__( 'This uses the same read-only settings as Jetpack CRM / Client Portal Pro. Existing address-group rules are shown as individual field selections when saved. Account password changes are unaffected.', 'jpcrm-courses' ) . '</p>';
		Admin::post_form( 'save_portal', 'jpcc_save_portal' );
		$selected = self::readonly_keys();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Read only', 'jpcrm-courses' ) . '</th><th>' . esc_html__( 'Field', 'jpcrm-courses' ) . '</th><th>' . esc_html__( 'Field key', 'jpcrm-courses' ) . '</th></tr></thead><tbody>';
		foreach ( self::field_choices() as $key => $label ) {
			echo '<tr><td><input type="checkbox" id="jpcc-field-' . esc_attr( $key ) . '" name="readonly_fields[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $selected, true ), true, false ) . '></td><td><label for="jpcc-field-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></td><td><code>' . esc_html( $key ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save portal settings', 'jpcrm-courses' ) );
		echo '</form></div>';
	}
}
