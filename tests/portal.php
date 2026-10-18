<?php
/** Real CRM portal integration and HTTP regressions. Run only on a disposable localhost site. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'JPCC_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set JPCC_TEST_WP_ROOT.\n" ); exit( 1 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8775';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $root . '/wp-load.php';
if ( ! defined( 'JPCC_INTEGRATION_TESTS' ) || true !== JPCC_INTEGRATION_TESTS || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { exit( 1 ); }

use JPCRM_Courses\Portal;
use JPCRM_Courses\Store;
use JPCRM_Courses\CRM;
use JPCRM_Courses\Certificates;

$checks = 0;
$sessions = array();
function portal_check( $ok, $label ) {
	global $checks;
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks; echo 'PASS: ' . $label . PHP_EOL;
}
function portal_rejects( $callback, $label ) {
	try { $callback(); } catch ( Throwable $e ) { portal_check( true, $label ); return; }
	portal_check( false, $label );
}
function portal_session( $id ) {
	global $sessions;
	wp_set_current_user( $id );
	$token = WP_Session_Tokens::get_instance( $id )->create( time() + 1800 );
	$sessions[] = array( $id, $token );
	$cookie = wp_generate_auth_cookie( $id, time() + 1800, 'logged_in', $token );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	return LOGGED_IN_COOKIE . '=' . $cookie . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie( $id, time() + 1800, 'auth', $token );
}
function portal_request( $url, $cookie = '', $post = null ) {
	$headers = array();
	$curl = curl_init( html_entity_decode( $url ) );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cookie, CURLOPT_TIMEOUT => 20,
		CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$headers ) { $headers[] = trim( $line ); return strlen( $line ); } ) );
	if ( null !== $post ) { curl_setopt( $curl, CURLOPT_POSTFIELDS, http_build_query( $post ) ); }
	$body = curl_exec( $curl );
	if ( false === $body ) { throw new RuntimeException( curl_error( $curl ) ); }
	$status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	return array( $status, $body, implode( "\n", $headers ) );
}

wp_set_current_user( 1 );
global $zbs, $wpdb, $zbsCustomerFields;
$settings = array();
foreach ( array( 'portal_readonlyfields', 'portal_hidefields', 'customfields', 'feat_portal' ) as $key ) { $settings[ $key ] = $zbs->settings->get( $key ); }
$custom_settings = $zbs->DAL->getActiveCustomFields( array( 'objtypeid' => ZBS_TYPE_CONTACT ) );
try {
	portal_check( CRM::can_edit() && Portal::available(), 'Real CRM staff permissions and active portal' );
	Store::install();
	$suffix = substr( wp_generate_uuid4(), 0, 8 );
	$custom = $settings['customfields'];
	$custom['customers'][] = array( 'text', 'Training identifier', '', 'jpcc-training-id' );
	$custom['customers'][] = array( 'checkbox', 'Training options', 'Alpha,Beta', 'jpcc-training-options' );
	$zbs->settings->update( 'customfields', $custom );
	$custom_by_key = (array) $custom_settings;
	$custom_by_key['jpcc-training-id'] = array( 'text', 'Training identifier', '', 'jpcc-training-id' );
	$custom_by_key['jpcc-training-options'] = array( 'checkbox', 'Training options', 'Alpha,Beta', 'jpcc-training-options' );
	$zbs->DAL->updateActiveCustomFields( array( 'objtypeid' => ZBS_TYPE_CONTACT, 'fields' => $custom_by_key ) );
	$zbs->DAL->getActiveCustomFields( array( 'objtypeid' => ZBS_TYPE_CONTACT, 'accept_cached' => false ) );
	zeroBSCRM_unpackCustomFields();
	portal_check( isset( Portal::field_choices()['jpcc-training-id'], Portal::field_choices()['jpcc-training-options'] ), 'Field picker discovers real CRM custom fields' );
	$contacts = array(); $users = array();
	foreach ( array( 'Owner', 'Other', 'Empty' ) as $name ) {
		$email = strtolower( $name ) . '-' . $suffix . '@example.test';
		$contacts[] = $zbs->DAL->contacts->addUpdateContact( array( 'data' => array( 'fname' => $name, 'lname' => 'Portal test', 'email' => $email, 'status' => 'Customer', 'hometel' => '1111', 'secaddr1' => 'Original second address', 'jpcc-training-id' => 'STAFF-123', 'jpcc-training-options' => 'Alpha' ) ) );
		$users[] = wp_insert_user( array( 'user_login' => strtolower( $name ) . '-' . $suffix, 'user_email' => $email, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	}
	$type = Store::save_type( array( 'name' => 'First Aid ' . $suffix . ' & refresher', 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 1 ) );
	$pdf = "%PDF-1.4\n1 0 obj<</Type /Catalog>>endobj\n%%EOF\n";
	$certificate = array( 'filename' => 'certificate.pdf', 'mime' => 'application/pdf', 'file_size' => strlen( $pdf ), 'data_base64' => base64_encode( $pdf ) );
	$records = array();
	foreach ( $contacts as $index => $contact ) {
		if ( 2 === $index ) { continue; }
		$records[] = Store::save_record( array( 'contact_id' => $contact, 'course_type_id' => $type, 'course_date' => '2026-10-01', 'notes' => 'STAFF-PRIVATE-NOTE' ), $certificate );
	}
	for ( $i = 0; $i < 20; ++$i ) { Store::save_record( array( 'contact_id' => $contacts[0], 'course_type_id' => $type, 'course_date' => '2025-01-01' ), $certificate ); }
	$admin_cookie = portal_session( 1 );
	$post_url = admin_url( 'admin-post.php' );
	list( $status, $body, $headers ) = portal_request( $post_url, $admin_cookie, array( 'action' => 'jpcc_save_portal', 'readonly_fields' => array( 'fname' ) ) );
	if ( 403 !== $status ) { fwrite( STDERR, $status . '\n' . $headers . '\n' . substr( strip_tags( $body ), 0, 300 ) . '\n' ); }
	portal_check( 403 === $status, 'Portal settings reject missing CSRF token' );
	list( $status ) = portal_request( $post_url, $admin_cookie, array( 'action' => 'jpcc_save_portal', '_wpnonce' => wp_create_nonce( 'jpcc_save_portal' ), 'readonly_fields' => array( 'fname', 'secaddr1', 'jpcc-training-id', 'jpcc-training-options' ) ) );
	portal_check( 303 === $status, 'Staff saves read-only fields through settings controller' );
	portal_check( in_array( 'fname', Portal::readonly_keys(), true ), 'Read-only selection persisted in native CRM setting' );
	portal_rejects( function () { Portal::update_readonly( array( array( 'fname' ) ) ); }, 'Nested settings input rejected' );
	portal_rejects( function () { Portal::update_readonly( array( 'not-a-field' ) ); }, 'Unknown field rejected' );
	$portal_url = jpcrm_get_client_portal_root_url() . Portal::SLUG;
	$details_url = jpcrm_get_client_portal_root_url() . 'details';
	$cookie = portal_session( $users[0] );
	portal_check( ! CRM::can_view() && Portal::contact_id() === (int) $contacts[0], 'Subscriber resolves only their own CRM contact' );
	portal_check( count( Portal::records()['rows'] ) === 20 && count( Portal::records( 2 )['rows'] ) === 1, 'Customer history paginates without losing older training' );
	portal_check( Portal::records( 999999 )['page'] === 2, 'Pagination bounds are clamped' );
	portal_check( Certificates::get_for_portal( $records[0] )['data_base64'] === base64_encode( $pdf ), 'Customer retrieves their own certificate' );
	portal_rejects( function () use ( $records ) { Certificates::get_for_portal( $records[1] ); }, 'Customer cannot retrieve another contact certificate' );
	portal_rejects( function () use ( $records ) { Certificates::get( $records[0] ); }, 'Portal access does not grant staff certificate access' );
	portal_rejects( function () { Portal::update_readonly( array() ); }, 'Customer cannot change read-only policy' );
	list( $status, $body, $headers ) = portal_request( add_query_arg( 'contact_id', $contacts[1], $portal_url ), $cookie );
	portal_check( 200 === $status && false !== strpos( $body, 'Training &amp; certificates' ) && false !== strpos( $body, 'Page 1 of 2' ), 'Native portal endpoint renders customer training and pagination' );
	portal_check( false === strpos( $body, 'STAFF-PRIVATE-NOTE' ) && false === strpos( $body, 'jpcc_save_record' ) && false === strpos( $body, 'jpcc_delete_record' ) && false === strpos( $body, 'type="file"' ), 'Customer page excludes internal notes and mutation controls' );
	portal_check( false === strpos( $body, 'jpcc_certificate=' . $records[1] . '&' ) && false !== strpos( $body, '&amp; refresher' ), 'Requested contact override is ignored and course names are escaped' );
	portal_check( false !== strpos( $headers, 'Cache-Control: private, no-store, max-age=0' ), 'Portal page prevents shared caching' );
	list( $status, $body, $headers ) = portal_request( Portal::certificate_url( $records[0], true ), $cookie );
	portal_check( 200 === $status && $body === $pdf && false !== strpos( $headers, 'Content-Disposition: attachment;' ) && false !== strpos( $headers, 'Cache-Control: private, no-store' ), 'Customer download delivers exact private attachment bytes' );
	list( $status, $body, $headers ) = portal_request( Portal::certificate_url( $records[0] ), $cookie );
	portal_check( 200 === $status && $body === $pdf && false !== strpos( $headers, 'Content-Disposition: inline;' ), 'Customer can view their certificate inline' );
	list( $status, $body ) = portal_request( Portal::certificate_url( $records[1] ), $cookie );
	portal_check( 403 === $status && $body !== $pdf, 'Valid nonce cannot authorize a different contact certificate' );
	list( $status ) = portal_request( add_query_arg( 'jpcc_certificate', $records[0], home_url( '/' ) ), $cookie );
	portal_check( 403 === $status, 'Customer download requires nonce' );
	list( $status, $body ) = portal_request( Portal::certificate_url( $records[0] ) );
	portal_check( $status >= 400 && $body !== $pdf, 'Anonymous certificate download denied' );
	foreach ( array( 'save_record', 'delete_record' ) as $action ) {
		$nonce = 'delete_record' === $action ? 'jpcc_delete_record_' . $records[0] : 'jpcc_save_record';
		list( $status ) = portal_request( $post_url, $cookie, array( 'action' => 'jpcc_' . $action, '_wpnonce' => wp_create_nonce( $nonce ), 'id' => $records[0], 'contact_id' => $contacts[0], 'version' => 1 ) );
		portal_check( 403 === $status, 'Customer cannot ' . $action . ' even with valid nonce' );
	}
	list( $status ) = portal_request( $post_url, $cookie, array( 'action' => 'jpcc_save_record', '_wpnonce' => wp_create_nonce( 'jpcc_save_record' ), 'id' => 0, 'contact_id' => $contacts[0] ) );
	portal_check( 403 === $status, 'Customer cannot create certificates' );
	list( $status ) = portal_request( $post_url, $cookie, array( 'action' => 'jpcc_save_portal', '_wpnonce' => wp_create_nonce( 'jpcc_save_portal' ) ) );
	portal_check( 403 === $status, 'Customer cannot submit portal settings' );
	( new WP_User( $users[0] ) )->set_role( 'zerobs_customer' );
	wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
	list( $status, $body ) = portal_request( Portal::certificate_url( $records[0], true ), $cookie );
	portal_check( 200 === $status && $body === $pdf, 'Native CRM customer role can download without wp-admin access' );
	list( $status, $body ) = portal_request( $details_url, $cookie );
	$dom = new DOMDocument(); @$dom->loadHTML( $body ); $xpath = new DOMXPath( $dom );
	portal_check( 200 === $status && $xpath->query( '//input[@name="zbsc_fname" and @disabled]' )->length === 1 && $xpath->query( '//input[@name="zbsc_jpcc-training-id" and @disabled]' )->length === 1, 'Native details form disables selected built-in and custom fields' );
	$post = array( 'save' => 1, 'customer_id' => $contacts[0], '_wpnonce' => wp_create_nonce( 'jpcrm-update-client-details' ), 'zbsc_fname' => 'Forged', 'zbsc_secaddr1' => 'Forged address', 'zbsc_jpcc-training-id' => 'Forged ID', 'zbsc_jpcc-training-options-1' => 'Beta', 'zbsc_hometel' => '2222' );
	list( $status ) = portal_request( $details_url, $cookie, $post );
	$contact = $zbs->DAL->contacts->getContact( $contacts[0] );
	portal_check( 200 === $status && 'Owner' === $contact['fname'] && 'Original second address' === $contact['secaddr_addr1'] && 'STAFF-123' === $contact['jpcc-training-id'] && 'Alpha' === $contact['jpcc-training-options'], 'Forged POST cannot change read-only built-in, address, custom text or checkbox values' );
	portal_check( '2222' === $contact['hometel'], 'Unlocked fields still save through the real portal' );
	unset( $post['zbsc_fname'], $post['zbsc_secaddr1'], $post['zbsc_jpcc-training-id'], $post['zbsc_jpcc-training-options-1'] );
	$post['zbsc_hometel'] = '3333'; portal_request( $details_url, $cookie, $post );
	$contact = $zbs->DAL->contacts->getContact( $contacts[0] );
	portal_check( 'Owner' === $contact['fname'] && 'STAFF-123' === $contact['jpcc-training-id'] && 'Alpha' === $contact['jpcc-training-options'] && '3333' === $contact['hometel'], 'Disabled fields omitted by browser retain their values' );
	$zbs->DAL->updateMeta( ZBS_TYPE_CONTACT, $contacts[0], 'portal_disabled', true );
	list( $status, $body ) = portal_request( Portal::certificate_url( $records[0] ), $cookie );
	portal_check( 403 === $status && $body !== $pdf, 'Disabled portal contact cannot use an existing certificate link' );
	$zbs->DAL->updateMeta( ZBS_TYPE_CONTACT, $contacts[0], 'portal_disabled', false );
	$empty_cookie = portal_session( $users[2] );
	list( $status, $body ) = portal_request( $portal_url, $empty_cookie );
	portal_check( 200 === $status && false !== strpos( $body, 'No training records are available yet.' ), 'Customer without training sees an empty state' );
	wp_set_current_user( 1 );
	$zbs->DAL->contacts->addUpdateContact( array( 'id' => $contacts[0], 'data' => array( 'fname' => 'Staff edit' ), 'do_not_update_blanks' => true ) );
	portal_check( 'Staff edit' === $zbs->DAL->contacts->getContact( $contacts[0] )['fname'], 'Read-only portal policy does not block staff edits' );
	$zbs->settings->update( 'portal_readonlyfields', 'extension-unknown,jpcrm-second-address,fname' );
	Portal::update_readonly( array_values( array_intersect( Portal::readonly_keys(), array_keys( Portal::field_choices() ) ) ) );
	portal_check( in_array( 'extension-unknown', Portal::readonly_keys(), true ) && in_array( 'secaddr1', Portal::readonly_keys(), true ) && ! in_array( 'jpcrm-second-address', Portal::readonly_keys(), true ), 'Preserves unknown extension rules and expands existing Pro address groups' );
	Portal::update_readonly( array() );
	portal_check( ! in_array( 'fname', Portal::readonly_keys(), true ), 'Staff can unlock fields again' );
	$disabled = $zbs->modules->portal; unset( $zbs->modules->portal );
	portal_check( ! Portal::available(), 'Integration detects inactive portal module' );
	portal_rejects( function () use ( $records ) { Certificates::get_for_portal( $records[0] ); }, 'Inactive portal denies certificate access' );
	$zbs->modules->portal = $disabled;
	$cookie = portal_session( $users[0] );
	list( $status, $body ) = portal_request( Portal::certificate_url( $records[0] ), $cookie );
	portal_check( 200 === $status && $body === $pdf, 'Customer session and certificate remain valid before deactivating portal' );
	wp_set_current_user( 1 );
	$zbs->settings->update( 'feat_portal', -1 );
	wp_set_current_user( $users[0] );
	list( $status, $body ) = portal_request( Portal::certificate_url( $records[0] ), $cookie );
	portal_check( 403 === $status && $body !== $pdf, 'Fresh request rejects certificate when portal extension is deactivated' );
	echo "$checks portal checks passed.\n";
} catch ( Throwable $e ) { fwrite( STDERR, $e->getMessage() . PHP_EOL ); $failed = true; }
finally {
	wp_set_current_user( 1 );
	foreach ( $settings as $key => $value ) { $zbs->settings->update( $key, $value ); }
	$zbs->DAL->updateActiveCustomFields( array( 'objtypeid' => ZBS_TYPE_CONTACT, 'fields' => $custom_settings ) );
	foreach ( $sessions as $session ) { WP_Session_Tokens::get_instance( $session[0] )->destroy( $session[1] ); }
}
exit( empty( $failed ) ? 0 : 1 );
