<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function url( $args = array() ) {
		if ( 'types' === ( $args['view'] ?? '' ) ) { $args['page'] = 'jpcc-course-types'; unset( $args['view'] ); }
		return add_query_arg( array_merge( array( 'page' => 'jpcc-courses' ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		if ( ! CRM::available() ) { return; }
		add_menu_page( __( 'Courses', 'jpcrm-courses' ), __( 'Courses', 'jpcrm-courses' ), 'admin_zerobs_view_customers', 'jpcc-courses', array( __CLASS__, 'page' ), 'dashicons-welcome-learn-more', 58 );
		add_submenu_page( 'jpcc-courses', __( 'Course register', 'jpcrm-courses' ), __( 'Course register', 'jpcrm-courses' ), 'admin_zerobs_view_customers', 'jpcc-courses', array( __CLASS__, 'page' ) );
		add_submenu_page( 'jpcc-courses', __( 'Course types', 'jpcrm-courses' ), __( 'Course types', 'jpcrm-courses' ), 'admin_zerobs_manage_options', 'jpcc-course-types', array( __CLASS__, 'page' ) );
	}

	public static function crm_menu( $items ) {
		if ( CRM::can_view() ) { $items[] = '<a class="item" href="' . esc_url( self::url() ) . '"><i class="icon graduation cap"></i>' . esc_html__( 'Course certificates', 'jpcrm-courses' ) . '</a>'; }
		return $items;
	}

	public static function assets() {
		$page = Plugin::input( 'page', '', $_GET );
		if ( ! in_array( $page, array( 'jpcc-courses', 'jpcc-course-types', 'zbs-add-edit' ), true ) ) { return; }
		wp_enqueue_style( 'jpcc-admin', plugins_url( 'assets/admin.css', JPCRM_COURSES_FILE ), array(), JPCRM_COURSES_VERSION );
		wp_enqueue_script( 'jpcc-admin', plugins_url( 'assets/admin.js', JPCRM_COURSES_FILE ), array( 'jquery' ), JPCRM_COURSES_VERSION, true );
		wp_localize_script( 'jpcc-admin', 'jpccAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'jpcc_audience_batch' ),
			'confirmDelete' => __( 'Delete this course record and its certificate? This cannot be undone.', 'jpcrm-courses' ),
			'saving' => __( 'Saving…', 'jpcrm-courses' ),
			'fileError' => __( 'Choose one PDF, JPEG or PNG file, up to 5 MB.', 'jpcrm-courses' ),
			'dropUnavailable' => __( 'Please use Choose file in this browser.', 'jpcrm-courses' ),
			'noFile' => __( 'No file selected.', 'jpcrm-courses' ),
			'clearFile' => __( 'Clear selection', 'jpcrm-courses' ),
			'progress' => __( 'Processed %1$s of %2$s contacts. Added: %3$s. Skipped: %4$s. Failed: %5$s.', 'jpcrm-courses' ),
			'error' => __( 'The batch could not finish. Resume to continue from the last saved contact.', 'jpcrm-courses' ) ) );
	}

	public static function contact_tab( $tabs, $contact_id ) {
		if ( ! CRM::contact( $contact_id ) ) { return $tabs; }
		$tabs[] = array( 'id' => 'jpcc-courses', 'name' => __( 'Courses & certificates', 'jpcrm-courses' ), 'contentaction' => array( __CLASS__, 'contact_content' ) );
		return $tabs;
	}

	public static function contact_content( $contact_id ) {
		echo '<div class="jpcc">';
		try {
			CRM::require_contact( $contact_id );
			$edit = Plugin::input( 'jpcc_record', '', $_GET );
			if ( $edit ) {
				self::record_page( $contact_id, 'new' === $edit ? 0 : absint( $edit ) );
				echo '</div>'; return;
			}
			$result = Store::query( array( 'contact_id' => $contact_id ), 1, 20 );
			if ( Plugin::input( 'jpcc_saved', '', $_GET ) ) { echo '<div class="jpcc-success" role="status"><p>' . esc_html__( 'Course record saved.', 'jpcrm-courses' ) . '</p></div>'; }
			if ( Plugin::input( 'jpcc_deleted', '', $_GET ) ) { echo '<div class="jpcc-success" role="status"><p>' . esc_html__( 'Course record and certificate deleted.', 'jpcrm-courses' ) . '</p></div>'; }
			echo '<h3>' . esc_html__( 'Course history', 'jpcrm-courses' ) . '</h3><p>';
			if ( CRM::can_edit() ) { self::button( __( 'Add course date', 'jpcrm-courses' ), CRM::courses_link( $contact_id, array( 'jpcc_record' => 'new' ) ), true ); }
			self::button( __( 'View all course records', 'jpcrm-courses' ), self::url( array( 'contact_id' => $contact_id ) ) );
			echo '</p>';
			self::table( $result['rows'], false );
			if ( $result['total'] > 20 ) { echo '<p>' . esc_html__( 'Showing 20 records. Open all course records to see the full history.', 'jpcrm-courses' ) . '</p>'; }
			echo '</div>';
		} catch ( \Throwable $e ) { echo '<div class="jpcc-error" role="alert"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>'; }
	}

	public static function page() {
		if ( ! CRM::available() || ! CRM::can_view() ) { wp_die( esc_html__( 'CRM contact access is required.', 'jpcrm-courses' ) ); }
		$view = 'jpcc-course-types' === Plugin::input( 'page', '', $_GET ) ? 'types' : Plugin::input( 'view', '', $_GET );
		$titles = array( 'types' => __( 'Course types', 'jpcrm-courses' ), 'record' => __( 'Course record', 'jpcrm-courses' ), 'audience' => __( 'Send an Email', 'jpcrm-courses' ) );
		echo '<div class="wrap jpcc"><h1>' . esc_html( $titles[ $view ] ?? __( 'Course register', 'jpcrm-courses' ) ) . '</h1><p class="jpcc-intro">' . esc_html__( 'Course history, renewal dates and the people who need a reminder.', 'jpcrm-courses' ) . '</p>';
		if ( Plugin::input( 'saved', '', $_GET ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved successfully.', 'jpcrm-courses' ) . '</p></div>'; }
		if ( Plugin::input( 'deleted', '', $_GET ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Course record and certificate deleted.', 'jpcrm-courses' ) . '</p></div>'; }
		try {
			switch ( $view ) {
				case 'types': self::types_page(); break;
				case 'record': self::record_page(); break;
				case 'audience': self::audience_page(); break;
				default: self::register_page();
			}
		} catch ( \Throwable $e ) { self::error( $e->getMessage() ); }
		echo '</div>';
	}

	public static function button( $label, $url, $primary = false ) {
		echo '<a class="button ' . ( $primary ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
	}
	public static function error( $message ) { echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>'; }
	public static function field( $label, $name, $value = '', $type = 'text', $attributes = '' ) {
		echo '<label class="jpcc-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . $attributes . '></label>';
	}
	public static function hidden( $name, $value ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
	public static function select( $label, $name, $value, $options ) {
		echo '<label class="jpcc-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></label>';
	}
	public static function post_form( $action, $nonce ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="jpcc-form">';
		self::hidden( 'action', 'jpcc_' . $action );
		wp_nonce_field( $nonce );
	}

	public static function register_page() {
		$input = wp_unslash( $_GET );
		$filters = Dates::filters( $input );
		$contact = $filters['contact_id'] ? CRM::require_contact( $filters['contact_id'] ) : null;
		$result = Store::query( $filters, absint( Plugin::input( 'paged', '1', $_GET ) ) );
		$types = array( '' => __( 'All courses', 'jpcrm-courses' ) );
		foreach ( Store::types() as $type ) { $types[ $type['id'] ] = $type['name'] . ( $type['active'] ? '' : __( ' (archived)', 'jpcrm-courses' ) ); }
		if ( $contact ) {
			echo '<div class="jpcc-toolbar"><h2>' . esc_html( CRM::name( $contact ) ) . '</h2><div>';
			self::button( __( 'Open contact', 'jpcrm-courses' ), CRM::link( $contact['id'] ) );
			if ( CRM::can_edit() ) { self::button( __( 'Add course date', 'jpcrm-courses' ), CRM::courses_link( $contact['id'], array( 'jpcc_record' => 'new' ) ), true ); }
			echo '</div></div>';
		}
		echo '<form method="get" class="jpcc-panel jpcc-filters">';
		self::hidden( 'page', 'jpcc-courses' );
		if ( $contact ) { self::hidden( 'contact_id', $contact['id'] ); }
		self::select( __( 'Course type', 'jpcrm-courses' ), 'course_type_id', $filters['course_type_id'], $types );
		self::field( __( 'Expiry month', 'jpcrm-courses' ), 'expiry_month', $filters['expiry_month'], 'month' );
		self::field( __( 'Or expires from', 'jpcrm-courses' ), 'from', $filters['expiry_month'] ? '' : $filters['from'], 'date' );
		self::field( __( 'Expires through', 'jpcrm-courses' ), 'to', $filters['expiry_month'] ? '' : $filters['to'], 'date' );
		self::select( __( 'Status', 'jpcrm-courses' ), 'status', $filters['status'], array( '' => __( 'All statuses', 'jpcrm-courses' ), 'expired' => __( 'Expired', 'jpcrm-courses' ), 'soon' => __( 'Expires within 30 days', 'jpcrm-courses' ), 'current' => __( 'Current', 'jpcrm-courses' ), 'none' => __( 'No expiry', 'jpcrm-courses' ) ) );
		self::field( __( 'Contact name or email', 'jpcrm-courses' ), 'search', $filters['search'], 'search' );
		echo '<div class="jpcc-filter-footer"><label><input type="checkbox" name="latest" value="1" ' . checked( $filters['latest'], true, false ) . '> ' . esc_html__( 'Latest course per contact only', 'jpcrm-courses' ) . '</label><button class="button button-primary">' . esc_html__( 'Apply filters', 'jpcrm-courses' ) . '</button> <a href="' . esc_url( self::url( $contact ? array( 'contact_id' => $contact['id'] ) : array() ) ) . '">' . esc_html__( 'Clear filters', 'jpcrm-courses' ) . '</a></div></form>';
		echo '<p>' . esc_html( sprintf( _n( '%s course record', '%s course records', $result['total'], 'jpcrm-courses' ), number_format_i18n( $result['total'] ) ) ) . ' · ' . esc_html__( 'Expiry ranges include both dates. A certificate is current through its expiry date.', 'jpcrm-courses' ) . '</p>';
		$can_audience = MailPoet::available() && MailPoet::permitted();
		if ( $can_audience ) {
			self::post_form( 'audience', 'jpcc_audience' );
			self::hidden( 'token', str_replace( '-', '', wp_generate_uuid4() ) );
			foreach ( $filters as $key => $value ) {
				if ( $filters['expiry_month'] && in_array( $key, array( 'from', 'to' ), true ) ) { $value = ''; }
				self::hidden( $key, is_bool( $value ) ? (int) $value : $value );
			}
		}
		self::table( $result['rows'], $can_audience );
		if ( $can_audience ) {
			if ( $result['total'] ) {
				echo '<section class="jpcc-panel jpcc-mailout"><h2>' . esc_html__( 'Send an Email', 'jpcrm-courses' ) . '</h2><p>' . esc_html__( 'Create a new list from your selection, then compose your email in MailPoet and choose that list as the recipients.', 'jpcrm-courses' ) . '</p><div class="jpcc-filters">';
				self::select( __( 'Include', 'jpcrm-courses' ), 'selection', 'selected', array( 'selected' => __( 'Checked records on this page', 'jpcrm-courses' ), 'all' => sprintf( __( 'All %d records matching these filters', 'jpcrm-courses' ), $result['total'] ) ) );
				$default_name = ( $filters['course_type_id'] ? $types[ $filters['course_type_id'] ] ?? '' : __( 'Course renewals', 'jpcrm-courses' ) ) . ( $filters['expiry_month'] ? ' — ' . $filters['expiry_month'] : '' );
				self::field( __( 'Audience name', 'jpcrm-courses' ), 'audience_name', $default_name, 'text', 'required maxlength="100"' );
				echo '<button class="button button-primary">' . esc_html__( 'Prepare audience', 'jpcrm-courses' ) . '</button></div><p class="description">' . esc_html__( 'Only existing, subscribed MailPoet recipients are included. Do Not Email contacts, missing addresses and inactive subscribers are skipped. Duplicate contacts and email addresses are counted once. Up to 5,000 course records per audience.', 'jpcrm-courses' ) . '</p></section>';
			}
			echo '</form>';
		} else {
			echo '<p class="jpcc-panel">' . esc_html__( 'Mailouts require active MailPoet and permissions to manage MailPoet lists, subscribers and emails, plus permission to edit and email CRM contacts.', 'jpcrm-courses' ) . '</p>';
		}
		if ( $result['pages'] > 1 ) {
			echo '<nav class="jpcc-pagination" aria-label="' . esc_attr__( 'Course pages', 'jpcrm-courses' ) . '">';
			$args = $filters;
			if ( $args['expiry_month'] ) { $args['from'] = ''; $args['to'] = ''; }
			if ( $result['page'] > 1 ) { self::button( __( 'Previous', 'jpcrm-courses' ), self::url( $args + array( 'paged' => $result['page'] - 1 ) ) ); }
			echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'jpcrm-courses' ), $result['page'], $result['pages'] ) ) . '</span> ';
			if ( $result['page'] < $result['pages'] ) { self::button( __( 'Next', 'jpcrm-courses' ), self::url( $args + array( 'paged' => $result['page'] + 1 ) ) ); }
			echo '</nav>';
		}
	}

	public static function table( $rows, $selectable ) {
		echo '<div class="jpcc-table-scroll"><table class="widefat striped jpcc-table"><thead><tr>';
		if ( $selectable ) { echo '<td class="check-column"><input type="checkbox" data-jpcc-select-all aria-label="' . esc_attr__( 'Select all records on this page', 'jpcrm-courses' ) . '"></td>'; }
		foreach ( array( __( 'Contact', 'jpcrm-courses' ), __( 'Course', 'jpcrm-courses' ), __( 'Course date', 'jpcrm-courses' ), __( 'Expiry', 'jpcrm-courses' ), __( 'Status', 'jpcrm-courses' ), __( 'Certificate', 'jpcrm-courses' ), __( 'Actions', 'jpcrm-courses' ) ) as $heading ) { echo '<th scope="col">' . esc_html( $heading ) . '</th>'; }
		echo '</tr></thead><tbody>';
		if ( ! $rows ) { echo '<tr><td colspan="8" class="jpcc-empty">' . esc_html__( 'No course records found. Add a course date from a contact’s Courses & certificates tab, or adjust your filters.', 'jpcrm-courses' ) . '</td></tr>'; }
		foreach ( $rows as $row ) {
			$contact = array( 'id' => $row['contact_id'], 'fname' => $row['fname'], 'lname' => $row['lname'], 'email' => $row['email'] );
			$expired = $row['expires_on'] && $row['expires_on'] < current_time( 'Y-m-d' );
			$status = ! $row['expires_on'] ? __( 'No expiry', 'jpcrm-courses' ) : ( $expired ? __( 'Expired', 'jpcrm-courses' ) : __( 'Current', 'jpcrm-courses' ) );
			echo '<tr>';
			if ( $selectable ) { echo '<th scope="row" class="check-column"><input type="checkbox" name="record_ids[]" value="' . esc_attr( $row['id'] ) . '" aria-label="' . esc_attr( CRM::name( $contact ) . ' — ' . $row['course_name'] . ' — ' . $row['course_date'] ) . '"></th>'; }
			echo '<td><a href="' . esc_url( CRM::link( $row['contact_id'] ) ) . '">' . esc_html( CRM::name( $contact ) ) . '</a><small>' . esc_html( $row['email'] ) . '</small></td><td><strong>' . esc_html( $row['course_name'] ) . '</strong></td><td>' . esc_html( $row['course_date'] ) . '</td><td>' . esc_html( $row['expires_on'] ?: '—' ) . '</td><td><span class="jpcc-status ' . ( $expired ? 'jpcc-expired' : 'jpcc-current' ) . '">' . esc_html( $status ) . '</span></td><td>';
			if ( $row['certificate_id'] ) { echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( Certificates::url( $row['id'] ) ) . '">' . esc_html__( 'View', 'jpcrm-courses' ) . '</a> · <a href="' . esc_url( Certificates::url( $row['id'], true ) ) . '">' . esc_html__( 'Download', 'jpcrm-courses' ) . '</a>'; }
			else { echo '—'; }
			echo '</td><td>';
			if ( CRM::can_edit() ) { echo '<a href="' . esc_url( CRM::courses_link( $row['contact_id'], array( 'jpcc_record' => $row['id'] ) ) ) . '">' . esc_html__( 'Edit', 'jpcrm-courses' ) . '</a>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function types_page() {
		if ( ! CRM::can_configure() ) { throw new \RuntimeException( __( 'You cannot configure course types.', 'jpcrm-courses' ) ); }
		$id = absint( Plugin::input( 'id', '', $_GET ) );
		$type = $id ? Store::type( $id ) : array( 'id' => 0, 'name' => '', 'validity_amount' => 1, 'validity_unit' => 'years', 'active' => 1 );
		if ( ! $type ) { throw new \RuntimeException( __( 'Course type not found.', 'jpcrm-courses' ) ); }
		echo '<section class="jpcc-panel"><h2>' . esc_html( $id ? __( 'Edit course type', 'jpcrm-courses' ) : __( 'Add course type', 'jpcrm-courses' ) ) . '</h2>';
		self::post_form( 'save_type', 'jpcc_save_type' );
		self::hidden( 'id', $type['id'] );
		echo '<div class="jpcc-filters">';
		self::field( __( 'Course name', 'jpcrm-courses' ), 'name', $type['name'], 'text', 'required maxlength="190"' );
		self::field( __( 'Valid for', 'jpcrm-courses' ), 'validity_amount', $type['validity_amount'], 'number', 'min="0" max="36500" step="1"' );
		self::select( __( 'Expiry unit', 'jpcrm-courses' ), 'validity_unit', $type['validity_unit'], array( 'days' => __( 'Days', 'jpcrm-courses' ), 'months' => __( 'Months', 'jpcrm-courses' ), 'years' => __( 'Years', 'jpcrm-courses' ), 'none' => __( 'Does not expire', 'jpcrm-courses' ) ) );
		echo '<label><input type="checkbox" name="active" value="1" ' . checked( $type['active'], 1, false ) . '> ' . esc_html__( 'Available for new records', 'jpcrm-courses' ) . '</label></div><p class="description">' . esc_html__( 'For example: First Aid, valid for 1 year. Changes apply to new records only. Archive a type by unchecking availability; its history is preserved.', 'jpcrm-courses' ) . '</p>';
		submit_button( __( 'Save course type', 'jpcrm-courses' ) );
		echo '</form></section><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Course', 'jpcrm-courses' ) . '</th><th>' . esc_html__( 'Validity', 'jpcrm-courses' ) . '</th><th>' . esc_html__( 'Availability', 'jpcrm-courses' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( Store::types() as $row ) {
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( self::rule_label( $row ) ) . '</td><td>' . esc_html( $row['active'] ? __( 'Active', 'jpcrm-courses' ) : __( 'Archived', 'jpcrm-courses' ) ) . '</td><td><a href="' . esc_url( self::url( array( 'view' => 'types', 'id' => $row['id'] ) ) ) . '">' . esc_html__( 'Edit', 'jpcrm-courses' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function rule_label( $row ) {
		$amount = (int) $row['validity_amount'];
		switch ( $row['validity_unit'] ) {
			case 'days': return sprintf( _n( '%d day', '%d days', $amount, 'jpcrm-courses' ), $amount );
			case 'months': return sprintf( _n( '%d month', '%d months', $amount, 'jpcrm-courses' ), $amount );
			case 'years': return sprintf( _n( '%d year', '%d years', $amount, 'jpcrm-courses' ), $amount );
			default: return __( 'Does not expire', 'jpcrm-courses' );
		}
	}

	public static function record_page( $contact_id = 0, $id = null ) {
		$id = null === $id ? absint( Plugin::input( 'id', '', $_GET ) ) : $id;
		$record = $id ? Store::record( $id ) : null;
		if ( $id && ! $record ) { throw new \RuntimeException( __( 'Course record not found.', 'jpcrm-courses' ) ); }
		if ( $record && $contact_id && (int) $record['contact_id'] !== (int) $contact_id ) { throw new \RuntimeException( __( 'This course record belongs to a different contact.', 'jpcrm-courses' ) ); }
		$contact_id = $contact_id ?: ( $record ? $record['contact_id'] : absint( Plugin::input( 'contact_id', '', $_GET ) ) );
		$contact = CRM::require_contact( $contact_id, true );
		echo '<section class="jpcc-panel jpcc-editor"><h2>' . esc_html( $id ? __( 'Edit course record', 'jpcrm-courses' ) : __( 'Add course date', 'jpcrm-courses' ) ) . ' · ' . esc_html( CRM::name( $contact ) ) . '</h2>';
		$options = array( '' => __( 'Choose a course', 'jpcrm-courses' ) );
		foreach ( Store::types() as $type ) {
			if ( $type['active'] || ( $record && (int) $type['id'] === (int) $record['course_type_id'] ) ) { $options[ $type['id'] ] = $type['name'] . ' (' . self::rule_label( $type ) . ')'; }
		}
		if ( 1 === count( $options ) ) {
			echo '<p>' . esc_html__( 'A CRM administrator needs to configure at least one active course type first.', 'jpcrm-courses' ) . '</p>';
			if ( CRM::can_configure() ) { self::button( __( 'Configure course types', 'jpcrm-courses' ), self::url( array( 'view' => 'types' ) ), true ); }
			echo '</section>'; return;
		}
		self::post_form( 'save_record', 'jpcc_save_record' );
		self::hidden( 'id', $id ); self::hidden( 'contact_id', $contact_id ); self::hidden( 'version', $record['version'] ?? 0 );
		if ( ! $record ) { self::hidden( 'submission_token', str_replace( '-', '', wp_generate_uuid4() ) ); }
		self::select( __( 'Course type', 'jpcrm-courses' ), 'course_type_id', $record['course_type_id'] ?? '', $options );
		self::field( __( 'Course date', 'jpcrm-courses' ), 'course_date', $record['course_date'] ?? current_time( 'Y-m-d' ), 'date', 'required min="1900-01-01" max="9999-12-31"' );
		if ( $record ) {
			echo '<p>' . esc_html( sprintf( __( 'Saved validity: %1$s. Expiry: %2$s. Editing the date uses the saved validity; selecting a different course uses that course’s current rule.', 'jpcrm-courses' ), self::rule_label( $record ), $record['expires_on'] ?: __( 'No expiry', 'jpcrm-courses' ) ) ) . '</p>';
			if ( $record['certificate_id'] ) { self::button( __( 'View current certificate', 'jpcrm-courses' ), Certificates::url( $id ) ); }
		}
		echo '<div class="jpcc-field"><label for="jpcc-certificate"><strong>' . esc_html( $record ? __( 'Replace certificate (optional)', 'jpcrm-courses' ) : __( 'Certificate', 'jpcrm-courses' ) ) . '</strong></label><div class="jpcc-dropzone" data-jpcc-dropzone><span class="dashicons dashicons-upload" aria-hidden="true"></span><strong>' . esc_html__( 'Drag and drop a certificate here', 'jpcrm-courses' ) . '</strong><span>' . esc_html__( 'or choose a file below', 'jpcrm-courses' ) . '</span><input id="jpcc-certificate" type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="jpcc-file-help jpcc-file-status jpcc-file-error" ' . ( $record ? '' : 'required' ) . '><span id="jpcc-file-status" data-jpcc-file-status role="status">' . esc_html__( 'No file selected.', 'jpcrm-courses' ) . '</span><span id="jpcc-file-error" data-jpcc-file-error role="alert"></span></div></div><p class="description" id="jpcc-file-help">' . esc_html__( 'PDF, JPEG or PNG. Maximum 5 MB, subject to your server upload limit. Only CRM users with contact access can view certificates.', 'jpcrm-courses' ) . '</p><label class="jpcc-field"><span>' . esc_html__( 'Notes (optional)', 'jpcrm-courses' ) . '</span><textarea name="notes" rows="4" maxlength="5000">' . esc_textarea( $record['notes'] ?? '' ) . '</textarea></label>';
		submit_button( __( 'Save course record', 'jpcrm-courses' ) );
		echo '</form>';
		self::button( __( 'Back to contact courses', 'jpcrm-courses' ), CRM::courses_link( $contact_id ) );
		echo '</section>';
		if ( $record ) {
			echo '<details class="jpcc-panel"><summary>' . esc_html__( 'Delete this record', 'jpcrm-courses' ) . '</summary><p>' . esc_html__( 'Permanently removes this course record and its certificate.', 'jpcrm-courses' ) . '</p>';
			self::post_form( 'delete_record', 'jpcc_delete_record_' . $id );
			self::hidden( 'id', $id ); self::hidden( 'version', $record['version'] );
			echo '<button class="button jpcc-delete" data-jpcc-delete>' . esc_html__( 'Delete record and certificate', 'jpcrm-courses' ) . '</button></form></details>';
		}
	}

	public static function audience_page() {
		$token = Plugin::input( 'token', '', $_GET );
		$job = MailPoet::job( $token );
		if ( ! $job ) { throw new \RuntimeException( __( 'Audience session expired. Check your MailPoet lists for any previously prepared audience.', 'jpcrm-courses' ) ); }
		$summary = MailPoet::summary( $job );
		echo '<section class="jpcc-panel" data-jpcc-audience="' . esc_attr( $token ) . '" data-done="' . ( $job['done'] ? '1' : '0' ) . '"><h2>' . esc_html__( 'Your MailPoet audience', 'jpcrm-courses' ) . '</h2><p><strong>' . esc_html( $job['name'] ) . '</strong></p><p data-jpcc-progress role="status" aria-live="polite">' . esc_html( sprintf( __( 'Processed %1$d of %2$d contacts. Added: %3$d. Skipped: %4$d. Failed: %5$d.', 'jpcrm-courses' ), $job['offset'], $summary['total'], $job['added'], $job['skipped'], $job['failed'] ) ) . '</p><progress max="' . esc_attr( $summary['total'] ) . '" value="' . esc_attr( $job['offset'] ) . '"></progress><p data-jpcc-batch-error role="alert"></p><button class="button" type="button" data-jpcc-resume hidden>' . esc_html__( 'Resume preparation', 'jpcrm-courses' ) . '</button>';
		if ( ! $job['done'] ) { echo '<p>' . esc_html__( 'Keep this page open while the list is prepared. If interrupted, reopen this page within 24 hours to resume.', 'jpcrm-courses' ) . '</p><noscript>' . esc_html__( 'JavaScript is required to prepare an audience. Enable it and reload this page.', 'jpcrm-courses' ) . '</noscript>'; }
		echo '<div data-jpcc-finished ' . ( $job['done'] ? '' : 'hidden' ) . '><h3>' . esc_html__( 'Review and compose', 'jpcrm-courses' ) . '</h3><ol><li>' . esc_html__( 'Review the added, skipped and failed counts below.', 'jpcrm-courses' ) . '</li><li>' . esc_html__( 'Open MailPoet and create a regular email.', 'jpcrm-courses' ) . '</li><li>' . esc_html__( 'Choose the list named above as the recipients. Preview the audience, compose, and send from MailPoet.', 'jpcrm-courses' ) . '</li></ol><p>';
		self::button( __( 'Open MailPoet emails', 'jpcrm-courses' ), admin_url( 'admin.php?page=mailpoet-newsletters' ), true );
		self::button( __( 'Review MailPoet lists', 'jpcrm-courses' ), admin_url( 'admin.php?page=mailpoet-lists' ) );
		echo '</p><p>' . esc_html__( 'This is a snapshot, not an automatically updated expiry segment. No campaign has been scheduled by this plugin. Only MailPoet subscribers already marked subscribed are added; other contacts must be reviewed in MailPoet before a future mailout.', 'jpcrm-courses' ) . '</p></div><h3>' . esc_html__( 'Exclusions and errors', 'jpcrm-courses' ) . '</h3><ul data-jpcc-reasons>';
		foreach ( $job['reasons'] as $reason => $count ) { echo '<li>' . esc_html( $reason . ': ' . $count ) . '</li>'; }
		echo '</ul><p class="description">' . esc_html__( 'Contact details below are limited to the first 100 exclusions or errors.', 'jpcrm-courses' ) . '</p><ul data-jpcc-details>';
		foreach ( $job['details'] as $detail ) { echo '<li>' . esc_html( '#' . $detail['contact_id'] . ' — ' . $detail['reason'] ) . '</li>'; }
		echo '</ul></section>';
	}
}
