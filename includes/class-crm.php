<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

final class CRM {
	public static function available() {
		global $zbs, $ZBSCRM_t;
		return isset( $zbs->DAL->contacts, $ZBSCRM_t['contacts'] ) && function_exists( 'zeroBSCRM_DAL2_ignoreOwnership' );
	}

	public static function can_view() { return current_user_can( 'admin_zerobs_view_customers' ); }
	public static function can_edit() { return self::can_view() && current_user_can( 'admin_zerobs_customers' ); }
	public static function can_configure() { return current_user_can( 'admin_zerobs_manage_options' ); }

	public static function contact( $id ) {
		global $zbs;
		if ( ! self::available() || ! self::can_view() ) { return false; }
		return $zbs->DAL->contacts->getContact( absint( $id ), array( 'withCustomFields' => false ) );
	}

	public static function require_contact( $id, $edit = false ) {
		$contact = self::contact( $id );
		if ( ! $contact || ( $edit && ! self::can_edit() ) ) {
			throw new \RuntimeException( __( 'This contact is unavailable or you do not have permission to access it.', 'jpcrm-courses' ) );
		}
		return $contact;
	}

	public static function name( $contact ) {
		$name = trim( ( $contact['fname'] ?? '' ) . ' ' . ( $contact['lname'] ?? '' ) );
		return $name ?: ( $contact['email'] ?: sprintf( __( 'Contact #%d', 'jpcrm-courses' ), $contact['id'] ) );
	}

	public static function link( $id ) { return jpcrm_esc_link( 'view', (int) $id, ZBS_TYPE_CONTACT ); }
	public static function courses_link( $id, $args = array() ) {
		return add_query_arg( array_merge( array( 'jpcc_tab' => 'courses' ), $args ), wp_specialchars_decode( self::link( $id ), ENT_QUOTES ) );
	}

	/** Use the same SQL ownership restrictions as the CRM DAL. */
	public static function scope() {
		global $zbs;
		$ignore = zeroBSCRM_DAL2_ignoreOwnership( ZBS_TYPE_CONTACT );
		return array( $zbs->DAL->contacts->ownershipSQL( $ignore, 'c' ), $zbs->DAL->contacts->ownershipQueryVars( $ignore ) );
	}

	public static function do_not_email( $id ) {
		global $zbs;
		return (bool) $zbs->DAL->contacts->getContactDoNotMail( $id );
	}
}
