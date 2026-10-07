<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

final class Store {
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'jpcc_' . $name;
	}

	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Course Certificates separately on each site, not network-wide.', 'jpcrm-courses' ) );
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$types = self::table( 'types' );
		$records = self::table( 'records' );
		$certificates = self::table( 'certificates' );
		dbDelta( "CREATE TABLE $types (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			validity_amount int unsigned NOT NULL DEFAULT 1,
			validity_unit varchar(10) NOT NULL DEFAULT 'years',
			active tinyint unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name)
		) ENGINE=InnoDB $charset;" );
		dbDelta( "CREATE TABLE $records (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contact_id bigint(20) unsigned NOT NULL,
			course_type_id bigint(20) unsigned NOT NULL,
			course_date date NOT NULL,
			expires_on date DEFAULT NULL,
			validity_amount int unsigned NOT NULL,
			validity_unit varchar(10) NOT NULL,
			certificate_id bigint(20) unsigned NOT NULL DEFAULT 0,
			notes text NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			version int unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY contact_course (contact_id,course_type_id,course_date),
			KEY course_expiry (course_type_id,expires_on),
			KEY expiry (expires_on)
		) ENGINE=InnoDB $charset;" );
		dbDelta( "CREATE TABLE $certificates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			filename varchar(190) NOT NULL,
			mime varchar(50) NOT NULL,
			file_size bigint(20) unsigned NOT NULL,
			data_base64 longtext NOT NULL,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB $charset;" );
		foreach ( array( $types, $records, $certificates ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new \RuntimeException( __( 'Could not create the course tables. Check database permissions.', 'jpcrm-courses' ) );
			}
		}
		update_option( 'jpcc_schema_version', JPCRM_COURSES_VERSION, false );
	}

	public static function types( $active_only = false ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table( 'types' ) . ( $active_only ? ' WHERE active = 1' : '' ) . ' ORDER BY name', ARRAY_A );
	}

	public static function type( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'types' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public static function save_type( $data ) {
		global $wpdb;
		if ( ! CRM::can_configure() ) { throw new \RuntimeException( __( 'You cannot configure course types.', 'jpcrm-courses' ) ); }
		$name = trim( sanitize_text_field( $data['name'] ?? '' ) );
		if ( ! $name || mb_strlen( $name ) > 190 ) { throw new \InvalidArgumentException( __( 'Enter a course name of 1–190 characters.', 'jpcrm-courses' ) ); }
		list( $amount, $unit ) = Dates::rule( $data['validity_amount'] ?? '', $data['validity_unit'] ?? '' );
		$id = absint( $data['id'] ?? 0 );
		if ( $id && ! self::type( $id ) ) { throw new \RuntimeException( __( 'Course type not found.', 'jpcrm-courses' ) ); }
		$row = array( 'name' => $name, 'validity_amount' => $amount, 'validity_unit' => $unit, 'active' => empty( $data['active'] ) ? 0 : 1 );
		$result = $id ? $wpdb->update( self::table( 'types' ), $row, array( 'id' => $id ) ) : $wpdb->insert( self::table( 'types' ), $row );
		if ( false === $result ) { throw new \RuntimeException( __( 'Could not save the course type. Use a unique name and check database availability.', 'jpcrm-courses' ) ); }
		return $id ?: (int) $wpdb->insert_id;
	}

	public static function record( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT r.*, t.name AS course_name FROM ' . self::table( 'records' ) . ' r JOIN ' . self::table( 'types' ) . ' t ON t.id = r.course_type_id WHERE r.id = %d', $id ), ARRAY_A );
	}

	/** All mutations check contact access, even when called outside the form controller. */
	public static function save_record( $data, $certificate = null ) {
		global $wpdb;
		$id = absint( $data['id'] ?? 0 );
		$old = $id ? self::record( $id ) : null;
		if ( $id && ! $old ) { throw new \RuntimeException( __( 'Course record not found.', 'jpcrm-courses' ) ); }
		$contact_id = $old ? (int) $old['contact_id'] : absint( $data['contact_id'] ?? 0 );
		CRM::require_contact( $contact_id, true );
		$type = self::type( absint( $data['course_type_id'] ?? 0 ) );
		$same_type = $old && (int) $old['course_type_id'] === (int) ( $type['id'] ?? 0 );
		if ( ! $type || ( ! $type['active'] && ! $same_type ) ) { throw new \InvalidArgumentException( __( 'Choose an active course type.', 'jpcrm-courses' ) ); }
		$rule = $same_type ? $old : $type;
		$date = $data['course_date'] ?? '';
		$expiry = Dates::expiry( $date, $rule['validity_amount'], $rule['validity_unit'] );
		$notes = sanitize_textarea_field( $data['notes'] ?? '' );
		if ( mb_strlen( $notes ) > 5000 ) { throw new \InvalidArgumentException( __( 'Notes must be 5,000 characters or fewer.', 'jpcrm-courses' ) ); }
		if ( ! $old && ! $certificate ) { throw new \InvalidArgumentException( __( 'Upload a certificate for this course record.', 'jpcrm-courses' ) ); }
		self::query_or_fail( 'START TRANSACTION' );
		try {
			$certificate_id = $old ? (int) $old['certificate_id'] : 0;
			if ( $certificate ) {
				if ( false === $wpdb->insert( self::table( 'certificates' ), $certificate ) ) { throw new \RuntimeException( __( 'The certificate could not be stored. Check the database packet-size limit.', 'jpcrm-courses' ) ); }
				$certificate_id = (int) $wpdb->insert_id;
			}
			$row = array( 'contact_id' => $contact_id, 'course_type_id' => (int) $type['id'], 'course_date' => $date, 'expires_on' => $expiry,
				'validity_amount' => (int) $rule['validity_amount'], 'validity_unit' => $rule['validity_unit'], 'certificate_id' => $certificate_id,
				'notes' => $notes, 'updated_at' => current_time( 'mysql', true ), 'version' => $old ? (int) $old['version'] + 1 : 1 );
			if ( $old ) {
				$result = $wpdb->update( self::table( 'records' ), $row, array( 'id' => $id, 'version' => absint( $data['version'] ?? 0 ) ) );
				if ( 1 !== $result ) { throw new \RuntimeException( __( 'This record changed or could not be saved. Reload it before trying again.', 'jpcrm-courses' ) ); }
			} else {
				$row['created_by'] = get_current_user_id();
				$row['created_at'] = $row['updated_at'];
				if ( false === $wpdb->insert( self::table( 'records' ), $row ) ) { throw new \RuntimeException( __( 'The course record could not be saved.', 'jpcrm-courses' ) ); }
				$id = (int) $wpdb->insert_id;
			}
			if ( $certificate && $old && $old['certificate_id'] ) {
				if ( false === $wpdb->delete( self::table( 'certificates' ), array( 'id' => $old['certificate_id'] ) ) ) { throw new \RuntimeException( __( 'Could not replace the previous certificate.', 'jpcrm-courses' ) ); }
			}
			self::query_or_fail( 'COMMIT' );
		} catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
		return $id;
	}

	public static function delete_record( $id, $version ) {
		global $wpdb;
		$row = self::record( $id );
		if ( ! $row ) { throw new \RuntimeException( __( 'Course record not found.', 'jpcrm-courses' ) ); }
		CRM::require_contact( $row['contact_id'], true );
		self::query_or_fail( 'START TRANSACTION' );
		try {
			if ( 1 !== $wpdb->delete( self::table( 'records' ), array( 'id' => $id, 'version' => $version ) ) ) { throw new \RuntimeException( __( 'Record changed. Reload before deleting it.', 'jpcrm-courses' ) ); }
			if ( false === $wpdb->delete( self::table( 'certificates' ), array( 'id' => $row['certificate_id'] ) ) ) { throw new \RuntimeException( __( 'Certificate deletion failed.', 'jpcrm-courses' ) ); }
			self::query_or_fail( 'COMMIT' );
		} catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
	}

	private static function query_or_fail( $sql ) {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) { throw new \RuntimeException( __( 'Database operation failed.', 'jpcrm-courses' ) ); }
	}

	public static function query( $filters, $page = 1, $per_page = 50, $ids_only = false ) {
		global $wpdb, $ZBSCRM_t;
		if ( ! CRM::available() || ! CRM::can_view() ) { throw new \RuntimeException( __( 'CRM access is required.', 'jpcrm-courses' ) ); }
		$records = self::table( 'records' );
		$types = self::table( 'types' );
		$contacts = $ZBSCRM_t['contacts'];
		$where = array( '1=1' ); $args = array();
		list( $scope, $scope_args ) = CRM::scope();
		if ( $scope ) { $where[] = $scope; $args = array_merge( $args, $scope_args ); }
		foreach ( array( 'contact_id', 'course_type_id' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) { $where[] = 'r.' . $key . ' = %d'; $args[] = $filters[ $key ]; }
		}
		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $key => $operator ) {
			if ( ! empty( $filters[ $key ] ) ) { $where[] = 'r.expires_on ' . $operator . ' %s'; $args[] = $filters[ $key ]; }
		}
		$today = current_time( 'Y-m-d' );
		switch ( $filters['status'] ?? '' ) {
			case 'expired': $where[] = 'r.expires_on < %s'; $args[] = $today; break;
			case 'current': $where[] = '(r.expires_on >= %s OR r.expires_on IS NULL)'; $args[] = $today; break;
			case 'soon': $where[] = 'r.expires_on BETWEEN %s AND %s'; $args[] = $today; $args[] = Dates::parse( $today )->modify( '+30 days' )->format( 'Y-m-d' ); break;
			case 'none': $where[] = 'r.expires_on IS NULL'; break;
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[] = "(CONCAT(c.zbsc_fname, ' ', c.zbsc_lname) LIKE %s OR c.zbsc_email LIKE %s)";
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%'; $args[] = $like; $args[] = $like;
		}
		// Compare against all history, before expiry filters, so a renewal suppresses the old certificate.
		if ( ! empty( $filters['latest'] ) ) {
			$where[] = "NOT EXISTS (SELECT 1 FROM $records newer WHERE newer.contact_id = r.contact_id AND newer.course_type_id = r.course_type_id AND (newer.course_date > r.course_date OR (newer.course_date = r.course_date AND newer.id > r.id)))";
		}
		$from = " FROM $records r JOIN $types t ON t.id = r.course_type_id JOIN $contacts c ON c.ID = r.contact_id WHERE " . implode( ' AND ', $where );
		$prepare = static function ( $sql, $params ) use ( $wpdb ) { return $params ? $wpdb->prepare( $sql, $params ) : $sql; };
		$total = (int) $wpdb->get_var( $prepare( 'SELECT COUNT(*)' . $from, $args ) );
		$fields = $ids_only ? 'r.id, r.contact_id' : 'r.*, t.name AS course_name, c.zbsc_fname AS fname, c.zbsc_lname AS lname, c.zbsc_email AS email';
		$limit = max( 1, min( 5001, (int) $per_page ) );
		$page = max( 1, min( (int) $page, max( 1, (int) ceil( $total / $limit ) ) ) );
		$sql = 'SELECT ' . $fields . $from . ' ORDER BY r.expires_on IS NULL, r.expires_on, r.id LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $prepare( $sql, array_merge( $args, array( $limit, ( $page - 1 ) * $limit ) ) ), ARRAY_A );
		if ( $wpdb->last_error ) { throw new \RuntimeException( __( 'Could not load course records.', 'jpcrm-courses' ) ); }
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / $limit ) );
	}
}
