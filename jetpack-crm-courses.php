<?php
/**
 * Plugin Name: Course Certificates for Jetpack CRM
 * Description: Contact course history, private certificates, expiry reporting and MailPoet audiences.
 * Version: 1.0.3
 * Plugin URI: https://github.com/lewishackfath/Jetpack-CRM---Contact-enahnacements
 * Update URI: https://github.com/lewishackfath/Jetpack-CRM---Contact-enahnacements
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: zero-bs-crm
 * Text Domain: jpcrm-courses
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'JPCRM_COURSES_VERSION', '1.0.3' );
define( 'JPCRM_COURSES_FILE', __FILE__ );

foreach ( array( 'dates', 'crm', 'activity', 'store', 'certificates', 'mailpoet', 'admin', 'updater', 'plugin' ) as $jpcc_class ) {
	require_once __DIR__ . '/includes/class-' . $jpcc_class . '.php';
}
unset( $jpcc_class );

register_activation_hook( __FILE__, array( 'JPCRM_Courses\Store', 'activate' ) );
add_action( 'plugins_loaded', array( 'JPCRM_Courses\Plugin', 'boot' ), 30 );
