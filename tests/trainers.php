<?php
/** Real CRM/database and HTTP trainer regressions; disposable localhost only. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'JPCC_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set JPCC_TEST_WP_ROOT.\n" ); exit( 1 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8775';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( ! defined( 'JPCC_INTEGRATION_TESTS' ) || true !== JPCC_INTEGRATION_TESTS || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit( 1 ); }

use JPCRM_Courses\Admin;
use JPCRM_Courses\CRM;
use JPCRM_Courses\Plugin;
use JPCRM_Courses\Store;
use JPCRM_Courses\Trainers;

$checks = 0; $sessions = array();
function trainer_check( $ok, $label ) {
	global $checks;
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks; echo 'PASS: ' . $label . PHP_EOL;
}
function trainer_rejects( $callback, $label ) {
	try { $callback(); } catch ( Throwable $e ) { trainer_check( true, $label ); return; }
	trainer_check( false, $label );
}
function trainer_save( $id, $ids ) { Trainers::save( $id, $ids, Trainers::version( Trainers::selected( $id ) ) ); }
function trainer_session( $id ) {
	global $sessions;
	wp_set_current_user( $id );
	$token = WP_Session_Tokens::get_instance( $id )->create( time() + 1800 );
	$sessions[] = array( $id, $token );
	$cookie = wp_generate_auth_cookie( $id, time() + 1800, 'logged_in', $token );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	return LOGGED_IN_COOKIE . '=' . $cookie . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie( $id, time() + 1800, 'auth', $token );
}
function trainer_request( $url, $cookie = '', $post = null ) {
	$headers = array(); $curl = curl_init( html_entity_decode( $url ) );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cookie, CURLOPT_TIMEOUT => 20,
		CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$headers ) { $headers[] = trim( $line ); return strlen( $line ); } ) );
	if ( null !== $post ) { curl_setopt( $curl, CURLOPT_POSTFIELDS, http_build_query( $post ) ); }
	$body = curl_exec( $curl );
	if ( false === $body ) { throw new RuntimeException( curl_error( $curl ) ); }
	$status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	return array( $status, $body, $headers );
}

try {
	global $wpdb, $zbs, $ZBSCRM_t;
	wp_set_current_user( 1 );
	trainer_check( CRM::available() && CRM::can_edit() && CRM::can_configure(), 'Real CRM is available to test administrator' );
	update_option( 'jpcc_schema_version', '1.1.0' ); Plugin::upgrade();
	$table = Store::table( 'trainer_courses' );
	trainer_check( JPCRM_COURSES_VERSION === get_option( 'jpcc_schema_version' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table, 'Upgrade installs trainer eligibility table' );
	$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
	$a = Store::save_type( array( 'name' => 'First Aid ' . $suffix, 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 1 ) );
	$b = Store::save_type( array( 'name' => 'CPR ' . $suffix, 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 1 ) );
	$ids = array();
	foreach ( array( 'Sydney', 'Melbourne', 'Sydney', '' ) as $index => $city ) {
		$ids[] = (int) $zbs->DAL->contacts->addUpdateContact( array( 'data' => array( 'fname' => 'Trainer ' . $index, 'lname' => $suffix, 'email' => "trainer-$index-$suffix@example.test", 'status' => 'Customer', 'city' => $city, 'county' => 'Melbourne' === $city ? 'VIC' : 'NSW', 'postcode' => 'Melbourne' === $city ? '3000' : '2000', 'country' => 'Australia' ) ) );
	}
	list( $one, $two, $three, $four ) = $ids;
	trainer_check( 4 === count( array_filter( $ids ) ), 'Create real contacts with main addresses' );
	trainer_check( array() === Trainers::selected( $one ), 'Contacts start with no eligibility' );
	trainer_save( $one, array( $a, (string) $b, $a ) );
	trainer_save( $two, array( $a ) ); trainer_save( $three, array( $b ) );
	trainer_check( 2 === count( Trainers::selected( $one ) ), 'Multiple courses persist and duplicate selections collapse' );
	Store::install();
	trainer_check( 2 === count( Trainers::selected( $one ) ), 'Repeated migration preserves selections' );
	$filter = array( 'search' => $suffix );
	trainer_check( 3 === Trainers::query( $filter )['total'], 'Only eligible trainers appear, once per contact' );
	trainer_check( 2 === Trainers::query( $filter + array( 'course_type_id' => $a ) )['total'], 'Course filtering uses eligibility' );
	trainer_check( 2 === Trainers::query( $filter + array( 'location' => 'syd' ) )['total'], 'Location matches partial city text without case sensitivity' );
	foreach ( array( 'NSW', '2000', 'Sydney NSW 2000' ) as $location ) {
		trainer_check( 2 === Trainers::query( $filter + array( 'location' => $location ) )['total'], 'Location matches ' . $location );
	}
	trainer_check( 3 === Trainers::query( $filter + array( 'location' => 'Australia' ) )['total'], 'Location matches country' );
	$result = Trainers::query( $filter + array( 'course_type_id' => $a, 'location' => 'Sydney' ) );
	trainer_check( 1 === $result['total'] && (int) $result['rows'][0]['id'] === $one && 2 === count( $result['rows'][0]['courses'] ), 'Combined course/location filters return the trainer and all their eligible courses' );
	trainer_check( 1 === Trainers::query( array( 'search' => "trainer-1-$suffix@" ) )['total'], 'Trainer email search' );
	foreach ( array( "' OR 1=1 --", '%', '_' ) as $literal ) {
		trainer_check( 0 === Trainers::query( $filter + array( 'location' => $literal ) )['total'], 'Location escapes SQL and LIKE metacharacters: ' . $literal );
	}
	$first = Trainers::query( $filter, 1, 2 ); $last = Trainers::query( $filter, 9, 2 );
	trainer_check( 2 === count( $first['rows'] ) && 1 === count( $last['rows'] ) && 2 === $last['page'] && ! array_intersect( array_column( $first['rows'], 'id' ), array_column( $last['rows'], 'id' ) ), 'Pagination counts contacts, clamps pages and avoids duplicate trainers' );
	trainer_rejects( function () use ( $one ) { trainer_save( $one, array( 99999999 ) ); }, 'Unknown course rejected' );
	foreach ( array( '1', array( array( 1 ) ), array( -1 ), array( '1 OR 1=1' ) ) as $bad ) {
		trainer_rejects( function () use ( $one, $bad ) { trainer_save( $one, $bad ); }, 'Malformed selections rejected without clearing eligibility' );
	}
	trainer_rejects( function () use ( $a ) { Trainers::save( 99999999, array( $a ), Trainers::version( array() ) ); }, 'Missing contact cannot be assigned eligibility' );
	$stale = Trainers::version( Trainers::selected( $one ) ); trainer_save( $one, array( $a ) );
	trainer_rejects( function () use ( $one, $b, $stale ) { Trainers::save( $one, array( $b ), $stale ); }, 'Stale form cannot overwrite another edit' );
	trainer_check( array( $a ) === Trainers::selected( $one ), 'Rejected stale edit preserves current selections' );
	$break_insert = static function ( $sql ) use ( $table ) { return false !== strpos( $sql, 'INSERT INTO `' . $table . '`' ) ? 'INJECTED DATABASE FAILURE' : $sql; };
	$wpdb->suppress_errors( true ); add_filter( 'query', $break_insert );
	try { trainer_rejects( function () use ( $one, $b ) { trainer_save( $one, array( $b ) ); }, 'Database failure rejects the save' ); }
	finally { remove_filter( 'query', $break_insert ); $wpdb->suppress_errors( false ); }
	trainer_check( array( $a ) === Trainers::selected( $one ), 'Failed replacement rolls back removed selections' );
	Store::save_type( array( 'id' => $b, 'name' => 'CPR renamed ' . $suffix, 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 0 ) );
	trainer_check( 2 === Trainers::query( $filter )['total'] && array( $b ) === Trainers::selected( $three ), 'Archived-only trainers leave the register while selections remain stored' );
	trainer_rejects( function () use ( $one, $b ) { trainer_save( $one, array( $b ) ); }, 'Archived course cannot be newly assigned' );
	trainer_save( $three, array( $b ) );
	Store::save_type( array( 'id' => $b, 'name' => 'CPR renamed ' . $suffix, 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 1 ) );
	trainer_check( 'CPR renamed ' . $suffix === Trainers::query( $filter + array( 'course_type_id' => $b ) )['rows'][0]['courses'][0], 'Reactivating restores trainers and course renames are reflected' );
	trainer_save( $three, array() );
	trainer_check( array() === Trainers::selected( $three ) && 2 === Trainers::query( $filter )['total'], 'Clearing every checkbox removes trainer from register' );
	$zbs->DAL->contacts->addUpdateContact( array( 'id' => $one, 'data' => array_merge( CRM::contact( $one ), array( 'city' => 'Newcastle' ) ) ) );
	trainer_check( 1 === Trainers::query( $filter + array( 'location' => 'Newcastle' ) )['total'], 'Address changes immediately update register search' );
	$tabs = apply_filters( 'jetpack-crm-contact-vital-tabs', array(), $one );
	trainer_check( in_array( 'jpcc-training', array_column( $tabs, 'id' ), true ) && in_array( 'jpcc-courses', array_column( $tabs, 'id' ), true ), 'New training tab coexists with existing courses tab' );
	ob_start(); Admin::training_content( $one ); $html = ob_get_clean();
	trainer_check( false !== strpos( $html, 'name="course_type_ids[]"' ) && false !== strpos( $html, 'Save training eligibility' ) && false !== strpos( $html, 'Newcastle' ), 'Contact tab renders checkbox form and current location' );
	$viewer = wp_insert_user( array( 'user_login' => 'trainer_viewer_' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	$user = get_user_by( 'id', $viewer ); $user->add_cap( 'admin_zerobs_view_customers' ); wp_set_current_user( $viewer );
	trainer_check( 2 === Trainers::query( $filter )['total'] && array( $a ) === Trainers::selected( $one ), 'CRM viewers can search and read eligibility' );
	trainer_rejects( function () use ( $one ) { trainer_save( $one, array() ); }, 'Viewer cannot edit eligibility through the store' );
	ob_start(); Admin::training_content( $one ); $html = ob_get_clean();
	trainer_check( false === strpos( $html, '<form' ) && false !== strpos( $html, 'disabled=' ), 'Viewer receives read-only checkbox display' );
	$user->remove_cap( 'admin_zerobs_view_customers' ); wp_set_current_user( 0 ); wp_set_current_user( $viewer );
	trainer_rejects( function () use ( $filter ) { Trainers::query( $filter ); }, 'Ordinary subscriber cannot query trainers' );
	trainer_rejects( function () use ( $one ) { Trainers::selected( $one ); }, 'Ordinary subscriber cannot read eligibility' );
	wp_set_current_user( 1 );
	// An orphaned eligibility row must never become a visible trainer.
	$wpdb->insert( $table, array( 'contact_id' => 99999999, 'course_type_id' => $a ) );
	trainer_check( ! in_array( '99999999', array_column( Trainers::query( array( 'course_type_id' => $a ) )['rows'], 'id' ), true ), 'Deleted/missing contacts are excluded' );
	$wpdb->delete( $table, array( 'contact_id' => 99999999 ) );

	$cookie = trainer_session( 1 );
	list( $status, $body ) = trainer_request( CRM::training_link( $one ), $cookie );
	trainer_check( 200 === $status && false !== strpos( $body, 'data-tab="jpcc-training"' ) && false !== strpos( $body, 'name="selection_version"' ), 'Real CRM contact page renders the training tab and save form' );
	$post = array( 'action' => 'jpcc_save_training', 'contact_id' => $one, '_wpnonce' => wp_create_nonce( 'jpcc_save_training_' . $one ), 'selection_version' => Trainers::version( Trainers::selected( $one ) ), 'course_type_ids' => array( $a, $b ) );
	$endpoint = admin_url( 'admin-post.php' );
	list( $status ) = trainer_request( $endpoint, $cookie, array_diff_key( $post, array( '_wpnonce' => true ) ) );
	trainer_check( 403 === $status, 'Missing nonce blocks HTTP save' );
	list( $status ) = trainer_request( $endpoint, $cookie, array_merge( $post, array( 'contact_id' => $two ) ) );
	trainer_check( 403 === $status, 'Nonce is bound to the target contact' );
	list( $status ) = trainer_request( add_query_arg( $post, $endpoint ), $cookie );
	trainer_check( 403 === $status, 'GET requests cannot mutate eligibility' );
	list( $status ) = trainer_request( $endpoint, $cookie, array_diff_key( $post, array( 'selection_version' => true ) ) );
	trainer_check( 400 === $status && array( $a ) === Trainers::selected( $one ), 'Missing form version rejects HTTP save without changes' );
	list( $status, $body, $headers ) = trainer_request( $endpoint, $cookie, $post );
	trainer_check( 303 === $status && in_array( 'Location: ' . CRM::training_link( $one, array( 'jpcc_training_saved' => 1 ) ), $headers, true ) && 2 === count( Trainers::selected( $one ) ), 'HTTP save persists eligibility and returns to Training and Assessing' );
	list( $status ) = trainer_request( $endpoint, $cookie, $post );
	trainer_check( 400 === $status && 2 === count( Trainers::selected( $one ) ), 'Repeated stale HTTP submission preserves the saved selections' );
	list( $status, $body ) = trainer_request( Admin::url( array( 'view' => 'trainers', 'course_type_id' => $b, 'location' => 'Newcastle', 'search' => $suffix ) ), $cookie );
	trainer_check( 200 === $status && false !== strpos( $body, "trainer-0-$suffix@example.test" ) && false === strpos( $body, "trainer-1-$suffix@example.test" ) && false !== strpos( $body, 'Trainer register' ), 'Trainer submenu page applies combined filters over HTTP' );
	// Stored markup must remain literal text in both admin surfaces.
	$unsafe_name = '<script>trainer-test-' . $suffix . '</script>';
	$wpdb->update( Store::table( 'types' ), array( 'name' => $unsafe_name ), array( 'id' => $b ) );
	$wpdb->update( $ZBSCRM_t['contacts'], array( 'zbsc_city' => '<img src=x onerror=alert(1)>' ), array( 'ID' => $one ) );
	$_GET = array( 'search' => $suffix ); ob_start(); Admin::trainers_page(); $html = ob_get_clean(); $_GET = array();
	trainer_check( false === strpos( $html, $unsafe_name ) && false !== strpos( $html, esc_html( $unsafe_name ) ) && false !== strpos( $html, '&lt;img' ), 'Course names and contact locations are escaped in results' );
	$post['selection_version'] = Trainers::version( Trainers::selected( $one ) ); unset( $post['course_type_ids'] );
	list( $status ) = trainer_request( $endpoint, $cookie, $post );
	trainer_check( 303 === $status && array() === Trainers::selected( $one ), 'Unchecking all courses clears eligibility over HTTP' );
	$subscriber_cookie = trainer_session( $viewer );
	$post['_wpnonce'] = wp_create_nonce( 'jpcc_save_training_' . $two ); $post['contact_id'] = $two;
	list( $status ) = trainer_request( $endpoint, $subscriber_cookie, $post );
	trainer_check( 403 === $status, 'Valid nonce does not grant an ordinary subscriber editing permission' );
	list( $status, $body ) = trainer_request( Admin::url( array( 'view' => 'trainers' ) ), $subscriber_cookie );
	trainer_check( $status >= 400 && false === strpos( $body, "trainer-1-$suffix@example.test" ), 'Subscriber cannot open trainer register' );
	list( $status, $body ) = trainer_request( Admin::url( array( 'view' => 'trainers' ) ) );
	trainer_check( $status >= 300 && false === strpos( $body, "trainer-1-$suffix@example.test" ), 'Anonymous requests disclose no trainer details' );
	echo "\n$checks trainer integration/HTTP checks passed.\n";
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" ); exit( 1 );
} finally {
	foreach ( $sessions as $session ) { WP_Session_Tokens::get_instance( $session[0] )->destroy( $session[1] ); }
}
