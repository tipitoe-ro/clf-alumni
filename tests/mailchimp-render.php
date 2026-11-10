<?php
/* Render the real admin template with synthetic WordPress data for layout checks. */
if ( PHP_SAPI !== 'cli' ) { exit; }
ob_start();
require __DIR__ . '/mailchimp-test.php';
ob_end_clean();
function current_user_can( ...$args ) { return $GLOBALS['test_admin_access'] ?? true; }
function sanitize_text_field_render( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_nonce_field( ...$args ) {}
function checked( $v, $expected = true ) { if ( $v == $expected ) { echo ' checked'; } }
function disabled( $v ) { if ( $v ) { echo ' disabled'; } }
function add_query_arg( ...$args ) { return '#'; }
function rest_url( $path ) { return 'https://wordpress.example.test/wp-json/' . $path; }
class WP_User_Query {
	function __construct( $args ) {}
	function get_results() { return array(); }
	function get_total() { return 0; }
}
require __DIR__ . '/../includes/mailchimp-admin.php';
echo '<!doctype html><html><head><meta charset="utf-8"><style>
body{background:#f0f0f1;color:#1d2327;font:14px/1.5 Arial,sans-serif;margin:32px}
.wrap{max-width:1100px;margin:auto}h1{font-size:25px;font-weight:400}h2{font-size:20px}
.form-table{width:100%;border-collapse:collapse}.form-table th{width:210px;text-align:left;vertical-align:top;padding:20px 10px 20px 0}.form-table td{padding:20px 0}
.description{color:#646970;font-size:13px;margin:6px 0}.regular-text{width:360px}input:not([type=checkbox]){padding:7px;border:1px solid #8c8f94;border-radius:4px}
.button{border:1px solid #2271b1;border-radius:3px;color:#2271b1;background:white;padding:7px 12px;cursor:pointer}.button-primary{background:#2271b1;color:white}
.widefat{width:100%;border-collapse:collapse;background:white}.widefat th,.widefat td{text-align:left;padding:10px;border:1px solid #ddd}
hr{border:0;border-top:1px solid #ccc;margin:24px 0}</style></head><body>';
clfa_mc_admin_page();
echo '</body></html>';
