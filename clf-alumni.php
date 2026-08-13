<?php
/**
 * Plugin Name:       CLF Alumni Network
 * Plugin URI:        https://app.global
 * Description:       Private alumni network for the Charlotte Leadership Forum — member profiles, searchable directory, and admin member management. Bold Conviction design.
 * Version:           1.5.3
 * Author:            Always About People
 * License:           GPL-2.0-or-later
 * Text Domain:       clf-alumni
 * GitHub Plugin URI: tipitoe-ro/clf-alumni
 * Primary Branch:    main
 */

defined( 'ABSPATH' ) || exit;

define( 'CLFA_VERSION', '1.5.3' );
define( 'CLFA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLFA_URL', plugin_dir_url( __FILE__ ) );

require_once CLFA_DIR . 'includes/helpers.php';
require_once CLFA_DIR . 'includes/portal-ui.php';
require_once CLFA_DIR . 'includes/access.php';
require_once CLFA_DIR . 'includes/profile.php';
require_once CLFA_DIR . 'includes/directory.php';
require_once CLFA_DIR . 'includes/admin.php';
require_once CLFA_DIR . 'includes/email.php';
require_once CLFA_DIR . 'includes/events.php';
require_once CLFA_DIR . 'includes/rsvp.php';
require_once CLFA_DIR . 'includes/invites.php';
require_once CLFA_DIR . 'includes/mentorship.php';
require_once CLFA_DIR . 'includes/opportunities.php';
require_once CLFA_DIR . 'includes/home.php';

/* ============================================================
   Activation: role + members-area pages
   ============================================================ */
register_activation_hook( __FILE__, 'clfa_activate' );
function clfa_activate() {
	add_role( 'clf_alumni', __( 'CLF Alumni', 'clf-alumni' ), array( 'read' => true ) );

	$pages = array(
		'alumni-login'   => array( 'title' => 'Alumni Login',   'content' => '[clf_alumni_login]' ),
		'alumni-home'    => array( 'title' => 'Alumni Home',    'content' => '[clf_alumni_home]' ),
		'alumni-directory'         => array( 'title' => 'Alumni Network', 'content' => '[clf_alumni_directory]' ),
		'alumni-profile' => array( 'title' => 'My Profile',     'content' => '[clf_alumni_profile]' ),
		'alumni-events'  => array( 'title' => 'Alumni Events',  'content' => '[clf_alumni_events]' ),
		'alumni-mentors' => array( 'title' => 'Find a Mentor',  'content' => '[clf_alumni_mentors]' ),
		'alumni-board'   => array( 'title' => 'Opportunities',  'content' => '[clf_alumni_opportunities]' ),
	);
	// v1.4.2 migration: if the plugin previously created the directory at /alumni/
	// (page containing the directory shortcode), rename it to /alumni-directory/.
	$old = get_page_by_path( 'alumni' );
	if ( $old && false !== strpos( $old->post_content, '[clf_alumni_directory]' ) && ! get_page_by_path( 'alumni-directory' ) ) {
		wp_update_post( array( 'ID' => $old->ID, 'post_name' => 'alumni-directory' ) );
	}

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
	clfa_install_rsvp_table();
	update_option( 'clfa_db_version', CLFA_VERSION );
}

/* Upgrade path: Git Updater replaces files without re-activating */
function clfa_maybe_upgrade() {
	if ( get_option( 'clfa_db_version' ) !== CLFA_VERSION ) {
		clfa_activate();
		clfa_cleanup_legacy_photos();
	}
}

/* One-time cleanup: delete any pre-1.0 member photos that were stored in the
   public Media Library (they leak via public URLs / REST enumeration). */
function clfa_cleanup_legacy_photos() {
	$users = get_users( array( 'meta_key' => 'clfa_photo_id', 'fields' => 'ID', 'number' => 2000 ) );
	foreach ( $users as $uid ) {
		$legacy = (int) get_user_meta( $uid, 'clfa_photo_id', true );
		if ( $legacy ) {
			wp_delete_attachment( $legacy, true );
		}
		delete_user_meta( $uid, 'clfa_photo_id' );
	}
}
add_action( 'admin_init', 'clfa_maybe_upgrade' );

/* ============================================================
   Front-end assets (only when a members-area page renders)
   ============================================================ */
function clfa_enqueue_assets() {
	if ( ! is_page( array( 'alumni-directory', 'alumni-home', 'alumni-profile', 'alumni-login', 'alumni-events', 'alumni-mentors', 'alumni-board' ) ) ) {
		return;
	}
	wp_enqueue_style( 'clfa-fonts', 'https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,600&display=swap', array(), null );
	wp_enqueue_style( 'clfa-style', CLFA_URL . 'assets/clf-alumni.css', array( 'clfa-fonts' ), CLFA_VERSION );
}
add_action( 'wp_enqueue_scripts', 'clfa_enqueue_assets' );
