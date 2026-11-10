<?php
/* Standalone regression checks. Run: php clf-alumni/tests/leaders-test.php */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ );
$meta = array();
$admin = true;
function add_action( ...$args ) {}
function __( $s, $domain = '' ) { return $s; }
function current_user_can( ...$args ) { return $GLOBALS['admin']; }
function sanitize_text_field( $s ) { return strip_tags( $s ); }
function wp_unslash( $s ) { return $s; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === $action; }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][$id][$key] ?? array(); }
function add_user_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key][] = $value; }
function delete_user_meta( $id, $key, $value ) {
	$GLOBALS['meta'][$id][$key] = array_values( array_diff( $GLOBALS['meta'][$id][$key], array( $value ) ) );
}
class WP_Error { function __construct( ...$args ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
require __DIR__ . '/../includes/leaders.php';
function check( $condition, $label ) {
	if ( ! $condition ) { throw new Exception( $label ); }
	echo "PASS: $label\n";
}
check( clfa_parse_leader_years( '2026, 2027, 2026' ) === array( '2026', '2027' ), 'Deduplicate multiple classes' );
check( is_wp_error( clfa_parse_leader_years( '2026,abc' ) ), 'Reject invalid year without partial saves' );
$_POST = array( 'clfa_leaders_nonce' => 'clfa_leaders_4', 'clfa_leader_classes' => '2026,2027' );
clfa_save_leader_profile( 4 );
check( clfa_leader_years( 4 ) === array( '2026', '2027' ), 'Admin assigns two classes to a contact independently of role' );
$admin = false;
$_POST['clfa_leader_classes'] = '';
clfa_save_leader_profile( 4 );
check( count( clfa_leader_years( 4 ) ) === 2, 'Non-admin cannot remove tags' );
$admin = true;
$_POST['clfa_leaders_nonce'] = 'wrong';
clfa_save_leader_profile( 4 );
check( count( clfa_leader_years( 4 ) ) === 2, 'Reject forged nonce' );
$_POST['clfa_leaders_nonce'] = 'clfa_leaders_4';
$_POST['clfa_leader_classes'] = '2027';
clfa_save_leader_profile( 4 );
check( clfa_leader_years( 4 ) === array( '2027' ), 'Remove one assignment while preserving another' );
$_POST['clfa_leader_classes'] = 'bad';
clfa_save_leader_profile( 4 );
check( clfa_leader_years( 4 ) === array( '2027' ), 'Invalid data preserves previous assignments' );
$_POST['clfa_leader_classes'] = '';
clfa_save_leader_profile( 4 );
check( clfa_leader_years( 4 ) === array(), 'Blank removes leader tag' );
check( clfa_leader_query( '2027' )[0]['compare'] === '=', 'Class filtering is exact, not substring' );
check( clfa_leader_query()[0]['compare'] === 'EXISTS', 'All leaders query uses tag existence' );

function wp_parse_args( $v, $defaults ) { return array_merge( $defaults, $v ); }
function get_option( $key, $default ) { return array( 'from_name' => 'CLF Alumni', 'from_email' => 'test@example.test' ); }
function esc_url( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_mail( $to, $subject, $body, $headers ) {
	$GLOBALS['mail'] = compact( 'subject', 'body', 'headers' );
	return true;
}
require __DIR__ . '/../includes/email.php';
check( clfa_email_settings()['from_name'] === 'CLF', 'Legacy sender name normalized' );
clfa_send_branded( 'nobody@example.test', 'CLF test', '<p>Test</p>' );
check( stripos( json_encode( $GLOBALS['mail'] ), 'alumni' ) === false, 'Branded email shell and sender do not label recipients alumni' );
