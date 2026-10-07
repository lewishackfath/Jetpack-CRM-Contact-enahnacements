<?php
/** Run only in a disposable WordPress install; see tests/README.md. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'JPCC_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set JPCC_TEST_WP_ROOT to a disposable WordPress installation.\n" ); exit( 1 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8775';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( ! defined( 'JPCC_INTEGRATION_TESTS' ) || true !== JPCC_INTEGRATION_TESTS || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	fwrite( STDERR, "Refusing: a localhost test site with JPCC_INTEGRATION_TESTS=true is required.\n" ); exit( 1 );
}
add_filter( 'pre_wp_mail', '__return_true' );
wp_set_current_user( 1 );

use JPCRM_Courses\Dates;
use JPCRM_Courses\Store;
use JPCRM_Courses\CRM;
use JPCRM_Courses\Certificates;
use JPCRM_Courses\MailPoet;
use JPCRM_Courses\Admin;

$checks = 0;
function expect( $condition, $label ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks;
	echo "PASS: $label\n";
}
function rejects( $callback, $label ) {
	try { $callback(); } catch ( Throwable $e ) { expect( true, $label ); return; }
	expect( false, $label );
}

try {
	expect( CRM::available(), 'Real Jetpack CRM DAL is available' );
	expect( JPCRM_VERSION === '6.8.5', 'Target CRM version 6.8.5' );
	expect( MailPoet::available() && MailPoet::permitted(), 'Real MailPoet API and admin permissions' );
	Store::install(); Store::install();
	expect( get_option( 'jpcc_schema_version' ) === JPCRM_COURSES_VERSION, 'Schema installation is repeatable' );
	expect( Dates::expiry( '2024-02-29', 1, 'years' ) === '2025-02-28', 'Leap-day annual expiry clamps to February' );
	expect( Dates::expiry( '2026-01-31', 1, 'months' ) === '2026-02-28', 'Month-end expiry does not overflow' );
	expect( Dates::expiry( '2026-12-31', 1, 'days' ) === '2027-01-01', 'Day expiry crosses year boundary' );
	expect( Dates::expiry( '2026-01-01', 0, 'none' ) === null, 'Non-expiring course' );
	rejects( function () { Dates::parse( '2026-02-29' ); }, 'Invalid calendar date rejected' );
	rejects( function () { Dates::expiry( '2026-01-01', '-1', 'years' ); }, 'Negative expiry rejected' );
	rejects( function () { Dates::expiry( '2026-01-01', '1.5', 'years' ); }, 'Fractional expiry rejected' );
	rejects( function () { Dates::expiry( '9999-01-01', 1, 'years' ); }, 'Date overflow rejected' );
	rejects( function () { Dates::filters( array( 'expiry_month' => '2027-13' ) ); }, 'Invalid expiry month rejected' );
	rejects( function () { Dates::filters( array( 'from' => '2027-02-01', 'to' => '2027-01-01' ) ); }, 'Reversed range rejected' );
	rejects( function () { Dates::filters( array( 'expiry_month' => '2027-01', 'from' => '2027-01-01' ) ); }, 'Conflicting month and range rejected' );

	$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
	$type_id = Store::save_type( array( 'name' => 'First Aid ' . $suffix, 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 1 ) );
	$none_id = Store::save_type( array( 'name' => 'Induction ' . $suffix, 'validity_amount' => 0, 'validity_unit' => 'none', 'active' => 1 ) );
	global $zbs, $wpdb;
	$contacts = array();
	foreach ( array( 'Jamie', 'Morgan', 'Casey', 'Taylor', 'Jordan', 'Alex', 'Sam' ) as $index => $name ) {
		$id = $zbs->DAL->contacts->addUpdateContact( array( 'data' => array( 'fname' => $name, 'lname' => 'Certificate Test', 'email' => strtolower( $name ) . '-' . $suffix . '@example.test', 'status' => 'Customer' ) ) );
		expect( $id > 0 && CRM::contact( $id ), 'Create CRM contact ' . $name );
		$contacts[] = $id;
	}
	$pdf = "%PDF-1.4\n1 0 obj<</Type /Catalog>>endobj\n%%EOF\n";
	$path = tempnam( sys_get_temp_dir(), 'jpcc-cert-' ); file_put_contents( $path, $pdf );
	$certificate = Certificates::read_validated( $path, 'first-aid.pdf' );
	expect( $certificate['mime'] === 'application/pdf', 'PDF content type validated' );
	rejects( function () use ( $path ) { Certificates::read_validated( $path, 'fake.png' ); }, 'Mismatched extension rejected' );
	file_put_contents( $path, '<?php echo "unsafe"; ?>' );
	rejects( function () use ( $path ) { Certificates::read_validated( $path, 'script.pdf' ); }, 'Executable disguised as PDF rejected' );
	file_put_contents( $path, str_repeat( 'x', Certificates::MAX_BYTES + 1 ) );
	rejects( function () use ( $path ) { Certificates::read_validated( $path, 'large.pdf' ); }, 'Oversized certificate rejected' );
	unlink( $path );
	$records = array();
	foreach ( $contacts as $index => $contact ) {
		$records[] = Store::save_record( array( 'contact_id' => $contact, 'course_type_id' => $type_id, 'course_date' => $index === 0 ? '2026-01-01' : '2026-01-31', 'notes' => 'Test certificate' ), $certificate );
	}
	expect( base64_decode( Certificates::get( $records[0] )['data_base64'] ) === $pdf, 'Private certificate round-trip preserves bytes' );
	$filter = Dates::filters( array( 'course_type_id' => $type_id, 'expiry_month' => '2027-01' ) );
	expect( Store::query( $filter )['total'] === 7, 'January filter includes first and last day of month' );
	expect( Store::query( Dates::filters( array( 'course_type_id' => $type_id, 'expiry_month' => '2027-02' ) ) )['total'] === 0, 'Adjacent month excluded' );
	expect( count( Store::query( $filter, 2, 3 )['rows'] ) === 3, 'Database pagination' );
	expect( Store::query( array_merge( $filter, array( 'search' => "' OR 1=1 --" ) ) )['total'] === 0, 'Search treats SQL metacharacters as literal text' );
	$renewal = Store::save_record( array( 'contact_id' => $contacts[0], 'course_type_id' => $type_id, 'course_date' => '2027-01-01' ), $certificate );
	expect( Store::query( $filter )['total'] === 7, 'Historical January certificate remains in all-history view' );
	expect( Store::query( array_merge( $filter, array( 'latest' => true ) ) )['total'] === 6, 'Renewed contact excluded before expiry filtering' );
	$permanent = Store::save_record( array( 'contact_id' => $contacts[0], 'course_type_id' => $none_id, 'course_date' => '2026-01-01' ), $certificate );
	expect( Store::query( array( 'course_type_id' => $none_id, 'status' => 'none' ) )['total'] === 1, 'Non-expiring records queryable' );

	Store::save_type( array( 'id' => $type_id, 'name' => 'First Aid ' . $suffix, 'validity_amount' => 2, 'validity_unit' => 'years', 'active' => 1 ) );
	$row = Store::record( $records[0] );
	Store::save_record( array_merge( $row, array( 'notes' => 'Corrected notes' ) ) );
	expect( Store::record( $records[0] )['expires_on'] === '2027-01-01', 'Course policy changes preserve recorded expiry' );
	$certificate_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( 'certificates' ) );
	rejects( function () use ( $row, $certificate ) { Store::save_record( $row, $certificate ); }, 'Stale edit rejected by record version' );
	expect( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( 'certificates' ) ) === $certificate_count, 'Stale edit rolls back replacement upload' );
	expect( Store::record( $records[0] )['notes'] === 'Corrected notes', 'Stale edit does not overwrite current data' );
	$row = Store::record( $records[0] );
	$old_certificate_id = $row['certificate_id'];
	Store::save_record( $row, $certificate );
	expect( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Store::table( 'certificates' ) . ' WHERE id=%d', $old_certificate_id ) ), 'Replacing certificate removes previous bytes' );
	Store::save_type( array( 'id' => $none_id, 'name' => 'Induction ' . $suffix, 'validity_amount' => 0, 'validity_unit' => 'none', 'active' => 0 ) );
	rejects( function () use ( $contacts, $none_id, $certificate ) { Store::save_record( array( 'contact_id' => $contacts[0], 'course_type_id' => $none_id, 'course_date' => '2026-01-01' ), $certificate ); }, 'Archived course cannot be assigned anew' );
	Store::save_record( Store::record( $permanent ) );
	expect( Store::record( $permanent ) !== null, 'Archived course history can still be edited' );

	$viewer = wp_insert_user( array( 'user_login' => 'viewer_' . $suffix, 'user_email' => 'viewer-' . $suffix . '@example.test', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	$user = get_user_by( 'id', $viewer ); $user->add_cap( 'admin_zerobs_view_customers' );
	wp_set_current_user( $viewer );
	expect( ! empty( Certificates::get( $records[0] ) ), 'CRM contact viewer can read a certificate' );
	rejects( function () use ( $records ) { Store::save_record( Store::record( $records[0] ) ); }, 'Viewer cannot edit courses' );
	rejects( function () use ( $records ) { Store::delete_record( $records[0], 1 ); }, 'Viewer cannot delete courses' );
	rejects( function () { Store::save_type( array( 'name' => 'Forbidden', 'validity_amount' => 1, 'validity_unit' => 'years' ) ); }, 'Viewer cannot configure courses' );
	rejects( function () { MailPoet::api(); }, 'Viewer cannot manage MailPoet audience' );
	$user->remove_cap( 'admin_zerobs_view_customers' ); wp_set_current_user( 0 ); wp_set_current_user( $viewer );
	rejects( function () use ( $records ) { Certificates::get( $records[0] ); }, 'Ordinary WordPress subscriber cannot read certificate' );
	rejects( function () use ( $filter ) { Store::query( $filter ); }, 'Ordinary subscriber cannot query CRM courses' );
	wp_set_current_user( 1 );

	$api = MailPoet::api();
	// Fixture setup needs arbitrary subscription states; the public API intentionally ignores status on add.
	$subscriber_fixture = \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\Subscribers\SubscriberSaveController::class );
	$statuses = array( 'subscribed', 'unsubscribed', 'unconfirmed', 'bounced', 'inactive', 'subscribed' );
	foreach ( $statuses as $index => $status ) {
		$contact = CRM::contact( $contacts[ $index ] );
		$subscriber_fixture->createOrUpdate( array( 'email' => $contact['email'], 'first_name' => $contact['fname'], 'status' => $status ), null );
	}
	$zbs->DAL->contacts->setContactDoNotMail( $contacts[5], true );
	$token = str_replace( '-', '', wp_generate_uuid4() );
	$job = MailPoet::locked( function () use ( $token, $records, $renewal ) { return MailPoet::start( $token, 'January 2027 First Aid', array_merge( $records, array( $renewal, $records[0] ) ) ); } );
	expect( count( $job['contacts'] ) === 7, 'Audience deduplicates contacts across course records' );
	$same = MailPoet::locked( function () use ( $token, $records ) { return MailPoet::start( $token, 'Duplicate submit', $records ); } );
	expect( $same['list_id'] === $job['list_id'], 'Repeated preparation token reuses the same job' );
	$summary = MailPoet::locked( function () use ( $token ) { return MailPoet::batch( $token ); } );
	expect( $summary['done'] && $summary['added'] === 1 && $summary['skipped'] === 6 && $summary['failed'] === 0, 'MailPoet adds only eligible existing subscribers' );
	$again = MailPoet::locked( function () use ( $token ) { return MailPoet::batch( $token ); } );
	expect( $again === $summary, 'Completed batch is idempotent' );
	foreach ( $statuses as $index => $status ) { expect( $api->getSubscriber( CRM::contact( $contacts[ $index ] )['email'] )['status'] === $status, 'Subscription status preserved: ' . $status ); }
	$subscriber = $api->getSubscriber( CRM::contact( $contacts[0] )['email'] );
	expect( in_array( (string) $job['list_id'], array_map( 'strval', array_column( $subscriber['subscriptions'], 'segment_id' ) ), true ), 'Eligible subscriber belongs to generated MailPoet list' );
	rejects( function () use ( $api, $contacts ) { $api->getSubscriber( CRM::contact( $contacts[6] )['email'] ); }, 'Unknown subscriber is not auto-created' );
	$all_token = str_replace( '-', '', wp_generate_uuid4() );
	$all = MailPoet::locked( function () use ( $all_token, $filter ) { return MailPoet::start( $all_token, 'All filtered records', array(), array_merge( $filter, array( 'latest' => true ) ) ); } );
	expect( count( $all['contacts'] ) === 6, 'Select all uses the same latest and expiry filters as the register' );

	ob_start(); Admin::contact_content( $contacts[0] ); $html = ob_get_clean();
	expect( strpos( $html, 'Add course date' ) !== false && strpos( $html, 'first-aid.pdf' ) === false, 'Contact tab renders actions with authenticated certificate URLs' );
	$tabs = apply_filters( 'jetpack-crm-contact-vital-tabs', array(), $contacts[0] );
	expect( in_array( 'jpcc-courses', array_column( $tabs, 'id' ), true ), 'Real CRM hook adds the course tab' );
	$row = Store::record( $permanent );
	Store::delete_record( $permanent, $row['version'] );
	expect( ! Store::record( $permanent ) && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Store::table( 'certificates' ) . ' WHERE id=%d', $row['certificate_id'] ) ), 'Deleting record also deletes its private certificate' );
	echo "\n$checks integration checks passed.\n";
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" ); exit( 1 );
}
