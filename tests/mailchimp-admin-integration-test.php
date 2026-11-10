<?php
// Real WordPress capabilities, nonces, options, crypto and production handler.
// CLI requests populate superglobals as PHP does for form submissions.
// Constants cannot be undefined: the runner launches this mode in its own PHP process.
if ( in_array( '--constant-key', $argv ?? array(), true ) ) {
define( 'CLFA_MAILCHIMP_API_KEY', 'syntheticConfigConstant-us2' );
}
require __DIR__ . '/wordpress-integration/bootstrap.php';

function admin_verify( $condition, $message ) {
if ( ! $condition ) {
throw new RuntimeException( $message );
}
echo "PASS: $message\n";
}

class Clfa_Admin_Test_Die extends RuntimeException {}
// Keep WordPress nonce verification intact; only replace the terminal wp_die.
add_filter( 'wp_die_handler', function () {
return function () { throw new Clfa_Admin_Test_Die(); };
} );

function admin_request( $uid, $post = array() ) {
wp_set_current_user( $uid );
$_GET = array( 'page' => 'clfa-mailchimp' );
$_POST = wp_slash( $post );
$_REQUEST = array_merge( $_GET, $_POST );
$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=clfa-mailchimp';
$died = false;
ob_start();
try {
clfa_mc_admin_page();
} catch ( Clfa_Admin_Test_Die $e ) {
$died = true;
} finally {
$html = ob_get_clean();
}
// Check every response, including validation errors and successful POSTs.
foreach ( array( 'syntheticAdminFirst-us1', 'syntheticAdminReplacement-us1', 'syntheticAttack-us1', 'syntheticConfigConstant-us2' ) as $key ) {
admin_verify( ! str_contains( $html, $key ) && ! str_contains( $html, base64_encode( 'clf:' . $key ) ), 'Response does not disclose synthetic API credential' );
}
return array( $html, $died );
}

function admin_nonce( $uid, $action = 'clfa_mc_admin' ) {
wp_set_current_user( $uid );
return wp_create_nonce( $action );
}

function admin_snapshot() {
wp_cache_flush();
return array_map( 'get_option', array( 'clfa_mailchimp', 'clfa_mailchimp_key', 'clfa_mc_webhook_token' ) );
}

$admin = get_user_by( 'login', 'synthetic-admin' )->ID;
if ( defined( 'CLFA_MAILCHIMP_API_KEY' ) ) {
require __DIR__ . '/wordpress-integration/admin-constant-key.php';
return;
}
add_role( 'clf_alumni', 'CLF Alumni', array( 'read' => true ) );
$member = wp_insert_user( array(
'user_login' => 'synthetic-settings-member', 'user_email' => 'settings@example.test',
'user_pass' => 'synthetic-only-password', 'role' => 'clf_alumni',
) );
admin_verify( ! is_wp_error( $member ), 'Real member created' );
admin_verify( user_can( $admin, 'manage_options' ) && ! user_can( $member, 'manage_options' ), 'Real administrator/member capabilities differ' );

$settings = array(
'mc_action' => 'settings', 'audience' => 'test-list', 'enabled' => '1',
'double_optin' => '1', 'api_key' => 'syntheticAdminFirst-us1',
);
list( $html, $died ) = admin_request( $admin, $settings + array( 'clfa_mc_nonce' => admin_nonce( $admin ) ) );
admin_verify( ! $died && str_contains( $html, 'Settings saved.' ), 'Administrator saves settings with a real nonce' );
admin_verify( clfa_mc_settings() === array( 'enabled' => true, 'audience' => 'test-list', 'double_optin' => true, 'auto_enroll' => false ), 'All connection settings persisted' );
$before = admin_snapshot();
$ciphertext = get_option( 'clfa_mailchimp_key' );
$encrypted = json_decode( $ciphertext, true );
admin_verify( isset( $encrypted['iv'], $encrypted['tag'], $encrypted['data'] )
&& ! str_contains( $ciphertext, 'syntheticAdminFirst-us1' )
&& 'syntheticAdminFirst-us1' === clfa_mc_key(), 'Stored key is authenticated ciphertext and decrypts after cache flush' );
global $wpdb;
admin_verify( 'off' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'clfa_mailchimp_key'" ), 'Stored credential is not autoloaded' );
admin_verify( (bool) preg_match( '/name="api_key"[^>]*value=""/', $html ), 'Rendered password field is blank' );
admin_verify( (bool) preg_match( '/name="clfa_mc_nonce" value="([^"]+)"/', $html, $nonce_match )
&& wp_verify_nonce( $nonce_match[1], 'clfa_mc_admin' ), 'Rendered form carries a valid WordPress nonce' );

// Every denied action must preserve the exact encrypted key, settings and token.
// The bootstrap rejects any HTTP, so unauthorized connection tests also fail
// immediately if they accidentally reach the remote API.
foreach ( array( $member, 0, $admin ) as $actor ) {
$nonces = array( 'missing' => null, 'invalid' => 'invalid-nonce',
'wrong-action' => admin_nonce( $actor, 'another-action' ),
'other-user' => admin_nonce( $actor === $admin ? $member : $admin ) );
if ( $actor !== $admin ) {
$nonces['own-valid'] = admin_nonce( $actor );
}
foreach ( $nonces as $kind => $nonce ) {
foreach ( array( 'settings', 'rotate_webhook', 'test' ) as $action ) {
$post = array( 'mc_action' => $action, 'api_key' => 'syntheticAttack-us1',
'audience' => 'attacker-list', 'auto_enroll' => '1', 'auto_consent' => '1' );
if ( null !== $nonce ) {
$post['clfa_mc_nonce'] = $nonce;
}
list( $html, $died ) = admin_request( $actor, $post );
admin_verify( $before === admin_snapshot(), "Denied actor $actor / $kind / $action preserves credentials and settings" );
admin_verify( $died === ( $actor === $admin && null !== $nonce ), 'Invalid administrator nonce terminates in WordPress; other requests cannot act' );
if ( $actor !== $admin ) {
admin_verify( '' === $html, 'Non-administrator receives no settings or private webhook URL' );
}
}
}
}

// A rejected settings submission must not partially replace the credential,
// settings, callback token, or existing subscriber state.
$identity = array( 'audience' => 'test-list', 'email' => 'settings@example.test' );
update_user_meta( $member, 'clfa_mc_identity', $identity );
update_user_meta( $member, 'clfa_mc_consent', '1' );
$member_before = get_user_meta( $member );
$before = admin_snapshot();
foreach ( array( 'different-list', '' ) as $audience ) {
list( $html, $died ) = admin_request( $admin, array_replace( $settings, array(
'audience' => $audience, 'api_key' => 'syntheticAttack-us1',
'clfa_mc_nonce' => admin_nonce( $admin ),
) ) );
admin_verify( ! $died && str_contains( $html, 'This audience has synced contacts.' )
&& ! str_contains( $html, 'Settings saved.' ), 'Changing or clearing a synced audience shows the migration error' );
admin_verify( $before === admin_snapshot() && $member_before === get_user_meta( $member ),
'Rejected audience change preserves settings, ciphertext, webhook token and subscriber metadata' );
}
list( $html, $died ) = admin_request( $admin, array_replace( $settings, array(
'auto_enroll' => '1', 'api_key' => 'syntheticAttack-us1',
'clfa_mc_nonce' => admin_nonce( $admin ),
) ) );
admin_verify( ! $died && str_contains( $html, 'Confirm existing consent before enabling automatic enrollment.' )
&& ! str_contains( $html, 'Settings saved.' ) && $before === admin_snapshot()
&& $member_before === get_user_meta( $member ), 'Automatic enrollment without confirmation is rejected without side effects' );

$blank = array_replace( $settings, array( 'api_key' => '', 'double_optin' => '', 'auto_enroll' => '1', 'auto_consent' => '1' ) );
admin_request( $admin, $blank + array( 'clfa_mc_nonce' => admin_nonce( $admin ) ) );
admin_verify( $ciphertext === get_option( 'clfa_mailchimp_key' ) && clfa_mc_settings()['auto_enroll']
&& ! clfa_mc_settings()['double_optin'], 'Blank key preserves ciphertext while authorized settings change' );
admin_verify( $member_before === get_user_meta( $member ), 'Confirmed automatic enrollment does not alter or queue existing subscribers' );
delete_user_meta( $member, 'clfa_mc_identity' );
$before = admin_snapshot();
list( $html ) = admin_request( $admin, array_replace( $settings, array(
'api_key' => 'invalid-key', 'clfa_mc_nonce' => admin_nonce( $admin ),
) ) );
admin_verify( str_contains( $html, 'Enter a valid Mailchimp API key' ) && $before === admin_snapshot(), 'Malformed key is rejected without overwriting saved settings or credentials' );
list( $html ) = admin_request( $admin, array_replace( $settings, array(
'api_key' => 'syntheticAdminReplacement-us1', 'clfa_mc_nonce' => admin_nonce( $admin ),
) ) );
wp_cache_flush();
admin_verify( 'syntheticAdminReplacement-us1' === clfa_mc_key() && $ciphertext !== get_option( 'clfa_mailchimp_key' ), 'Authorized replacement persists and decrypts' );
admin_request( $admin );
admin_request( $member );

// Intercept the connection test at the real WP HTTP API boundary.
$calls = 0;
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
admin_verify( 'https://us1.api.mailchimp.com/3.0/lists/test-list' === $url
&& 'GET' === $args['method']
&& 'Basic ' . base64_encode( 'clf:syntheticAdminReplacement-us1' ) === $args['headers']['Authorization'], 'Connection test uses the saved credential and audience' );
++ $calls;
return array( 'headers' => array(), 'body' => '{"name":"Synthetic audience"}',
'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
}, 10, 3 );
$before = admin_snapshot();
list( $html ) = admin_request( $admin, array( 'mc_action' => 'test', 'clfa_mc_nonce' => admin_nonce( $admin ) ) );
admin_verify( 1 === $calls && str_contains( $html, 'Connection works. Audience: Synthetic audience' )
&& $before === admin_snapshot(), 'Authorized connection test is intercepted and leaves settings unchanged' );

$old_token = get_option( 'clfa_mc_webhook_token' );
list( $html ) = admin_request( $admin, array( 'mc_action' => 'rotate_webhook', 'clfa_mc_nonce' => admin_nonce( $admin ) ) );
$after = admin_snapshot();
$new_token = get_option( 'clfa_mc_webhook_token' );
admin_verify( preg_match( '/^[a-f0-9]{64}$/D', $new_token ) && $new_token !== $old_token
&& $before[0] === $after[0] && $before[1] === $after[1], 'Authorized rotation changes only the webhook credential' );
admin_verify( str_contains( $html, $new_token ) && ! str_contains( $html, $old_token ), 'Administrator sees only the replacement callback URL' );
foreach ( array( $old_token => 403, $new_token => 200 ) as $token => $expected ) {
$request = new WP_REST_Request( 'GET', '/clf-alumni/v1/mailchimp' );
$request->set_query_params( array( 'key' => $token ) );
wp_set_current_user( 0 );
admin_verify( $expected === rest_do_request( $request )->get_status(), 'Actual REST route rejects old webhook key and accepts new key after settings rotation' );
}
admin_verify( 1 === $calls, 'Rotation and webhook URL validation make no remote calls' );
echo "WordPress Mailchimp admin integration tests passed.\n";
