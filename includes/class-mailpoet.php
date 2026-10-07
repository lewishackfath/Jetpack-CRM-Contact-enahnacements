<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

final class MailPoet {
	const MAX_RECORDS = 5000;
	const BATCH_SIZE = 25;

	public static function available() { return class_exists( '\MailPoet\API\API' ); }
	public static function permitted() {
		return CRM::can_edit() && current_user_can( 'admin_zerobs_sendemails_contacts' )
			&& current_user_can( 'mailpoet_manage_subscribers' ) && current_user_can( 'mailpoet_manage_segments' ) && current_user_can( 'mailpoet_manage_emails' );
	}
	public static function api() {
		if ( ! self::available() || ! self::permitted() ) { throw new \RuntimeException( __( 'MailPoet must be active and you need permission to manage its lists, subscribers and emails, and to email CRM contacts.', 'jpcrm-courses' ) ); }
		return \MailPoet\API\API::MP( 'v1' );
	}

	public static function key( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) { throw new \InvalidArgumentException( __( 'Invalid audience token.', 'jpcrm-courses' ) ); }
		return 'jpcc_job_' . get_current_user_id() . '_' . $token;
	}

	public static function job( $token ) {
		self::api();
		return get_transient( self::key( $token ) );
	}

	/** Per-user lock prevents duplicate form submissions and overlapping AJAX batches. */
	public static function locked( $callback ) {
		global $wpdb;
		$key = 'jpcc_lock_' . get_current_user_id();
		$old = get_option( $key );
		if ( $old && (int) $old < time() - 180 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $old ) );
			wp_cache_delete( $key, 'options' );
		}
		$value = time() . ':' . wp_generate_uuid4();
		if ( ! add_option( $key, $value, '', false ) ) { throw new \RuntimeException( __( 'An audience batch is already running. Wait a moment, then resume.', 'jpcrm-courses' ) ); }
		try { return $callback(); } finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $value ) );
			wp_cache_delete( $key, 'options' );
		}
	}

	public static function start( $token, $name, $record_ids, $filters = null ) {
		$api = self::api();
		$key = self::key( $token );
		$existing = get_transient( $key );
		if ( $existing ) { return $existing; }
		$name = trim( sanitize_text_field( $name ) );
		if ( ! $name || mb_strlen( $name ) > 100 ) { throw new \InvalidArgumentException( __( 'Name your audience using 1–100 characters.', 'jpcrm-courses' ) ); }
		if ( null !== $filters ) {
			$result = Store::query( $filters, 1, self::MAX_RECORDS + 1, true );
			if ( $result['total'] > self::MAX_RECORDS ) { throw new \RuntimeException( __( 'This audience exceeds 5,000 course records. Narrow the expiry range or course filter.', 'jpcrm-courses' ) ); }
			$record_ids = array_column( $result['rows'], 'id' );
		}
		$record_ids = array_values( array_unique( array_filter( array_map( 'absint', $record_ids ) ) ) );
		if ( ! $record_ids || count( $record_ids ) > self::MAX_RECORDS ) { throw new \InvalidArgumentException( __( 'Select between 1 and 5,000 course records.', 'jpcrm-courses' ) ); }
		$contacts = array();
		foreach ( $record_ids as $record_id ) {
			$record = Store::record( $record_id );
			if ( ! $record ) { throw new \RuntimeException( __( 'A selected record no longer exists. Refresh your selection.', 'jpcrm-courses' ) ); }
			CRM::require_contact( $record['contact_id'], true );
			$contacts[ (int) $record['contact_id'] ] = (int) $record['contact_id'];
		}
		// A fresh, uniquely named list prevents recipients from previous mailouts leaking in.
		$list_name = $name . ' · ' . current_time( 'Y-m-d H:i' ) . ' · ' . substr( $token, 0, 8 );
		$list = $api->addList( array( 'name' => $list_name, 'description' => __( 'Snapshot selected from Jetpack CRM course certificates. Review recipients before sending.', 'jpcrm-courses' ) ) );
		if ( empty( $list['id'] ) ) { throw new \RuntimeException( __( 'MailPoet did not return a list ID.', 'jpcrm-courses' ) ); }
		$job = array( 'list_id' => (int) $list['id'], 'name' => $list_name, 'contacts' => array_values( $contacts ), 'offset' => 0,
			'added' => 0, 'skipped' => 0, 'failed' => 0, 'reasons' => array(), 'details' => array(), 'seen' => array(), 'done' => false );
		set_transient( $key, $job, DAY_IN_SECONDS );
		return $job;
	}

	public static function batch( $token ) {
		$api = self::api();
		$key = self::key( $token );
		$job = get_transient( $key );
		if ( ! $job ) { throw new \RuntimeException( __( 'The audience session expired. Its MailPoet list is retained; review it before starting another audience.', 'jpcrm-courses' ) ); }
		if ( $job['done'] ) { return self::summary( $job ); }
		$ids = array_slice( $job['contacts'], $job['offset'], self::BATCH_SIZE );
		foreach ( $ids as $id ) {
			try {
				$contact = CRM::require_contact( $id, true );
				$email = strtolower( trim( $contact['email'] ?? '' ) );
				$reason = '';
				if ( ! is_email( $email ) ) { $reason = __( 'Missing or invalid email', 'jpcrm-courses' ); }
				elseif ( CRM::do_not_email( $id ) ) { $reason = __( 'CRM Do Not Email flag', 'jpcrm-courses' ); }
				elseif ( isset( $job['seen'][ $email ] ) ) { $reason = __( 'Duplicate email address', 'jpcrm-courses' ); }
				else {
					try { $subscriber = $api->getSubscriber( $email ); }
					catch ( \Exception $e ) {
						// MailPoet API code 4 is subscriber-not-found. Other errors are failures, not exclusions.
						if ( 4 !== (int) $e->getCode() ) { throw $e; }
						$subscriber = null;
					}
					if ( ! $subscriber ) { $reason = __( 'Not in MailPoet', 'jpcrm-courses' ); }
					elseif ( ! empty( $subscriber['deleted_at'] ) || 'subscribed' !== ( $subscriber['status'] ?? '' ) ) { $reason = __( 'Not an active MailPoet subscriber', 'jpcrm-courses' ); }
					else {
						// Never create or resubscribe a subscriber as a side effect of preparing a mailout.
						$api->subscribeToLists( $subscriber['id'], array( $job['list_id'] ), array(
							'send_confirmation_email' => false, 'schedule_welcome_email' => false, 'skip_subscriber_notification' => true,
						) );
						$job['seen'][ $email ] = true;
						++$job['added'];
					}
				}
				if ( $reason ) { ++$job['skipped']; self::reason( $job, $id, $reason ); }
			} catch ( \Throwable $e ) {
				++$job['failed'];
				self::reason( $job, $id, __( 'Could not add contact', 'jpcrm-courses' ) . ': ' . sanitize_text_field( $e->getMessage() ) );
			}
			++$job['offset'];
			// Save after each contact so interruption can be resumed with minimal repeated work.
			set_transient( $key, $job, DAY_IN_SECONDS );
		}
		$job['done'] = $job['offset'] >= count( $job['contacts'] );
		set_transient( $key, $job, DAY_IN_SECONDS );
		return self::summary( $job );
	}

	private static function reason( &$job, $id, $reason ) {
		$job['reasons'][ $reason ] = ( $job['reasons'][ $reason ] ?? 0 ) + 1;
		if ( count( $job['details'] ) < 100 ) { $job['details'][] = array( 'contact_id' => $id, 'reason' => $reason ); }
	}

	public static function summary( $job ) {
		return array_intersect_key( $job, array_flip( array( 'list_id', 'name', 'offset', 'added', 'skipped', 'failed', 'reasons', 'details', 'done' ) ) ) + array( 'total' => count( $job['contacts'] ) );
	}
}
