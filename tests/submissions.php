<?php
/** Real database concurrency regression test; use only the marked disposable site. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'JPCC_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set JPCC_TEST_WP_ROOT.\n" ); exit( 1 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8775'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( ! defined( 'JPCC_INTEGRATION_TESTS' ) || true !== JPCC_INTEGRATION_TESTS || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit( 1 ); }
wp_set_current_user( 1 );
use JPCRM_Courses\Store;
use JPCRM_Courses\Certificates;
use JPCRM_Courses\Admin;

if ( 'worker' === ( $argv[1] ?? '' ) ) {
	$directory = $argv[2]; $worker = (int) $argv[3];
	$data = json_decode( file_get_contents( $directory . '/data.json' ), true );
	add_filter( 'query', static function ( $query ) use ( $directory, $worker ) {
		if ( 0 === strpos( $query, 'INSERT INTO `' . Store::table( 'records' ) . '`' ) ) {
			file_put_contents( $directory . '/ready-' . $worker, 'ready' );
			$deadline = microtime( true ) + 10;
			while ( count( glob( $directory . '/ready-*' ) ) < 2 ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Concurrency barrier timed out.' ); }
				usleep( 10000 );
			}
		}
		return $query;
	} );
	try { echo Store::save_record( $data, Certificates::read_validated( $directory . '/certificate.pdf', 'certificate.pdf' ) ); }
	catch ( Throwable $e ) { fwrite( STDERR, $e->getMessage() ); exit( 1 ); }
	exit;
}

$checks = 0;
function expect_submission( $ok, $message ) {
	global $checks;
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	++$checks; echo "PASS: $message\n";
}
$directory = sys_get_temp_dir() . '/jpcc-submission-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory, 0700 );
$created = array(); $workers = array();
try {
	Store::install();
	$source = $wpdb->get_row( 'SELECT r.contact_id, r.course_type_id FROM ' . Store::table( 'records' ) . ' r JOIN ' . Store::table( 'types' ) . ' t ON t.id = r.course_type_id WHERE t.active = 1 LIMIT 1', ARRAY_A );
	if ( ! $source ) { throw new RuntimeException( 'Run integration.php to create test contacts and courses first.' ); }
	$data = $source + array( 'course_date' => '2026-10-07', 'submission_token' => bin2hex( random_bytes( 16 ) ) );
	file_put_contents( $directory . '/data.json', wp_json_encode( $data ) );
	file_put_contents( $directory . '/certificate.pdf', "%PDF-1.4\n1 0 obj<</Type /Catalog>>endobj\n%%EOF\n" );
	$certificate = Certificates::read_validated( $directory . '/certificate.pdf', 'certificate.pdf' );
	$before_certificates = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( 'certificates' ) );
	$before_logs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $ZBSCRM_t['logs'] );
	for ( $i = 0; $i < 2; ++$i ) {
		$workers[] = proc_open( array( PHP_BINARY, __FILE__, 'worker', $directory, (string) $i ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $directory . '/out-' . $i, 'w' ), 2 => array( 'file', $directory . '/err-' . $i, 'w' ) ), $pipes );
		fclose( $pipes[0] );
	}
	foreach ( $workers as $i => $process ) { expect_submission( 0 === proc_close( $process ), 'Concurrent request ' . ( $i + 1 ) . ' completes successfully: ' . file_get_contents( $directory . '/err-' . $i ) ); }
	$id = (int) file_get_contents( $directory . '/out-0' ); $created[] = $id;
	expect_submission( $id > 0 && $id === (int) file_get_contents( $directory . '/out-1' ), 'Simultaneous saves return the same record ID' );
	$key = hash( 'sha256', '1:' . $data['submission_token'] );
	expect_submission( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::table( 'records' ) . ' WHERE submission_key = %s', $key ) ), 'Only one record is committed for the submission token' );
	expect_submission( $before_certificates + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( 'certificates' ) ), 'The duplicate upload is rolled back without an orphan certificate' );
	expect_submission( $id === Store::save_record( $data, null ), 'A later retry returns the saved record even without another upload' );
	expect_submission( $before_logs + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $ZBSCRM_t['logs'] ), 'Simultaneous saves and subsequent retries commit only one native contact activity entry' );
	$next = $data; $next['submission_token'] = bin2hex( random_bytes( 16 ) );
	$new_id = Store::save_record( $next, $certificate ); $created[] = $new_id;
	expect_submission( $new_id !== $id, 'A fresh form can intentionally add another course for the same date' );
	try { Store::save_record( array_merge( $data, array( 'submission_token' => '' ) ), $certificate ); $rejected = false; }
	catch ( InvalidArgumentException $e ) { $rejected = true; }
	expect_submission( $rejected, 'A missing or malformed submission token is rejected' );
	$_GET = array( 'page' => 'jpcc-course-types' );
	ob_start(); Admin::page(); $html = ob_get_clean();
	expect_submission( false !== strpos( $html, '<h1>Course types</h1>' ) && false === strpos( $html, 'nav-tab-wrapper' ), 'Course types has its own page without register/type tabs' );
	$_GET = array( 'page' => 'jpcc-courses', 'contact_id' => $source['contact_id'] );
	ob_start(); Admin::page(); $html = ob_get_clean();
	expect_submission( false !== strpos( $html, '<h1>Course register</h1>' ) && false !== strpos( $html, '<h2>Send an Email</h2>' ), 'Register has the requested page and email headings' );
	$_GET = array( 'jpcc_record' => 'new' );
	ob_start(); Admin::contact_content( $source['contact_id'] ); $html = ob_get_clean();
	expect_submission( false !== strpos( $html, 'name="submission_token"' ) && false !== strpos( $html, 'data-jpcc-dropzone' ), 'Contact form includes a submission token and certificate drop zone' );
	echo "\n$checks submission and page checks passed.\n";
} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); $failed = true; }
finally {
	foreach ( $created as $id ) { if ( $id && Store::record( $id ) ) { Store::delete_record( $id, 1 ); } }
	foreach ( glob( $directory . '/*' ) as $file ) { unlink( $file ); }
	rmdir( $directory );
}
exit( empty( $failed ) ? 0 : 1 );
