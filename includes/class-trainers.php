<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

/** Explicit staff-managed eligibility, independent of a contact's course history. */
final class Trainers {
	public static function selected( $contact_id ) {
		global $wpdb;
		CRM::require_contact( $contact_id );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT course_type_id FROM ' . Store::table( 'trainer_courses' ) . ' WHERE contact_id = %d ORDER BY course_type_id', $contact_id ) );
		if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Could not load training eligibility.', 'jpcrm-courses' ) ); }
		return array_map( 'intval', $ids );
	}

	public static function version( $ids ) {
		sort( $ids, SORT_NUMERIC );
		return hash( 'sha256', implode( ',', $ids ) );
	}

	public static function save( $contact_id, $ids, $version ) {
		global $wpdb, $ZBSCRM_t;
		CRM::require_contact( $contact_id, true );
		if ( ! is_array( $ids ) ) { throw new \InvalidArgumentException( __( 'Choose valid course types.', 'jpcrm-courses' ) ); }
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id ) ) { throw new \InvalidArgumentException( __( 'Choose valid course types.', 'jpcrm-courses' ) ); }
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		$table = Store::table( 'trainer_courses' );
		self::query_or_fail( 'START TRANSACTION' );
		try {
			// Serialize even the first save (when there are no eligibility rows to lock).
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$ZBSCRM_t['contacts']} WHERE ID = %d FOR UPDATE", $contact_id ) );
			if ( ! $locked || $wpdb->last_error ) { throw new \RuntimeException( __( 'Could not lock this contact for saving.', 'jpcrm-courses' ) ); }
			$old = self::selected( $contact_id );
			if ( ! is_string( $version ) || ! hash_equals( self::version( $old ), $version ) ) {
				throw new \RuntimeException( __( 'Training eligibility changed. Reload the contact before saving again.', 'jpcrm-courses' ) );
			}
			$types = array_column( Store::types(), null, 'id' );
			foreach ( $ids as $id ) {
				if ( ! isset( $types[ $id ] ) || ( ! $types[ $id ]['active'] && ! in_array( $id, $old, true ) ) ) {
					throw new \InvalidArgumentException( __( 'Choose active course types. Existing archived selections may be retained or removed.', 'jpcrm-courses' ) );
				}
			}
			if ( $ids !== $old ) {
				if ( false === $wpdb->delete( $table, array( 'contact_id' => $contact_id ), array( '%d' ) ) ) { throw new \RuntimeException( __( 'Could not save training eligibility.', 'jpcrm-courses' ) ); }
				foreach ( $ids as $id ) {
					if ( false === $wpdb->insert( $table, array( 'contact_id' => $contact_id, 'course_type_id' => $id ), array( '%d', '%d' ) ) ) { throw new \RuntimeException( __( 'Could not save training eligibility.', 'jpcrm-courses' ) ); }
				}
			}
			self::query_or_fail( 'COMMIT' );
		} catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
	}

	private static function query_or_fail( $sql ) {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) { throw new \RuntimeException( __( 'Database operation failed.', 'jpcrm-courses' ) ); }
	}

	public static function filters( $input ) {
		return array(
			'course_type_id' => absint( Plugin::input( 'course_type_id', '', $input ) ),
			'location' => Plugin::input( 'location', '', $input ),
			'search' => Plugin::input( 'search', '', $input ),
		);
	}

	public static function query( $filters, $page = 1, $per_page = 50 ) {
		global $wpdb, $ZBSCRM_t;
		if ( ! CRM::available() || ! CRM::can_view() ) { throw new \RuntimeException( __( 'CRM access is required.', 'jpcrm-courses' ) ); }
		$trainers = Store::table( 'trainer_courses' );
		$types = Store::table( 'types' );
		$where = array(); $args = array();
		list( $scope, $scope_args ) = CRM::scope();
		if ( $scope ) { $where[] = $scope; $args = array_merge( $args, $scope_args ); }
		$eligible = "EXISTS (SELECT 1 FROM $trainers tc JOIN $types t ON t.id = tc.course_type_id WHERE tc.contact_id = c.ID AND t.active = 1";
		if ( ! empty( $filters['course_type_id'] ) ) { $eligible .= ' AND tc.course_type_id = %d'; $args[] = (int) $filters['course_type_id']; }
		$where[] = $eligible . ')';
		if ( isset( $filters['location'] ) && '' !== $filters['location'] ) {
			$where[] = "CONCAT_WS(' ', NULLIF(c.zbsc_city, ''), NULLIF(c.zbsc_county, ''), NULLIF(c.zbsc_postcode, ''), NULLIF(c.zbsc_country, '')) LIKE %s";
			$args[] = '%' . $wpdb->esc_like( $filters['location'] ) . '%';
		}
		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$where[] = "(CONCAT_WS(' ', c.zbsc_fname, c.zbsc_lname) LIKE %s OR c.zbsc_email LIKE %s)";
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%'; $args[] = $like; $args[] = $like;
		}
		$from = " FROM {$ZBSCRM_t['contacts']} c WHERE " . implode( ' AND ', $where );
		$prepare = static function ( $sql, $params ) use ( $wpdb ) { return $params ? $wpdb->prepare( $sql, $params ) : $sql; };
		$total = (int) $wpdb->get_var( $prepare( 'SELECT COUNT(*)' . $from, $args ) );
		if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Could not load the Trainer register.', 'jpcrm-courses' ) ); }
		$limit = max( 1, min( 100, (int) $per_page ) );
		$pages = (int) ceil( $total / $limit );
		$page = max( 1, min( (int) $page, max( 1, $pages ) ) );
		$fields = 'c.ID AS id, c.zbsc_fname AS fname, c.zbsc_lname AS lname, c.zbsc_email AS email, c.zbsc_city AS city, c.zbsc_county AS county, c.zbsc_postcode AS postcode, c.zbsc_country AS country';
		$rows = $wpdb->get_results( $prepare( 'SELECT ' . $fields . $from . ' ORDER BY c.zbsc_lname, c.zbsc_fname, c.ID LIMIT %d OFFSET %d', array_merge( $args, array( $limit, ( $page - 1 ) * $limit ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Could not load the Trainer register.', 'jpcrm-courses' ) ); }
		$courses = array();
		if ( $rows ) {
			$placeholders = implode( ',', array_fill( 0, count( $rows ), '%d' ) );
			$selections = $wpdb->get_results( $wpdb->prepare( "SELECT tc.contact_id, t.name FROM $trainers tc JOIN $types t ON t.id = tc.course_type_id WHERE t.active = 1 AND tc.contact_id IN ($placeholders) ORDER BY t.name, t.id", array_column( $rows, 'id' ) ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Could not load trainer course types.', 'jpcrm-courses' ) ); }
			foreach ( $selections as $selection ) { $courses[ $selection['contact_id'] ][] = $selection['name']; }
		}
		foreach ( $rows as &$row ) { $row['courses'] = $courses[ $row['id'] ] ?? array(); }
		unset( $row );
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages );
	}

	public static function location( $contact ) {
		$parts = array();
		foreach ( array( 'city', 'county', 'postcode', 'country' ) as $key ) {
			if ( isset( $contact[ $key ] ) && '' !== trim( $contact[ $key ] ) ) { $parts[] = $contact[ $key ]; }
		}
		return implode( ', ', $parts );
	}
}
