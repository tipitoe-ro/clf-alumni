<?php
// Exercise actual menu registration, not just calling the page renderer.
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( get_user_by( 'login', 'synthetic-admin' )->ID );
$GLOBALS['menu'] = array();
$GLOBALS['submenu'] = array();
$GLOBALS['admin_page_hooks'] = array();
$GLOBALS['_registered_pages'] = array();
$GLOBALS['_parent_pages'] = array();
do_action( 'admin_menu', '' );
$hook = get_plugin_page_hookname( 'clfa-mailchimp', 'clf-alumni' );
if ( ! has_action( $hook, 'clfa_mc_admin_page' ) ) {
	throw new RuntimeException( 'Mailchimp callback not registered under its parent menu hook.' );
}
$url = menu_page_url( 'clfa-mailchimp', false );
if ( $url !== admin_url( 'admin.php?page=clfa-mailchimp' ) ) {
	throw new RuntimeException( 'Incorrect Mailchimp menu URL: ' . $url );
}
echo "PASS: Mailchimp submenu uses its registered parent hook and admin.php?page=clfa-mailchimp URL\n";
$slugs = array_column( $GLOBALS['submenu']['clf-alumni'], 2 );
$email_index = array_search( 'clfa-email', $slugs, true );
$mailchimp_index = array_search( 'clfa-mailchimp', $slugs, true );
if ( false === $email_index || $mailchimp_index !== $email_index + 1 ) {
	throw new RuntimeException( 'Mailchimp must appear directly below Email Settings.' );
}
echo "PASS: Mailchimp appears directly below Email Settings\n";
