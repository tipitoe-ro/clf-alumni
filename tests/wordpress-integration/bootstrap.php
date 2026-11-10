<?php
$root = getenv( 'CLFA_TEST_ROOT' );
if ( ! $root || ! is_file( "$root/wordpress/wp-load.php" ) || ! is_dir( "$root/db" ) ) {
	throw new RuntimeException( 'Run tests/run-wordpress-integration.sh instead.' );
}
require "$root/wordpress/wp-load.php";
// Load the actual plugin, not copies of its REST registration or handlers.
require_once dirname( __DIR__, 2 ) . '/clf-alumni.php';
wp_set_current_user( 0 );
add_filter( 'pre_wp_mail', '__return_false' );
// Fail closed: every HTTP request must be explicitly handled by the test.
add_filter( 'pre_http_request', function () {
	throw new RuntimeException( 'Unexpected outbound HTTP request.' );
}, 1000 );

function clfa_test_http( $callback ) {
	remove_all_filters( 'pre_http_request' );
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $callback ) {
		$expected = 'https://us1.api.mailchimp.com/3.0/lists/test-list/members/' . md5( 'member+rest@example.test' );
		if ( $url !== $expected || 'GET' !== $args['method'] ) {
			throw new RuntimeException( 'Unexpected HTTP URL or method.' );
		}
		return $callback();
	}, 10, 3 );
}

function clfa_test_response( $status = 'unsubscribed', $code = 200 ) {
	return array(
		'headers' => array(), 'body' => wp_json_encode( array( 'status' => $status, 'title' => 'Synthetic response' ) ),
		'response' => array( 'code' => $code, 'message' => 'Synthetic response' ),
		'cookies' => array(),
	);
}
