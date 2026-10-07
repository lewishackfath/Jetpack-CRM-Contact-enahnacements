<?php
/** Exercise core update discovery and ZIP installation in an isolated plugin directory. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'JPCC_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set JPCC_TEST_WP_ROOT to a disposable WordPress installation.\n" ); exit( 1 ); }
$workspace = dirname( __DIR__ );
$scratch = sys_get_temp_dir() . '/jpcc-updater-' . bin2hex( random_bytes( 6 ) );
mkdir( $scratch . '/plugins', 0700, true );
// Never let WordPress's upgrader write through a source checkout's plugin symlink.
define( 'WP_PLUGIN_DIR', $scratch . '/plugins' );
define( 'FS_METHOD', 'direct' );
foreach ( glob( $root . '/wp-content/plugins/*', GLOB_ONLYDIR ) as $directory ) {
	if ( 'jetpack-crm-courses' !== basename( $directory ) ) { symlink( $directory, WP_PLUGIN_DIR . '/' . basename( $directory ) ); }
}
$header = file_get_contents( $workspace . '/jetpack-crm-courses.php' );
preg_match( '/ \* Version: ([0-9.]+)/', $header, $version_match );
$version = $version_match[1];
$archive = new ZipArchive();
if ( true !== $archive->open( $workspace . '/dist/jetpack-crm-courses-' . $version . '.zip' ) ) { throw new RuntimeException( 'Build the current ZIP first.' ); }
$archive->extractTo( WP_PLUGIN_DIR ); $archive->close();
$_SERVER['HTTP_HOST'] = '127.0.0.1:8775';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( ! defined( 'JPCC_INTEGRATION_TESTS' ) || true !== JPCC_INTEGRATION_TESTS || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	fwrite( STDERR, "Refusing: a localhost test site with JPCC_INTEGRATION_TESTS=true is required.\n" ); exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user( 1 );
add_filter( 'pre_wp_mail', '__return_true' );
use JPCRM_Courses\Updater;

$checks = 0;
function expect( $condition, $label ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks; echo "PASS: $label\n";
}
function table_snapshot() {
	global $wpdb;
	$data = array();
	foreach ( array( 'types', 'records', 'certificates' ) as $table ) { $data[ $table ] = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'jpcc_' . $table . ' ORDER BY id', ARRAY_A ); }
	return hash( 'sha256', wp_json_encode( $data ) );
}

$original_active = get_option( 'active_plugins' );
$original_auto = get_site_option( 'auto_update_plugins' );
$file = plugin_basename( JPCRM_COURSES_FILE );
$future = '99.0.0';
$base = Updater::REPOSITORY . '/releases/download/v' . $future . '/';
$package_url = $base . 'jetpack-crm-courses-' . $future . '.zip';
$manifest_url = $base . 'jetpack-crm-courses-update.json';
$release = array( 'draft' => false, 'prerelease' => false, 'tag_name' => 'v' . $future, 'body' => 'Test release <script>alert(1)</script>', 'assets' => array() );
foreach ( array( $package_url, $manifest_url ) as $url ) {
	$release['assets'][] = array( 'name' => basename( $url ), 'state' => 'uploaded', 'size' => 1234, 'browser_download_url' => $url );
}
$manifest = array( 'version' => $future, 'requires' => '6.5', 'requires_php' => '7.4', 'tested' => '7.1' );
$http = function ( $pre, $args, $url ) use ( &$release, &$manifest, $manifest_url ) {
	if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check/' ) ) { $body = array( 'plugins' => array(), 'no_update' => array(), 'translations' => array() ); }
	elseif ( Updater::API === $url ) { $body = $release; }
	elseif ( $manifest_url === $url ) { $body = $manifest; }
	else { return new WP_Error( 'test_network_blocked', 'No external network in integration tests.' ); }
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
};
add_filter( 'pre_http_request', $http, 999, 3 );

try {
	expect( realpath( dirname( JPCRM_COURSES_FILE ) ) === realpath( WP_PLUGIN_DIR . '/jetpack-crm-courses' ), 'Upgrader uses an isolated copy, never the source checkout' );
	expect( has_filter( 'update_plugins_github.com', array( Updater::class, 'update' ) ) !== false, 'Native GitHub update filter is registered' );
	Updater::clear(); delete_site_transient( 'update_plugins' ); wp_clean_plugins_cache( false ); wp_update_plugins();
	$updates = get_site_transient( 'update_plugins' );
	expect( isset( $updates->response[ $file ] ) && $updates->response[ $file ]->new_version === $future, 'WordPress discovers a newer GitHub version' );
	expect( $updates->response[ $file ]->package === $package_url, 'WordPress receives the exact release asset URL' );
	expect( $updates->response[ $file ]->requires_php === '7.4' && $updates->response[ $file ]->requires === '6.5', 'WordPress receives compatibility requirements from the new release' );
	$info = plugins_api( 'plugin_information', array( 'slug' => Updater::SLUG ) );
	expect( $info->version === $future && false === strpos( $info->sections['changelog'], '<script>' ), 'Native plugin information dialog receives safe release notes' );
	$before = table_snapshot();
	$zip_path = $scratch . '/test-update.zip';
	$zip = new ZipArchive(); $zip->open( $zip_path, ZipArchive::CREATE );
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/jetpack-crm-courses', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $path ) {
		if ( ! $path->isFile() ) { continue; }
		$name = substr( $path->getPathname(), strlen( WP_PLUGIN_DIR ) + 1 );
		$contents = file_get_contents( $path->getPathname() );
		if ( $file === $name ) { $contents = str_replace( $version, $future, $contents ); }
		$zip->addFromString( $name, $contents );
	}
	$zip->close();
	add_filter( 'upgrader_pre_download', function ( $pre, $url ) use ( $package_url, $zip_path ) {
		return $url === $package_url ? $zip_path : new WP_Error( 'test_download_blocked', 'Unexpected package URL.' );
	}, 999, 2 );
	$skin = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	// Core's background update path leaves active plugins active. Interactive
	// single updates reactivate through their browser skin; that is not a CLI flow.
	define( 'DOING_CRON', true );
	$result = $upgrader->upgrade( $file );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	expect( true === $result, 'Real WordPress background Plugin_Upgrader installs the release ZIP' );
	expect( get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'] === $future, 'Installed files now contain the new version at the same plugin path' );
	expect( get_option( 'active_plugins' ) === $original_active && is_plugin_active( $file ), 'Plugin activation is preserved after upgrade' );
	expect( table_snapshot() === $before, 'All course types, records and certificate bytes are preserved' );
	expect( get_site_option( 'auto_update_plugins' ) === $original_auto, 'Existing WordPress automatic-update preference is preserved' );
	expect( false === get_site_transient( Updater::CACHE ), 'The completed upgrade clears cached GitHub metadata' );
	Updater::clear(); delete_site_transient( 'update_plugins' ); wp_update_plugins();
	$updates = get_site_transient( 'update_plugins' );
	expect( ! isset( $updates->response[ $file ] ) && isset( $updates->no_update[ $file ] ), 'Installed current release is classified as up to date' );
	echo "\n$checks WordPress update checks passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" ); $failed = true;
} finally {
	update_option( 'active_plugins', $original_active );
	Updater::clear(); wp_clean_plugins_cache();
	// Remove temporary links first so cleanup cannot traverse them.
	foreach ( glob( WP_PLUGIN_DIR . '/*' ) as $path ) { if ( is_link( $path ) ) { unlink( $path ); } }
	$paths = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scratch, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $paths as $path ) { $path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() ); }
	rmdir( $scratch );
}
exit( empty( $failed ) ? 0 : 1 );
