<?php
/* No network, no WordPress install, no real credentials or emails.
 * Run: php clf-alumni/tests/mailchimp-test.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ );
define( 'CLFA_MAILCHIMP_API_KEY', 'test-us1' );
$meta = array();
$options = array( 'clfa_mailchimp' => array( 'enabled' => true, 'audience' => 'test-list', 'double_optin' => true ) );
$remote = null;
$requests = array();
$fail = 0;
$users = array( 4 => (object) array( 'ID' => 4, 'user_email' => 'member@example.test', 'first_name' => 'Test', 'last_name' => 'Member' ) );
class WP_Error {
	function __construct( public $code, public $message, public $data = null ) {}
	function get_error_code() { return $this->code; }
	function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( ...$v ) {}
function add_filter( ...$v ) {}
function wp_parse_args( $v, $defaults ) { return array_merge( $defaults, $v ); }
function get_option( $k, $default = false ) { return $GLOBALS['options'][$k] ?? $default; }
function update_option( $k, $v, ...$args ) { $GLOBALS['options'][$k] = $v; }
function add_option( $k, $v, ...$args ) {
	if ( isset( $GLOBALS['options'][$k] ) ) { return false; }
	$GLOBALS['options'][$k] = $v;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['options'][$k] ); }
function get_user_meta( $id, $k, $single = true ) { return $GLOBALS['meta'][$id][$k] ?? ( $single ? '' : array() ); }
function update_user_meta( $id, $k, $v ) { $GLOBALS['meta'][$id][$k] = $v; }
function delete_user_meta( $id, $k ) { unset( $GLOBALS['meta'][$id][$k] ); }
function get_current_user_id() { return 1; }
function get_userdata( $id ) { return $GLOBALS['users'][$id] ?? false; }
function get_users( $args ) { return array( 4 ); }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_text_field( $v ) { return strip_tags( $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $v ) ); }
function clfa_leader_years( $id ) { return get_user_meta( $id, 'clfa_leader_class', false ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return json_encode( $r['data'] ); }
function wp_remote_request( $url, $args ) {
	global $requests, $remote, $fail;
	$method = $args['method'];
	$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
	$requests[] = array( 'method' => $method, 'url' => $url, 'body' => $body );
	if ( $fail ) { return array( 'code' => $fail, 'data' => array( 'title' => 'Failure' ) ); }
	if ( str_contains( $url, '/tags' ) ) {
		if ( 'POST' === $method ) { return array( 'code' => 204, 'data' => array() ); }
		return array( 'code' => 200, 'data' => array( 'tags' => array( array( 'name' => 'CLF:Leads 2025' ), array( 'name' => 'Other newsletter' ) ), 'total_items' => 2 ) );
	}
	if ( 'GET' === $method ) {
		return array( 'code' => $remote ? 200 : 404, 'data' => $remote ?: array( 'title' => 'Resource Not Found' ) );
	}
	if ( 'PUT' === $method ) {
		$remote ??= array( 'status' => $body['status_if_new'] );
	} elseif ( 'PATCH' === $method ) {
		$remote['status'] = $body['status'];
	}
	return array( 'code' => 200, 'data' => $remote );
}
require __DIR__ . '/../includes/mailchimp-api.php';
require __DIR__ . '/../includes/mailchimp-sync.php';
function check( $condition, $label ) {
	if ( ! $condition ) { throw new Exception( $label ); }
	echo "PASS: $label\n";
}
clfa_mc_consent( 4, true, 'member_profile' );
check( get_user_meta( 4, 'clfa_mc_pending', true ) === 1, 'Consent queues sync' );
check( get_user_meta( 4, 'clfa_mc_consent_record', true )['source'] === 'member_profile', 'Consent source recorded' );
$meta[4]['clfa_class_year'] = '2020';
$meta[4]['clfa_leader_class'] = array( '2026', '2027' );
$result = clfa_mc_sync_user( 4 );
check( ! is_wp_error( $result ) && $remote['status'] === 'pending', 'New subscriber uses double opt-in' );
$puts = array_values( array_filter( $requests, fn( $r ) => 'PUT' === $r['method'] ) );
check( ! isset( $puts[0]['body']['status'] ), 'Upsert never overrides existing subscription status' );
check( $puts[0]['body']['merge_fields']['FNAME'] === 'Test', 'Name fields synced' );
$tags = end( $requests )['body']['tags'];
check( in_array( array( 'name' => 'CLF:Leads 2026', 'status' => 'active' ), $tags, true ) && in_array( array( 'name' => 'CLF:Leads 2027', 'status' => 'active' ), $tags, true ), 'Multiple leader class tags synced' );
check( in_array( array( 'name' => 'CLF:Leads 2025', 'status' => 'inactive' ), $tags, true ), 'Stale managed tag removed' );
check( ! in_array( 'Other newsletter', array_column( $tags, 'name' ), true ), 'Unrelated tags left unchanged' );
foreach ( array( 'unsubscribed', 'cleaned', 'transactional' ) as $status ) {
	$remote = array( 'status' => $status );
	$requests = array();
	$meta[4]['clfa_mc_consent'] = 1;
	clfa_mc_sync_user( 4 );
	check( count( $requests ) === 1 && ! get_user_meta( 4, 'clfa_mc_consent', true ), 'Preserve Mailchimp ' . $status . ' and clear local opt-in' );
}
$remote = array( 'status' => 'subscribed' );
$meta[4]['clfa_mc_consent'] = 1;
clfa_mc_sync_user( 4 );
check( $remote['status'] === 'subscribed', 'Existing subscribed contact remains subscribed' );
clfa_mc_consent( 4, false, 'member_profile' );
clfa_mc_sync_user( 4 );
check( $remote['status'] === 'unsubscribed', 'Member opt-out reaches Mailchimp' );
$requests = array();
clfa_mc_sync_user( 4 );
check( count( $requests ) === 1, 'Repeat opt-out is idempotent' );
$meta[4]['clfa_mc_consent'] = 1;
$remote = array( 'status' => 'subscribed' );
$users[4]->user_email = 'new@example.test';
clfa_mc_sync_user( 4 );
check( $remote['status'] === 'unsubscribed' && ! get_user_meta( 4, 'clfa_mc_consent', true ) && ! get_user_meta( 4, 'clfa_mc_identity', true ), 'Email change retires old identity and requires new consent' );
$meta[4]['clfa_mc_consent'] = 1;
$meta[4]['clfa_disabled'] = 1;
$requests = array();
clfa_mc_sync_user( 4 );
check( ! $requests, 'Inactive contacts are not enrolled' );
unset( $meta[4]['clfa_disabled'] );
$fail = 429;
clfa_mc_queue( 4 );
clfa_mc_worker();
check( get_user_meta( 4, 'clfa_mc_attempts', true ) === 1 && get_user_meta( 4, 'clfa_mc_pending', true ) === 1, 'API rate limit schedules retry' );
$meta[4]['clfa_mc_attempts'] = 4;
clfa_mc_worker();
check( ! get_user_meta( 4, 'clfa_mc_pending', true ) && str_contains( get_user_meta( 4, 'clfa_mc_result', true ), 'Retry limit' ), 'Retry limit leaves visible error' );
$fail = 0;
$options['clfa_mailchimp']['enabled'] = false;
$requests = array();
clfa_mc_worker();
check( ! $requests, 'Disabled integration makes no API calls' );
$payload = clfa_mc_member_payload( $users[4], false );
check( $payload['status_if_new'] === 'subscribed' && ! isset( $payload['status'] ), 'Single opt-in applies only to new contacts' );
check( clfa_mc_member_path( 'list', 'TEST@example.test' ) === clfa_mc_member_path( 'list', 'test@example.test' ), 'Subscriber identity is case-insensitive' );
