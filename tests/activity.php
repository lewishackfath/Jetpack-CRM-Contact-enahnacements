<?php
/** Exercise native contact activity and transaction failures on the disposable site. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'JPCC_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set JPCC_TEST_WP_ROOT.\n" ); exit( 1 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8775'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( ! defined( 'JPCC_INTEGRATION_TESTS' ) || true !== JPCC_INTEGRATION_TESTS || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_true' );
wp_set_current_user( 1 );

use JPCRM_Courses\Store;
use JPCRM_Courses\Certificates;

$checks = 0;
function expect_activity( $ok, $message ) {
	global $checks;
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks; echo "PASS: $message\n";
}
function reject_activity( $callback, $message ) {
	try { $callback(); } catch ( Throwable $e ) { expect_activity( true, $message ); return; }
	expect_activity( false, $message );
}
function activity_logs( $contact_id ) {
	global $zbs;
	return $zbs->DAL->logs->getLogsForObj( array( 'objtype' => ZBS_TYPE_CONTACT, 'objid' => $contact_id, 'notetype' => 'note', 'sortByField' => 'ID', 'sortOrder' => 'ASC', 'perPage' => 100 ) );
}
function activity_count( $table ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( $table ) );
}
function activity_text( $log ) {
	return html_entity_decode( wp_strip_all_tags( html_entity_decode( $log['longdesc'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function activity_timeline( $contact_id, $logs = false ) {
	ob_start(); zeroBSCRM_html_contactTimeline( $contact_id, $logs ); return ob_get_clean();
}

try {
	Store::install();
	$suffix = bin2hex( random_bytes( 5 ) );
	$contact_id = $zbs->DAL->contacts->addUpdateContact( array( 'data' => array( 'fname' => 'Activity', 'lname' => 'Test ' . $suffix, 'email' => 'activity-' . $suffix . '@example.test', 'status' => 'Customer' ) ) );
	expect_activity( $contact_id > 0, 'Create a real CRM contact for activity tests' );
	$course_name = 'First Aid & CPR &lt;b&gt;literal&lt;/b&gt; ' . $suffix;
	$type_id = Store::save_type( array( 'name' => $course_name, 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 1 ) );
	$none_id = Store::save_type( array( 'name' => 'Induction ' . $suffix, 'validity_amount' => 0, 'validity_unit' => 'none', 'active' => 1 ) );
	$path = tempnam( sys_get_temp_dir(), 'jpcc-activity-' );
	file_put_contents( $path, "%PDF-1.4\n1 0 obj<</Type /Catalog>>endobj\n%%EOF\n" );
	$certificate = Certificates::read_validated( $path, 'activity.pdf' ); unlink( $path );
	$data = array( 'contact_id' => $contact_id, 'course_type_id' => $type_id, 'course_date' => '2026-01-15', 'notes' => 'Private course notes', 'submission_token' => bin2hex( random_bytes( 16 ) ) );
	$started = time();
	$id = Store::save_record( $data, $certificate );
	$logs = activity_logs( $contact_id );
	expect_activity( count( $logs ) === 1 && $logs[0]['shortdesc'] === "Course record created (#$id)", 'Creating a course adds exactly one native CRM note with its record ID' );
	expect_activity( (int) $logs[0]['authorid'] === 1 && $logs[0]['author'] === wp_get_current_user()->display_name && (int) $logs[0]['createduts'] >= $started, 'CRM records the creating user and timestamp' );
	$text = activity_text( $logs[0] );
	expect_activity( strpos( $text, $course_name ) !== false && strpos( $text, '2026-01-15' ) !== false && strpos( $text, '2027-01-15' ) !== false && strpos( $text, 'Certificate uploaded.' ) !== false, 'Creation activity includes the course, date, expiry and upload' );
	expect_activity( strpos( $text, 'Private course notes' ) === false && strpos( $text, 'data_base64' ) === false, 'Activity does not copy private notes or certificate data' );
	$html = activity_timeline( $contact_id );
	expect_activity( strpos( $html, "Course record created (#$id)" ) !== false && strpos( $html, esc_html( wp_get_current_user()->display_name ) ) !== false && strpos( $html, 'clock icon' ) !== false, 'Native contact timeline renders the activity, author and time' );
	expect_activity( strpos( $html, 'First Aid &amp; CPR &amp;lt;b&amp;gt;literal&amp;lt;/b&amp;gt;' ) !== false && strpos( $html, '<b>literal</b>' ) === false, 'Native renderer preserves literal course names without interpreting their entities as HTML' );
	expect_activity( Store::save_record( $data ) === $id && count( activity_logs( $contact_id ) ) === 1, 'A repeated submission does not duplicate the creation log' );
	Store::save_record( Store::record( $id ) );
	expect_activity( count( activity_logs( $contact_id ) ) === 1, 'Saving unchanged fields does not add an activity entry' );

	$editor_id = wp_insert_user( array( 'user_login' => 'course_editor_' . $suffix, 'user_email' => 'editor-' . $suffix . '@example.test', 'user_pass' => wp_generate_password( 32 ), 'display_name' => 'Course Editor ' . $suffix, 'role' => 'subscriber' ) );
	if ( is_wp_error( $editor_id ) ) { throw new RuntimeException( $editor_id->get_error_message() ); }
	$editor = get_user_by( 'id', $editor_id );
	$editor->add_cap( 'admin_zerobs_view_customers' ); $editor->add_cap( 'admin_zerobs_customers' );
	wp_set_current_user( $editor_id );
	$old = Store::record( $id );
	Store::save_record( array_merge( $old, array( 'course_date' => '2026-02-20', 'notes' => 'Revised private notes' ) ) );
	$logs = activity_logs( $contact_id ); $text = activity_text( end( $logs ) );
	expect_activity( count( $logs ) === 2 && (int) $logs[1]['authorid'] === $editor_id && (int) Store::record( $id )['created_by'] === 1, 'An edit records its editor while preserving the original record creator' );
	expect_activity( strpos( $text, 'Course date: 2026-01-15 → 2026-02-20' ) !== false && strpos( $text, 'Expiry: 2027-01-15 → 2027-02-20' ) !== false && strpos( $text, 'Notes updated.' ) !== false, 'Edit activity describes date, calculated expiry and notes changes' );
	expect_activity( strpos( $text, $editor->display_name . ' (WordPress user #' . $editor_id . ')' ) !== false, 'Activity retains an actor-name and user-ID snapshot' );
	reject_activity( static function () use ( $old, $certificate ) { Store::save_record( $old, $certificate ); }, 'Stale course edit is rejected' );
	reject_activity( static function () use ( $id, $old ) { Store::delete_record( $id, $old['version'] ); }, 'Stale course deletion is rejected' );
	expect_activity( count( activity_logs( $contact_id ) ) === 2, 'Stale mutations leave no misleading activity' );
	Store::save_record( Store::record( $id ), $certificate );
	$logs = activity_logs( $contact_id );
	expect_activity( count( $logs ) === 3 && strpos( activity_text( $logs[2] ), 'Certificate replaced.' ) !== false && (int) $logs[2]['authorid'] === $editor_id, 'Replacing a certificate logs the replacement and responsible user' );
	Store::save_record( array_merge( Store::record( $id ), array( 'course_type_id' => $none_id ) ) );
	$logs = activity_logs( $contact_id ); $text = activity_text( end( $logs ) );
	expect_activity( count( $logs ) === 4 && strpos( $text, $course_name . ' → Induction ' . $suffix ) !== false && strpos( $text, 'Expiry: 2027-02-20 → No expiry' ) !== false, 'Changing course type logs both type and expiry transitions' );

	// Fail the actual CRM INSERT, rather than substituting a fake activity implementation.
	$fail_log = static function ( $query ) use ( $ZBSCRM_t ) {
		return str_replace( 'INSERT INTO `' . $ZBSCRM_t['logs'] . '`', 'INSERT INTO `jpcc_missing_activity_test_table`', $query );
	};
	$prior_errors = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_log );
	$row = Store::record( $id ); $before_records = activity_count( 'records' ); $before_certificates = activity_count( 'certificates' );
	$retry = $data; $retry['submission_token'] = bin2hex( random_bytes( 16 ) );
	reject_activity( static function () use ( $retry, $certificate ) { Store::save_record( $retry, $certificate ); }, 'Creation reports failure when the CRM log cannot be stored' );
	expect_activity( activity_count( 'records' ) === $before_records && activity_count( 'certificates' ) === $before_certificates, 'Failed creation activity rolls back the record and upload' );
	reject_activity( static function () use ( $row, $certificate ) { Store::save_record( array_merge( $row, array( 'notes' => 'Failed update' ) ), $certificate ); }, 'Edit reports failure when the CRM log cannot be stored' );
	expect_activity( Store::record( $id ) === $row && activity_count( 'certificates' ) === $before_certificates && Certificates::get( $id )['data_base64'] === $certificate['data_base64'], 'Failed edit activity preserves the old version, notes and certificate' );
	reject_activity( static function () use ( $id, $row ) { Store::delete_record( $id, $row['version'] ); }, 'Deletion reports failure when the CRM log cannot be stored' );
	expect_activity( Store::record( $id ) === $row && activity_count( 'certificates' ) === $before_certificates && count( activity_logs( $contact_id ) ) === 4, 'Failed deletion preserves the record and certificate without extra activity' );
	remove_filter( 'query', $fail_log ); $wpdb->suppress_errors( $prior_errors );
	$retry_id = Store::save_record( $retry, $certificate );
	expect_activity( $retry_id > 0 && count( activity_logs( $contact_id ) ) === 5, 'Retry after a logging failure creates one record and one activity entry' );

	// An exception after log insertion must also roll back the native log table.
	$fail_commit = static function ( $query ) { if ( 'COMMIT' === $query ) { throw new RuntimeException( 'Injected pre-commit failure' ); } return $query; };
	add_filter( 'query', $fail_commit );
	reject_activity( static function () use ( $row ) { Store::save_record( array_merge( $row, array( 'notes' => 'Uncommitted change' ) ) ); }, 'Failure after native log insertion is reported' );
	remove_filter( 'query', $fail_commit );
	expect_activity( Store::record( $id ) === $row && count( activity_logs( $contact_id ) ) === 5, 'Course change and native activity roll back together' );

	$editor->remove_cap( 'admin_zerobs_customers' ); wp_set_current_user( 0 ); wp_set_current_user( $editor_id );
	reject_activity( static function () use ( $row ) { Store::save_record( array_merge( $row, array( 'notes' => 'Forbidden' ) ) ); }, 'A contact viewer cannot create course activity by attempting an edit' );
	expect_activity( count( activity_logs( $contact_id ) ) === 5, 'Permission failure leaves activity unchanged' );
	wp_set_current_user( 1 );
	Store::delete_record( $id, $row['version'] );
	$logs = activity_logs( $contact_id ); $last = end( $logs );
	expect_activity( ! Store::record( $id ) && count( $logs ) === 6 && $last['shortdesc'] === "Course record deleted (#$id)" && (int) $last['authorid'] === 1, 'Deleting a record retains activity attributed to the deleting user' );
	expect_activity( strpos( activity_text( $last ), 'Induction ' . $suffix ) !== false && strpos( activity_text( $last ), '2026-02-20' ) !== false && strpos( activity_text( $last ), 'No expiry' ) !== false, 'Deletion activity retains the deleted course details' );
	Store::delete_record( $retry_id, Store::record( $retry_id )['version'] );
	// Make the target entry older than the latest item in an abbreviated (>10) timeline.
	$many_logs = array_merge( array( $logs[0] ), array_fill( 0, 11, $last ) );
	expect_activity( strpos( activity_timeline( $contact_id, $many_logs ), "Course record deleted (#$id)" ) !== false, 'Course notes remain eligible for CRM abbreviated timelines' );
	echo "\n$checks activity checks passed.\n";
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" ); exit( 1 );
}
