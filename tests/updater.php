<?php
/** Isolated updater contract tests. No WordPress install or network access needed. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
define( 'JPCRM_COURSES_FILE', 'jetpack-crm-courses/jetpack-crm-courses.php' );
define( 'JPCRM_COURSES_VERSION', '1.0.1' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

// Replace only the WordPress boundary; run the production updater unchanged.
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function plugin_basename( $file ) { return $file; }
function get_site_transient( $key ) { return $GLOBALS['cache'][ $key ] ?? false; }
function set_site_transient( $key, $value, $ttl ) { $GLOBALS['cache'][ $key ] = $value; $GLOBALS['ttl'] = $ttl; }
function delete_site_transient( $key ) { unset( $GLOBALS['cache'][ $key ] ); }
function __( $text, $domain ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain ) { return esc_html( $text ); }
function current_user_can( $cap ) { return $GLOBALS['authorized']; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['requests'][] = array( $url, $args );
	if ( ! array_key_exists( $url, $GLOBALS['responses'] ) ) { throw new RuntimeException( 'Unexpected request: ' . $url ); }
	return $GLOBALS['responses'][ $url ];
}
require dirname( __DIR__ ) . '/includes/class-updater.php';
use JPCRM_Courses\Updater;

$checks = 0;
function expect( $condition, $label ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks;
	echo "PASS: $label\n";
}
function response( $data, $code = 200 ) { return array( 'response' => array( 'code' => $code ), 'body' => json_encode( $data ) ); }
function fixture( $version = '1.2.0', $prefix = 'v' ) {
	$base = Updater::REPOSITORY . '/releases/download/' . $prefix . $version . '/';
	$assets = array();
	foreach ( array( 'jetpack-crm-courses-' . $version . '.zip', 'jetpack-crm-courses-update.json' ) as $name ) {
		$assets[] = array( 'name' => $name, 'browser_download_url' => $base . $name, 'size' => 123, 'state' => 'uploaded' );
	}
	return array( 'draft' => false, 'prerelease' => false, 'tag_name' => $prefix . $version, 'body' => "Release notes\n<script>alert(1)</script>", 'assets' => $assets );
}
function setup( $data = null, $metadata = null ) {
	$data = $data ?? fixture();
	$GLOBALS['cache'] = array();
	$GLOBALS['requests'] = array();
	$GLOBALS['authorized'] = true;
	$GLOBALS['responses'] = array( Updater::API => response( $data ) );
	foreach ( $data['assets'] ?? array() as $asset ) {
		if ( is_array( $asset ) && 'jetpack-crm-courses-update.json' === ( $asset['name'] ?? '' ) ) {
			$GLOBALS['responses'][ $asset['browser_download_url'] ] = response( $metadata ?? array( 'version' => is_string( $data['tag_name'] ) ? ltrim( $data['tag_name'], 'v' ) : '', 'requires' => '6.5', 'requires_php' => '7.4', 'tested' => '7.1' ) );
		}
	}
}

try {
	setup();
	$release = Updater::release();
	expect( '1.2.0' === $release['version'] && '6.5' === $release['requires'] && '7.4' === $release['requires_php'], 'Stable tag and compatibility metadata are returned' );
	expect( Updater::REPOSITORY . '/releases/download/v1.2.0/jetpack-crm-courses-1.2.0.zip' === $release['package'], 'Only the installable release ZIP is offered' );
	expect( count( $GLOBALS['requests'] ) === 2 && $GLOBALS['ttl'] === 21600, 'Successful releases use a six-hour cache' );
	Updater::release();
	expect( count( $GLOBALS['requests'] ) === 2, 'Cached checks make no network requests' );
	expect( ! isset( $GLOBALS['requests'][0][1]['headers']['Authorization'] ) && false === strpos( json_encode( $GLOBALS['requests'] ), '@' ), 'Public checks do not require credentials or transmit contact data' );
	$incoming = array( 'unrelated' => true );
	expect( Updater::update( $incoming, array(), 'other/plugin.php', array() ) === $incoming, 'Other GitHub plugins are left untouched' );
	$update = Updater::update( false, array( 'Version' => '1.0.1' ), JPCRM_COURSES_FILE, array() );
	expect( ! empty( $update['package'] ) && ! isset( $update['notes'], $update['autoupdate'] ), 'Newer version is offered without forcing automatic updates' );
	expect( '' === Updater::update( false, array( 'Version' => '1.2.0' ), JPCRM_COURSES_FILE, array() )['package'], 'Equal version has no upgrade package' );
	expect( '' === Updater::update( false, array( 'Version' => '2.0.0' ), JPCRM_COURSES_FILE, array() )['package'], 'Older releases cannot offer a downgrade' );
	$info = Updater::information( false, 'plugin_information', (object) array( 'slug' => Updater::SLUG ) );
	expect( false === strpos( $info->sections['changelog'], '<script>' ) && false !== strpos( $info->sections['changelog'], '&lt;script&gt;' ), 'Release notes are displayed as escaped text' );
	expect( Updater::information( $incoming, 'query_plugins', (object) array( 'slug' => Updater::SLUG ) ) === $incoming, 'Plugin searches are not intercepted' );
	expect( Updater::information( $incoming, 'plugin_information', (object) array( 'slug' => 'other' ) ) === $incoming, 'Other plugin details are not intercepted' );

	foreach ( array( 'draft', 'prerelease' ) as $field ) {
		$data = fixture(); $data[ $field ] = true; setup( $data );
		expect( false === Updater::release(), ucfirst( $field ) . ' releases are ignored' );
	}
	foreach ( array( 'main', 'v2.0.0-beta.1', 'v1.0', '../1.0.0', 'v01.2.0', array( 'v1.2.0' ) ) as $tag ) {
		$data = fixture(); $data['tag_name'] = $tag; setup( $data );
		expect( false === Updater::release(), 'Invalid or unstable tag rejected: ' . json_encode( $tag ) );
	}
	setup( fixture( '1.2.0', '' ) );
	expect( '1.2.0' === Updater::release()['version'], 'Tags without the optional v prefix are supported' );
	foreach ( array( 'missing_zip', 'missing_manifest', 'source_archive', 'other_host', 'other_repo', 'wrong_tag', 'empty_zip', 'pending_zip' ) as $case ) {
		$data = fixture();
		switch ( $case ) {
			case 'missing_zip': unset( $data['assets'][0] ); break;
			case 'missing_manifest': unset( $data['assets'][1] ); break;
			case 'source_archive': $data['assets'][0]['name'] = 'Source code (zip)'; break;
			case 'other_host': $data['assets'][0]['browser_download_url'] = 'https://example.test/plugin.zip'; break;
			case 'other_repo': $data['assets'][0]['browser_download_url'] = str_replace( 'lewishackfath', 'someone', $data['assets'][0]['browser_download_url'] ); break;
			case 'wrong_tag': $data['assets'][0]['browser_download_url'] = str_replace( '/v1.2.0/', '/v1.0.0/', $data['assets'][0]['browser_download_url'] ); break;
			case 'empty_zip': $data['assets'][0]['size'] = 0; break;
			case 'pending_zip': $data['assets'][0]['state'] = 'new'; break;
		}
		setup( $data );
		expect( false === Updater::release(), 'Incomplete or unexpected asset rejected: ' . $case );
	}
	foreach ( array( array(), array( 'version' => '9.0.0' ), array( 'version' => '1.2.0', 'requires' => array() ), array( 'version' => '1.2.0', 'requires' => '6.5', 'requires_php' => '<script>', 'tested' => '7.1' ) ) as $metadata ) {
		setup( fixture(), $metadata );
		expect( false === Updater::release(), 'Invalid or mismatched manifest rejected' );
	}
	foreach ( array( new WP_Error(), response( array(), 403 ), response( array(), 404 ), response( array(), 500 ), array( 'response' => array( 'code' => 200 ), 'body' => '{invalid' ) ) as $failure ) {
		setup(); $GLOBALS['responses'][ Updater::API ] = $failure;
		expect( false === Updater::release() && false === Updater::release() && count( $GLOBALS['requests'] ) === 1 && $GLOBALS['ttl'] === 300, 'Unavailable GitHub response is handled and briefly cached' );
	}
	expect( Updater::information( false, 'plugin_information', (object) array( 'slug' => Updater::SLUG ) ) instanceof WP_Error, 'Unavailable details return a WordPress error' );
	setup( fixture( '1.0.0' ) );
	expect( '' === Updater::information( false, 'plugin_information', (object) array( 'slug' => Updater::SLUG ) )->download_link, 'Details screen does not offer an older version for installation' );
	setup(); Updater::release();
	$_GET['force-check'] = 1; $GLOBALS['authorized'] = false;
	Updater::maybe_refresh();
	expect( false !== get_site_transient( Updater::CACHE ), 'Unprivileged requests cannot force a refresh' );
	$GLOBALS['authorized'] = true; Updater::maybe_refresh();
	expect( false === get_site_transient( Updater::CACHE ), 'WordPress Check again clears the GitHub cache' );
	Updater::release();
	Updater::after_upgrade( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'other/plugin.php' ) ) );
	expect( false !== get_site_transient( Updater::CACHE ), 'An unrelated upgrade preserves the release cache' );
	Updater::after_upgrade( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( JPCRM_COURSES_FILE ) ) );
	expect( false === get_site_transient( Updater::CACHE ), 'Bulk upgrade clears the release cache' );
	Updater::release();
	Updater::after_upgrade( null, array( 'type' => 'plugin', 'action' => 'update', 'plugin' => JPCRM_COURSES_FILE ) );
	expect( false === get_site_transient( Updater::CACHE ), 'Single upgrade clears the release cache' );
	echo "\n$checks updater checks passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" ); exit( 1 );
}
