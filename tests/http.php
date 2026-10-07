<?php
/** HTTP authorization checks for a running, disposable localhost test site. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'JPCC_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set JPCC_TEST_WP_ROOT.\n" ); exit( 1 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8775';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( ! defined( 'JPCC_INTEGRATION_TESTS' ) || true !== JPCC_INTEGRATION_TESTS || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit( 1 ); }

function http_request( $url, $cookie = '', $data = null ) {
	$headers = array();
	$curl = curl_init( html_entity_decode( $url ) );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cookie, CURLOPT_TIMEOUT => 20,
		CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$headers ) { $headers[] = trim( $line ); return strlen( $line ); } ) );
	if ( null !== $data ) { curl_setopt( $curl, CURLOPT_POSTFIELDS, $data ); }
	$body = curl_exec( $curl );
	if ( false === $body ) { throw new RuntimeException( curl_error( $curl ) ); }
	return array( curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ), $body, $headers );
}
function http_check( $ok, $label ) {
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . PHP_EOL;
}
function test_session( $user_id ) {
	wp_set_current_user( $user_id );
	$token = WP_Session_Tokens::get_instance( $user_id )->create( time() + 600 );
	$cookie = wp_generate_auth_cookie( $user_id, time() + 600, 'logged_in', $token );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	return array( $token, LOGGED_IN_COOKIE . '=' . $cookie );
}

$sessions = array();
try {
	global $wpdb;
	wp_set_current_user( 1 );
	$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . JPCRM_Courses\Store::table( 'records' ) );
	$certificate = JPCRM_Courses\Certificates::get( $id );
	list( $token, $cookie ) = test_session( 1 ); $sessions[1] = $token;
	$url = JPCRM_Courses\Certificates::url( $id );
	list( $status, $body, $headers ) = http_request( $url, $cookie );
	http_check( 200 === $status && $body === base64_decode( $certificate['data_base64'] ), 'Authenticated HTTP certificate matches stored bytes' );
	http_check( in_array( 'Cache-Control: private, no-store, max-age=0', $headers, true ) && in_array( 'X-Content-Type-Options: nosniff', $headers, true ), 'Private/no-store and MIME protection headers' );
	list( $status, $body, $headers ) = http_request( JPCRM_Courses\Certificates::url( $id, true ), $cookie );
	http_check( 200 === $status && false !== strpos( implode( "\n", $headers ), 'Content-Disposition: attachment;' ), 'Download action delivers attachment' );
	list( $status, $body ) = http_request( $url );
	http_check( $status >= 400 && $body !== base64_decode( $certificate['data_base64'] ), 'Anonymous certificate request denied' );
	list( $status ) = http_request( admin_url( 'admin-post.php?action=jpcc_certificate&record_id=' . $id ), $cookie );
	http_check( 403 === $status, 'Missing certificate nonce rejected' );
	list( $status ) = http_request( admin_url( 'admin-post.php' ), $cookie, array( 'action' => 'jpcc_delete_record', 'id' => $id, 'version' => 1 ) );
	http_check( 403 === $status, 'Record mutation without nonce rejected' );
	$viewer = null;
	foreach ( get_users( array( 'role' => 'subscriber' ) ) as $candidate ) {
		if ( ! user_can( $candidate, 'admin_zerobs_view_customers' ) ) { $viewer = $candidate; break; }
	}
	if ( ! $viewer ) { throw new RuntimeException( 'Run the integration suite to create an unprivileged test user.' ); }
	list( $token, $cookie ) = test_session( $viewer->ID ); $sessions[ $viewer->ID ] = $token;
	list( $status, $body ) = http_request( JPCRM_Courses\Certificates::url( $id ), $cookie );
	http_check( $status >= 400 && $body !== base64_decode( $certificate['data_base64'] ), 'Valid nonce cannot bypass CRM capability check' );
	echo "7 HTTP checks passed.\n";
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . PHP_EOL );
	$failed = true;
} finally {
	foreach ( $sessions as $user_id => $token ) { WP_Session_Tokens::get_instance( $user_id )->destroy( $token ); }
}
exit( empty( $failed ) ? 0 : 1 );
