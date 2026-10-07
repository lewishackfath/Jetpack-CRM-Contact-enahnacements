<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

/** Native CRM contact notes, written inside the course mutation's transaction. */
final class Activity {
	public static function saved( $record, $old = null ) {
		if ( ! $old ) {
			self::write( __( 'Course record created', 'jpcrm-courses' ), $record, array( __( 'Certificate uploaded.', 'jpcrm-courses' ) ) );
			return;
		}
		$changes = array();
		if ( (int) $old['course_type_id'] !== (int) $record['course_type_id'] ) {
			$changes[] = sprintf( __( 'Course: %1$s → %2$s', 'jpcrm-courses' ), $old['course_name'], $record['course_name'] );
		}
		if ( $old['course_date'] !== $record['course_date'] ) {
			$changes[] = sprintf( __( 'Course date: %1$s → %2$s', 'jpcrm-courses' ), $old['course_date'], $record['course_date'] );
		}
		if ( $old['expires_on'] !== $record['expires_on'] ) {
			$changes[] = sprintf( __( 'Expiry: %1$s → %2$s', 'jpcrm-courses' ), self::expiry( $old ), self::expiry( $record ) );
		}
		if ( $old['notes'] !== $record['notes'] ) {
			$changes[] = __( 'Notes updated.', 'jpcrm-courses' );
		}
		if ( (int) $old['certificate_id'] !== (int) $record['certificate_id'] ) {
			$changes[] = __( 'Certificate replaced.', 'jpcrm-courses' );
		}
		// A save without changes does not clutter the contact's activity feed.
		if ( $changes ) { self::write( __( 'Course record updated', 'jpcrm-courses' ), $record, $changes ); }
	}

	public static function deleted( $record ) {
		self::write( __( 'Course record deleted', 'jpcrm-courses' ), $record, array( __( 'Course record and certificate removed.', 'jpcrm-courses' ) ) );
	}

	private static function expiry( $record ) {
		return $record['expires_on'] ?: __( 'No expiry', 'jpcrm-courses' );
	}

	private static function write( $action, $record, $changes ) {
		global $zbs;
		if ( ! isset( $zbs->DAL->logs ) || ! is_callable( array( $zbs->DAL->logs, 'addUpdateLog' ) ) ) {
			throw new \RuntimeException( __( 'The contact activity log is unavailable. The course change was not saved.', 'jpcrm-courses' ) );
		}
		$user = wp_get_current_user();
		$lines = array(
			sprintf( __( 'Course: %s', 'jpcrm-courses' ), $record['course_name'] ),
			sprintf( __( 'Course date: %s', 'jpcrm-courses' ), $record['course_date'] ),
			sprintf( __( 'Expiry: %s', 'jpcrm-courses' ), self::expiry( $record ) ),
			// Preserve the actor's name and ID even if their WP account changes later.
			sprintf( __( 'Changed by: %1$s (WordPress user #%2$d)', 'jpcrm-courses' ), $user->display_name, $user->ID ),
		);
		$body = '';
		foreach ( array_merge( $lines, $changes ) as $line ) {
			// CRM 6.8.5 decodes entities once before rendering note bodies. Encode
			// text twice so course/user names remain literal text after that decode.
			$body .= '<p>' . htmlspecialchars( htmlspecialchars( $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</p>';
		}
		$id = $zbs->DAL->logs->addUpdateLog( array(
			'id' => -1,
			'owner' => (int) $user->ID,
			'data' => array(
				'objtype' => ZBS_TYPE_CONTACT,
				'objid' => (int) $record['contact_id'],
				// Native notes are included in CRM's abbreviated contact timeline.
				'type' => 'note',
				'shortdesc' => esc_html( sprintf( __( '%1$s (#%2$d)', 'jpcrm-courses' ), $action, $record['id'] ) ),
				'longdesc' => $body,
			),
		) );
		if ( ! $id ) {
			throw new \RuntimeException( __( 'Could not write the contact activity log. The course change was not saved. Please try again.', 'jpcrm-courses' ) );
		}
	}
}
