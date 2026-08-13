<?php
/**
 * Plugin Name:       CLF Alumni Network
 * Plugin URI:        https://charlotteforum.org
 * Description:       Private alumni network for the Charlotte Leadership Forum — member profiles, searchable directory, and admin member management. Bold Conviction design.
 * Version:           1.0.0
 * Author:            Charlotte Leadership Forum
 * License:           GPL-2.0-or-later
 * Text Domain:       clf-alumni
 * GitHub Plugin URI: tipitoe-ro/clf-alumni
 * Primary Branch:    main
 */

defined( 'ABSPATH' ) || exit;

define( 'CLFA_VERSION', '1.0.0' );
define( 'CLFA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLFA_URL', plugin_dir_url( __FILE__ ) );

require_once CLFA_DIR . 'includes/helpers.php';
require_once CLFA_DIR . 'includes/access.php';
require_once CLFA_DIR . 'includes/profile.php';
require_once CLFA_DIR . 'includes/directory.php';
require_once CLFA_DIR . 'includes/admin.php';

/* ============================================================
   Activation: role + members-area pages
   ============================================================ */
register_activation_hook( __FILE__, 'clfa_activate' );
function clfa_activate() {
	add_role( 'clf_alumni', __( 'CLF Alumni', 'clf-alumni' ), array( 'read' => true ) );

	$pages = array(
		'alumni-login'   => array( 'title' => 'Alumni Login',   'content' => '[clf_alumni_login]' ),
		'alumni'         => array( 'title' => 'Alumni Network', 'content' => '[clf_alumni_directory]' ),
		'alumni-profile' => array( 'title' => 'My Profile',     'content' => '[clf_alumni_profile]' ),
	);
	foreach ( $pages as $slug => $p ) {
		if ( ! get_page_by_path( $slug ) ) {
			wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $p['title'],
				'post_content' => $p['content'],
			) );
		}
	}
}

/* ============================================================
   Front-end assets (only when a members-area page renders)
   ============================================================ */
function clfa_enqueue_assets() {
	if ( ! is_page( array( 'alumni', 'alumni-profile', 'alumni-login' ) ) ) {
		return;
	}
	wp_enqueue_style( 'clfa-fonts', 'https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,600&display=swap', array(), null );
	wp_enqueue_style( 'clfa-style', CLFA_URL . 'assets/clf-alumni.css', array( 'clfa-fonts' ), CLFA_VERSION );
}
add_action( 'wp_enqueue_scripts', 'clfa_enqueue_assets' );
