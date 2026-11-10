<?php
// Invoked only by mailchimp-admin-integration-test.php --constant-key.
admin_verify( 'syntheticConfigConstant-us2' === CLFA_MAILCHIMP_API_KEY, 'Constant mode runs with a synthetic config credential' );
admin_verify( true === clfa_mc_store_key( 'syntheticAdminFirst-us1' ), 'Seed a distinct encrypted database credential' );
$before = admin_snapshot();
admin_verify( 'syntheticConfigConstant-us2' === clfa_mc_key(), 'Config constant takes precedence over the stored credential after cache flush' );

list( $html ) = admin_request( $admin );
admin_verify( (bool) preg_match( '/name="api_key"[^>]*value=""[^>]*disabled/', $html ), 'Constant-managed key field is disabled and blank' );
foreach ( array( 'syntheticAttack-us1', 'invalid-key', '' ) as $submitted_key ) {
list( $html, $died ) = admin_request( $admin, array(
'mc_action' => 'settings', 'clfa_mc_nonce' => admin_nonce( $admin ),
'api_key' => $submitted_key, 'audience' => 'constant-list', 'enabled' => '1',
'double_optin' => '1',
) );
$after = admin_snapshot();
admin_verify( ! $died && str_contains( $html, 'Settings saved.' )
&& 'constant-list' === clfa_mc_settings()['audience'] && clfa_mc_settings()['enabled'],
'Authorized settings save succeeds with a constant-managed key' );
admin_verify( $before[1] === $after[1] && $before[2] === $after[2]
&& 'syntheticConfigConstant-us2' === clfa_mc_key(),
'Posted replacement, malformed or blank key cannot overwrite either credential or rotate the callback token' );
admin_verify( (bool) preg_match( '/name="api_key"[^>]*value=""[^>]*disabled/', $html ), 'Key field remains blank and disabled after POST' );
}

// Check the credential actually used by the production HTTP request, not just
// the accessor. Intercept everything; no synthetic credential leaves the test.
$calls = 0;
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) {
admin_verify( 'https://us2.api.mailchimp.com/3.0/lists/constant-list' === $url
&& 'GET' === $args['method']
&& 'Basic ' . base64_encode( 'clf:syntheticConfigConstant-us2' ) === $args['headers']['Authorization'],
'Connection test uses the constant credential and its server suffix' );
++ $calls;
return array( 'headers' => array(), 'body' => '{"name":"Synthetic constant audience"}',
'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
}, 10, 3 );
$before = admin_snapshot();
list( $html, $died ) = admin_request( $admin, array(
'mc_action' => 'test', 'clfa_mc_nonce' => admin_nonce( $admin ),
) );
admin_verify( ! $died && 1 === $calls && str_contains( $html, 'Connection works. Audience: Synthetic constant audience' )
&& $before === admin_snapshot(), 'Constant-backed connection test succeeds without changing settings' );
echo "WordPress Mailchimp constant-key integration tests passed.\n";
