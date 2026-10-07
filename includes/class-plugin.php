<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'upgrade' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		add_action( 'admin_menu', array( 'JPCRM_Courses\Admin', 'menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( 'JPCRM_Courses\Admin', 'assets' ) );
		add_filter( 'jetpack-crm-contact-vital-tabs', array( 'JPCRM_Courses\Admin', 'contact_tab' ), 10, 2 );
		add_filter( 'zbs-contacts-menu', array( 'JPCRM_Courses\Admin', 'crm_menu' ) );
		foreach ( array( 'save_type', 'save_record', 'delete_record', 'certificate', 'audience' ) as $action ) {
			add_action( 'admin_post_jpcc_' . $action, array( __CLASS__, $action ) );
		}
		add_action( 'wp_ajax_jpcc_audience_batch', array( __CLASS__, 'audience_batch' ) );
	}

	public static function upgrade() {
		if ( CRM::available() && current_user_can( 'activate_plugins' ) && JPCRM_COURSES_VERSION !== get_option( 'jpcc_schema_version' ) ) { Store::install(); }
	}

	public static function dependency_notice() {
		if ( ! CRM::available() && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Course Certificates requires active Jetpack CRM (built for 6.8.5).', 'jpcrm-courses' ) . '</p></div>';
		}
	}

	public static function input( $key, $default = '', $source = null ) {
		$source = null === $source ? $_POST : $source;
		return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $source[ $key ] ) ) : $default;
	}

	private static function authorize( $nonce, $allowed ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! CRM::available() || ! $allowed ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'jpcrm-courses' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	private static function fail( $error ) {
		wp_die( esc_html( $error->getMessage() ), esc_html__( 'Course Certificates', 'jpcrm-courses' ), array( 'response' => 400, 'back_link' => true ) );
	}

	private static function redirect( $args = array() ) {
		wp_safe_redirect( Admin::url( $args ) );
		exit;
	}

	public static function save_type() {
		self::authorize( 'jpcc_save_type', CRM::can_configure() );
		try {
			$data = array();
			foreach ( array( 'id', 'name', 'validity_amount', 'validity_unit', 'active' ) as $key ) { $data[ $key ] = self::input( $key ); }
			Store::save_type( $data );
			self::redirect( array( 'view' => 'types', 'saved' => 1 ) );
		} catch ( \Throwable $e ) { self::fail( $e ); }
	}

	public static function save_record() {
		self::authorize( 'jpcc_save_record', CRM::can_edit() );
		try {
			$data = array();
			foreach ( array( 'id', 'contact_id', 'course_type_id', 'course_date', 'version' ) as $key ) { $data[ $key ] = self::input( $key ); }
			$data['notes'] = isset( $_POST['notes'] ) && is_string( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
			$id = Store::save_record( $data, Certificates::upload( $_FILES['certificate'] ?? null ) );
			$record = Store::record( $id );
			self::redirect( array( 'contact_id' => $record['contact_id'], 'saved' => 1 ) );
		} catch ( \Throwable $e ) { self::fail( $e ); }
	}

	public static function delete_record() {
		$id = absint( self::input( 'id' ) );
		self::authorize( 'jpcc_delete_record_' . $id, CRM::can_edit() );
		try {
			Store::delete_record( $id, absint( self::input( 'version' ) ) );
			self::redirect( array( 'deleted' => 1 ) );
		} catch ( \Throwable $e ) { self::fail( $e ); }
	}

	public static function certificate() {
		$id = absint( self::input( 'record_id', '', $_GET ) );
		check_admin_referer( 'jpcc_certificate_' . $id );
		try {
			$certificate = Certificates::get( $id );
			$bytes = base64_decode( $certificate['data_base64'], true );
			if ( false === $bytes || strlen( $bytes ) !== (int) $certificate['file_size'] ) { throw new \RuntimeException( __( 'Certificate data is damaged.', 'jpcrm-courses' ) ); }
			while ( ob_get_level() ) { ob_end_clean(); }
			nocache_headers();
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'Content-Type: ' . $certificate['mime'] );
			header( 'Content-Length: ' . strlen( $bytes ) );
			header( 'X-Content-Type-Options: nosniff' );
			// A CSP sandbox blocks native PDF viewers. Serve only validated MIME types,
			// with nosniff, and restrict embedding to this site's authenticated pages.
			header( "Content-Security-Policy: frame-ancestors 'self'" );
			header( 'Referrer-Policy: no-referrer' );
			$disposition = '1' === self::input( 'download', '', $_GET ) ? 'attachment' : 'inline';
			header( 'Content-Disposition: ' . $disposition . '; filename="certificate-' . $id . '.' . pathinfo( $certificate['filename'], PATHINFO_EXTENSION ) . '"; filename*=UTF-8\'\'' . rawurlencode( $certificate['filename'] ) );
			echo $bytes;
			exit;
		} catch ( \Throwable $e ) { self::fail( $e ); }
	}

	public static function audience() {
		self::authorize( 'jpcc_audience', MailPoet::permitted() );
		try {
			$token = self::input( 'token' );
			$ids = isset( $_POST['record_ids'] ) && is_array( $_POST['record_ids'] ) ? array_filter( wp_unslash( $_POST['record_ids'] ), 'is_scalar' ) : array();
			$filters = 'all' === self::input( 'selection' ) ? Dates::filters( wp_unslash( $_POST ) ) : null;
			MailPoet::locked( static function () use ( $token, $ids, $filters ) { return MailPoet::start( $token, self::input( 'audience_name' ), $ids, $filters ); } );
			self::redirect( array( 'view' => 'audience', 'token' => $token ) );
		} catch ( \Throwable $e ) { self::fail( $e ); }
	}

	public static function audience_batch() {
		check_ajax_referer( 'jpcc_audience_batch' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! MailPoet::permitted() ) { wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jpcrm-courses' ) ), 403 ); }
		try {
			$result = MailPoet::locked( static function () { return MailPoet::batch( self::input( 'token' ) ); } );
			wp_send_json_success( $result );
		} catch ( \Throwable $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 400 ); }
	}
}
