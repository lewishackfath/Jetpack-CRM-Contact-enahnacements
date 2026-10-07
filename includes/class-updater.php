<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

/** Public GitHub releases, delivered through WordPress's native plugin updater. */
final class Updater {
	const REPOSITORY = 'https://github.com/lewishackfath/Jetpack-CRM-Contact-enahnacements';
	const API = 'https://api.github.com/repos/lewishackfath/Jetpack-CRM-Contact-enahnacements/releases/latest';
	const SLUG = 'jetpack-crm-courses';
	// Discard metadata cached before the GitHub repository was renamed.
	const CACHE = 'jpcc_github_release_v2';

	public static function boot() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'information' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'links' ), 10, 2 );
		add_action( 'admin_post_jpcc_check_updates', array( __CLASS__, 'check_now' ) );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_refresh' ), 1 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
	}

	private static function json( $url, $limit ) {
		$response = wp_safe_remote_get( $url, array(
			'timeout' => 10,
			'limit_response_size' => $limit,
			'user-agent' => 'JPCRM-Courses/' . JPCRM_COURSES_VERSION,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28' ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return false; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : false;
	}

	public static function release() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) { return $cached['release']; }
		// Brief negative cache also covers the gap while release assets are uploading.
		set_site_transient( self::CACHE, array( 'release' => false ), 5 * MINUTE_IN_SECONDS );
		$data = self::json( self::API, 1024 * 1024 );
		if ( ! $data || ! isset( $data['draft'], $data['prerelease'], $data['tag_name'], $data['assets'] ) ||
			false !== $data['draft'] || false !== $data['prerelease'] || ! is_string( $data['tag_name'] ) || ! is_array( $data['assets'] ) ||
			! preg_match( '/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $data['tag_name'], $match ) ) { return false; }

		$version = $match[1];
		$base = self::REPOSITORY . '/releases/download/' . $data['tag_name'] . '/';
		$zip = self::SLUG . '-' . $version . '.zip';
		$manifest = self::SLUG . '-update.json';
		$assets = array();
		foreach ( $data['assets'] as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'], $asset['state'], $asset['size'] ) &&
				in_array( $asset['name'], array( $zip, $manifest ), true ) && 'uploaded' === $asset['state'] && $asset['size'] > 0 &&
				$asset['browser_download_url'] === $base . $asset['name'] ) {
				$assets[ $asset['name'] ] = $asset['browser_download_url'];
			}
		}
		if ( ! isset( $assets[ $zip ], $assets[ $manifest ] ) ) { return false; }
		$metadata = self::json( $assets[ $manifest ], 16 * 1024 );
		if ( ! $metadata || ( $metadata['version'] ?? null ) !== $version ) { return false; }
		foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
			if ( ! isset( $metadata[ $key ] ) || ! is_string( $metadata[ $key ] ) || ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $metadata[ $key ] ) ) { return false; }
		}
		$release = array(
			'slug' => self::SLUG,
			'version' => $version,
			'url' => self::REPOSITORY . '/releases/tag/' . $data['tag_name'],
			'package' => $assets[ $zip ],
			'requires' => $metadata['requires'],
			'requires_php' => $metadata['requires_php'],
			'tested' => $metadata['tested'],
			'notes' => isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '',
		);
		set_site_transient( self::CACHE, array( 'release' => $release ), 6 * HOUR_IN_SECONDS );
		return $release;
	}

	public static function update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( JPCRM_COURSES_FILE ) !== $plugin_file ) { return $update; }
		$release = self::release();
		if ( ! $release ) { return $update; }
		unset( $release['notes'] );
		// Core separates newer releases into response and older/equal into no_update.
		// An empty package also prevents offering an older release as a download.
		if ( ! version_compare( $release['version'], $plugin_data['Version'], '>' ) ) { $release['package'] = ''; }
		return $release;
	}

	public static function information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== self::SLUG ) { return $result; }
		$release = self::release();
		if ( ! $release ) {
			return new \WP_Error( 'jpcc_release_unavailable', __( 'No complete GitHub release is available, or GitHub could not be reached. Please try again later.', 'jpcrm-courses' ) );
		}
		return (object) array(
			'name' => 'Course Certificates for Jetpack CRM',
			'slug' => self::SLUG,
			'version' => $release['version'],
			'homepage' => self::REPOSITORY,
			'requires' => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested' => $release['tested'],
			'download_link' => version_compare( $release['version'], JPCRM_COURSES_VERSION, '<' ) ? '' : $release['package'],
			'external' => true,
			'sections' => array(
				'description' => esc_html__( 'Contact course history, private certificates, expiry reporting and MailPoet audiences for Jetpack CRM.', 'jpcrm-courses' ),
				'changelog' => '<p>' . nl2br( esc_html( $release['notes'] ?: __( 'See the GitHub release for details.', 'jpcrm-courses' ) ) ) . '</p>',
			),
		);
	}

	public static function links( $links, $file ) {
		if ( plugin_basename( JPCRM_COURSES_FILE ) === $file && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jpcc_check_updates' ), 'jpcc_check_updates' ) ) . '">' . esc_html__( 'Check GitHub for updates', 'jpcrm-courses' ) . '</a>';
		}
		return $links;
	}

	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) { wp_die( esc_html__( 'You do not have permission to update plugins.', 'jpcrm-courses' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'jpcc_check_updates' );
		self::clear();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( self_admin_url( 'plugins.php' ) );
		exit;
	}

	public static function maybe_refresh() {
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			self::clear();
			delete_site_transient( 'update_plugins' );
		}
	}

	public static function clear() { delete_site_transient( self::CACHE ); }

	public static function after_upgrade( $upgrader, $extra ) {
		if ( 'plugin' === ( $extra['type'] ?? '' ) && 'update' === ( $extra['action'] ?? '' ) &&
			in_array( plugin_basename( JPCRM_COURSES_FILE ), $extra['plugins'] ?? array( $extra['plugin'] ?? '' ), true ) ) { self::clear(); }
	}
}
