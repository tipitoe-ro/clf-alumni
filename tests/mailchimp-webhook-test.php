<?php
// Reuse the isolated WordPress/API test doubles and run the existing regressions.
require __DIR__ . '/mailchimp-test.php';
class WP_REST_Response {
function __construct( public $data, public $status ) {}
}
class WebhookRequest {
function __construct( public $body = array(), public $query = array(), public $method = 'POST' ) {}
function get_body_params() { return $this->body; }
function get_query_params() { return $this->query; }
function get_method() { return $this->method; }
}
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function get_user_by( $field, $email ) {
foreach ( $GLOBALS['users'] as $user ) {
if ( strtolower( $email ) === strtolower( $user->user_email ) ) { return $user; }
}
return false;
}
require __DIR__ . '/../includes/mailchimp-webhook.php';
function webhook( $type = 'unsubscribe', $email = 'member@example.test', $list = 'test-list' ) {
return clfa_mc_webhook( new WebhookRequest( array( 'type' => $type, 'data' => array( 'email' => $email, 'list_id' => $list ) ) ) );
}
$options['clfa_mailchimp']['enabled'] = true;
check( ! get_option( 'clfa_mc_webhook_last_accepted' ) && ! get_option( 'clfa_mc_webhook_verification' ), 'Health tracking starts empty' );
$users[4]->user_email = 'member@example.test';
$meta[4] = array(
'clfa_mc_consent' => 1,
'clfa_mc_identity' => array( 'email' => 'member@example.test', 'audience' => 'test-list' ),
'clfa_mc_pending' => 1,
);
$token = clfa_mc_webhook_token();
check( strlen( $token ) === 64 && $token === clfa_mc_webhook_token(), 'Webhook credential is random and stable' );
foreach ( array( array(), array( 'key' => 'wrong' ), array( 'key' => array( $token ) ) ) as $query ) {
check( clfa_mc_webhook_permission( new WebhookRequest( array(), $query ) )->data['status'] === 403, 'Missing, incorrect or array credential rejected' );
}
check( true === clfa_mc_webhook_permission( new WebhookRequest( array(), array( 'key' => $token ) ) ), 'Valid credential accepted' );
$options['clfa_mc_webhook_token'] = 'rotated';
check( is_wp_error( clfa_mc_webhook_permission( new WebhookRequest( array(), array( 'key' => $token ) ) ) ), 'Rotating invalidates old credential' );
$requests = array();
check( clfa_mc_webhook( new WebhookRequest( array(), array(), 'GET' ) )->status === 200 && ! $requests, 'GET probe does not call API or change data' );
check( ! get_option( 'clfa_mc_webhook_last_accepted' ) && ! get_option( 'clfa_mc_webhook_verification' ), 'Rejected credentials and GET probes do not create health history' );
foreach ( array( array(), array( 'type' => array(), 'data' => array() ), array( 'type' => 'unsubscribe', 'data' => 'bad' ) ) as $body ) {
check( clfa_mc_webhook( new WebhookRequest( $body ) )->data['status'] === 400, 'Malformed payload rejected' );
}
check( webhook( 'unsubscribe', 'bad email' )->data['status'] === 400, 'Invalid email rejected' );
check( webhook( 'unsubscribe', 'member@example.test', 'other-list' )->data['status'] === 400, 'Wrong audience rejected' );
check( ! get_option( 'clfa_mc_webhook_last_accepted' ), 'Invalid payloads do not advance delivery time' );
webhook( 'unsubscribe', 'unknown@example.test' );
webhook( 'profile' );
check( abs( time() - get_option( 'clfa_mc_webhook_last_accepted' ) ) <= 1, 'Accepted ignored events record a delivery timestamp' );
check( ! $requests, 'Unknown contacts and unsupported events ignored without API calls' );
$remote = array( 'status' => 'subscribed' );
webhook( 'subscribe' );
check( str_contains( $meta[4]['clfa_mc_result'], 'confirmation verified' ) && $meta[4]['clfa_mc_consent'] === 1, 'Confirmation immediately updates result for opted-in member' );
clfa_mc_consent( 4, false, 'member_profile' );
$before = $meta[4];
webhook( 'subscribe' );
check( $before === $meta[4], 'Confirmation cannot override opt-out, result or pending work' );
$meta[4]['clfa_mc_consent'] = 1;
webhook();
check( $meta[4]['clfa_mc_consent'] === 1, 'Delayed unsubscribe does not override current subscribed API state' );
foreach ( array( 'unsubscribed', 'cleaned', 'transactional', null ) as $status ) {
$meta[4]['clfa_mc_consent'] = 1;
$remote = $status ? array( 'status' => $status ) : null;
webhook();
check( $meta[4]['clfa_mc_consent'] === 0 && $meta[4]['clfa_mc_consent_record']['actor'] === 0 && $meta[4]['clfa_mc_pending'] === 1, 'Verified opt-out clears consent and preserves pending work: ' . ( $status ?: 'removed' ) );
$record = $meta[4]['clfa_mc_consent_record'];
webhook();
check( $record === $meta[4]['clfa_mc_consent_record'], 'Duplicate delivery preserves consent audit record' );
}
$meta[4]['clfa_mc_consent'] = 1;
$before = $meta[4];
$options['clfa_mc_webhook_last_accepted'] = 123;
$fail = 429;
check( webhook()->data['status'] === 503 && $before === $meta[4], 'API failure requests retry without local mutation' );
check( get_option( 'clfa_mc_webhook_last_accepted' ) === 123, 'API failure does not advance accepted timestamp' );
$health = get_option( 'clfa_mc_webhook_verification' );
check( $health['failures'] === 1 && abs( time() - $health['last_failure'] ) <= 1, 'API failure records only aggregate count and timestamp' );
webhook();
check( get_option( 'clfa_mc_webhook_verification' )['failures'] === 2, 'Repeated failures increment aggregate count' );
check( array_keys( $health ) === array( 'failures', 'last_failure' ) && count( array_filter( $health, 'is_int' ) ) === 2, 'Diagnostics contain only numeric health values' );
check( ! get_option( 'clfa_mc_lock' ), 'API failure releases sync lock' );
$fail = 0;
$options['clfa_mc_lock'] = time();
check( webhook()->data['status'] === 503 && $before === $meta[4], 'Concurrent worker requests retry without changes' );
check( get_option( 'clfa_mc_webhook_verification' )['failures'] === 2 && get_option( 'clfa_mc_webhook_last_accepted' ) === 123, 'Busy lock does not count as API verification failure or accepted delivery' );
unset( $options['clfa_mc_lock'] );
$remote = array( 'status' => 'subscribed' );
webhook( 'subscribe' );
check( get_option( 'clfa_mc_webhook_last_accepted' ) > 123 && get_option( 'clfa_mc_webhook_verification' )['failures'] === 2, 'Recovery advances delivery time and preserves historical failures' );
$before = $meta[4];
check( ! array_filter( $requests, fn( $r ) => 'GET' !== $r['method'] ), 'Webhook performs no remote writes' );
$users[4]->user_email = 'new@example.test';
$requests = array();
webhook();
webhook( 'subscribe', 'new@example.test' );
check( ! $requests && $before === $meta[4], 'Old address and unsynced new identity ignored' );
$options['clfa_mailchimp']['enabled'] = false;
$options['clfa_mc_webhook_last_accepted'] = 123;
check( webhook()->data['status'] === 503 && ! $requests, 'Paused integration requests retry without API calls' );
check( get_option( 'clfa_mc_webhook_last_accepted' ) === 123 && get_option( 'clfa_mc_webhook_verification' )['failures'] === 2, 'Paused integration preserves health history' );
