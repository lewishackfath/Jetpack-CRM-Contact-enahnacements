<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

// Loaded only when the active CRM portal registers its endpoints.
final class Portal_Endpoint extends \Automattic\JetpackCRM\Client_Portal_Endpoint {
	public static function register_endpoint( $endpoints, $client_portal ) {
		$endpoint = new self( $client_portal );
		$endpoint->slug = Portal::SLUG;
		$endpoint->name = __( 'Training & certificates', 'jpcrm-courses' );
		$endpoint->icon = 'fa-graduation-cap';
		$endpoint->menu_order = 20;
		$endpoint->hide_from_menu = false;
		$endpoint->add_rewrite_endpoint = true;
		$endpoint->should_check_user_permission = true;
		$endpoints[] = $endpoint;
		return $endpoints;
	}

	public function output_html() { Portal::render( $this ); }
}
