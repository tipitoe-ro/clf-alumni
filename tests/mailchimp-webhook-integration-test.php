<?php
// Real WordPress REST server, option locking, users and HTTP API.
// Intentionally separate from the doubles in mailchimp-webhook-test.php.
require __DIR__ . '/wordpress-integration/bootstrap.php';

function verify( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}
function dispatch_webhook( $method = 'POST', $query = array( 'key' => 'synthetic-webhook-key' ), $body = null ) {
	$request = new WP_REST_Request( $method, '/clf-alumni/v1/mailchimp' );
	$request->set_query_params( $query );
	$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
	$request->set_body( $body ?? http_build_query( array(
		'type' => 'unsubscribe',
		'data' => array( 'list_id' => 'test-list', 'email' => 'member+rest@example.test' ),
	) ) );
	// CLI dispatch mirrors PHP's $_POST. The wire tests below separately exercise
	// real PHP form parsing and WP_REST_Server::serve_request over HTTP.
	$params = array();
	parse_str( $request->get_body(), $params );
	$request->set_body_params( $params );
	return rest_do_request( $request );
}
function expect_status( $response, $status, $code = null ) {
	verify( $response->get_status() === $status, "REST status $status (actual " . $response->get_status() . ': ' . wp_json_encode( $response->get_data() ) . ')' );
	if ( null !== $code ) {
		verify( ( $response->get_data()['code'] ?? '' ) === $code, "REST error $code" );
	}
}
function reset_fixture() {
	global $uid, $calls;
	wp_cache_flush();
	foreach ( array( 'clfa_mc_lock', 'clfa_mc_webhook_last_accepted', 'clfa_mc_webhook_verification' ) as $name ) {
		delete_option( $name );
	}
	update_option( 'clfa_mailchimp', array( 'enabled' => true, 'audience' => 'test-list' ), false );
	update_option( 'clfa_mc_webhook_token', 'synthetic-webhook-key', false );
	clfa_mc_store_key( 'syntheticOnly-us1' );
	foreach ( get_user_meta( $uid ) as $key => $value ) {
		if ( str_starts_with( $key, 'clfa_mc_' ) ) {
			delete_user_meta( $uid, $key );
		}
	}
	update_user_meta( $uid, 'clfa_mc_consent', 1 );
	update_user_meta( $uid, 'clfa_mc_identity', array( 'email' => 'member+rest@example.test', 'audience' => 'test-list' ) );
	update_user_meta( $uid, 'clfa_mc_pending', 1 );
	update_user_meta( $uid, 'clfa_mc_next', time() - 1 );
	update_user_meta( $uid, 'clfa_mc_revision', 'synthetic-revision' );
	$calls = 0;
	clfa_test_http( function () use ( &$calls ) {
		++$calls;
		return clfa_test_response();
	} );
}
function start_worker( $mode ) {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/wordpress-integration/worker.php', $mode ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', getenv( 'CLFA_TEST_ROOT' ) . '/worker.log', 'a' ), 2 => array( 'redirect', 1 ) ), $pipes );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start worker.' );
	}
	fclose( $pipes[0] );
	return $process;
}
function finish_worker( $process ) {
	$deadline = microtime( true ) + 20;
	do {
		$status = proc_get_status( $process );
		if ( ! $status['running'] ) {
			proc_close( $process );
			verify( 0 === $status['exitcode'], 'Separate worker exits successfully: ' . file_get_contents( getenv( 'CLFA_TEST_ROOT' ) . '/worker.log' ) );
			return;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	proc_terminate( $process );
	proc_close( $process );
	throw new RuntimeException( 'Worker timed out.' );
}

$uid = wp_insert_user( array( 'user_login' => 'synthetic-member', 'user_email' => 'member+rest@example.test', 'user_pass' => 'synthetic-only' ) );
verify( ! is_wp_error( $uid ), 'Synthetic member created in real WordPress' );
reset_fixture();
verify( 0 === get_current_user_id(), 'Requests are anonymous, without admin bypass' );
verify( isset( rest_get_server()->get_routes()['/clf-alumni/v1/mailchimp'] ), 'Plugin registers the actual REST route' );
$before = get_user_meta( $uid );
foreach ( array( 'GET', 'POST' ) as $method ) {
	foreach ( array( array(), array( 'key' => 'wrong' ), array( 'key' => array( 'synthetic-webhook-key' ) ) ) as $query ) {
		expect_status( dispatch_webhook( $method, $query ), 403, 'forbidden' );
	}
}
expect_status( dispatch_webhook( 'POST', array(), 'key=synthetic-webhook-key' ), 403, 'forbidden' );
verify( 0 === $calls && $before === get_user_meta( $uid ) && ! get_option( 'clfa_mc_webhook_last_accepted' ), 'Denied requests never reach handler side effects' );
update_option( 'clfa_mc_webhook_token', 'replacement-key', false );
foreach ( array( 'GET', 'POST' ) as $method ) {
	expect_status( dispatch_webhook( $method ), 403, 'forbidden' );
}
expect_status( dispatch_webhook( 'GET', array( 'key' => 'replacement-key' ) ), 200 );
delete_option( 'clfa_mc_webhook_token' );
expect_status( dispatch_webhook( 'GET', array( 'key' => 'replacement-key' ) ), 403, 'forbidden' );
verify( ! get_option( 'clfa_mc_webhook_token' ), 'Permission check does not regenerate a revoked key' );

reset_fixture();
$before = get_user_meta( $uid );
update_option( 'clfa_mailchimp', array( 'enabled' => false ), false );
delete_option( 'clfa_mailchimp_key' );
expect_status( dispatch_webhook( 'GET' ), 200 );
verify( 0 === $calls && $before === get_user_meta( $uid ) && ! get_option( 'clfa_mc_lock' )
	&& ! get_option( 'clfa_mc_webhook_last_accepted' ) && ! get_option( 'clfa_mc_webhook_verification' ), 'GET validation works while paused without API, lock, health or member changes' );
expect_status( dispatch_webhook(), 503, 'unavailable' );

reset_fixture();
foreach ( array( '', 'type[]=unsubscribe&data=x', 'type=unsubscribe&data[email]=bad&data[list_id]=test-list',
	'type=unsubscribe&data[email]=member%2Brest%40example.test&data[list_id]=wrong' ) as $body ) {
	expect_status( dispatch_webhook( 'POST', array( 'key' => 'synthetic-webhook-key' ), $body ), 400 );
}
verify( 0 === $calls && ! get_option( 'clfa_mc_webhook_last_accepted' ), 'Malformed form bodies are rejected before HTTP or health updates' );
expect_status( dispatch_webhook(), 200 );
verify( 1 === $calls && '0' === get_user_meta( $uid, 'clfa_mc_consent', true ), 'REST dispatch accepts nested form fields and encoded plus in email' );
verify( '1' === get_user_meta( $uid, 'clfa_mc_pending', true ) && ! get_option( 'clfa_mc_lock' )
	&& get_option( 'clfa_mc_webhook_last_accepted' ) > 0, 'Accepted POST preserves pending work, records health and releases lock' );

foreach ( array( 401, 429, 500 ) as $http_code ) {
	reset_fixture();
	$before = get_user_meta( $uid );
	clfa_test_http( fn() => clfa_test_response( '', $http_code ) );
	expect_status( dispatch_webhook(), 503, 'verification' );
	verify( $before === get_user_meta( $uid ) && ! get_option( 'clfa_mc_lock' )
		&& ! get_option( 'clfa_mc_webhook_last_accepted' )
		&& 1 === get_option( 'clfa_mc_webhook_verification' )['failures'], "Remote HTTP $http_code retries without member mutation and releases lock" );
	clfa_test_http( fn() => clfa_test_response() );
	expect_status( dispatch_webhook(), 200 );
}

// The real worker holds the DB-backed lock on an independent PHP connection.
reset_fixture();
update_user_meta( $uid, 'clfa_mc_consent', 0 );
$worker = start_worker( 'hold' );
try {
	$deadline = microtime( true ) + 15;
	while ( ! is_file( "$root/worker-ready" ) && microtime( true ) < $deadline ) {
		usleep( 10000 );
	}
	verify( is_file( "$root/worker-ready" ), 'Separate worker acquired lock and reached mocked HTTP' );
	wp_cache_flush();
	$lock = get_option( 'clfa_mc_lock' );
	$before = get_user_meta( $uid );
	expect_status( dispatch_webhook(), 503, 'busy' );
	verify( $lock && $lock === get_option( 'clfa_mc_lock' ) && 0 === $calls
		&& $before === get_user_meta( $uid ) && ! get_option( 'clfa_mc_webhook_last_accepted' )
		&& ! get_option( 'clfa_mc_webhook_verification' ), 'Contending webhook preserves worker lock, member state and health' );
} finally {
	file_put_contents( "$root/worker-release", 'release' );
	finish_worker( $worker );
}
wp_cache_flush();
verify( ! get_option( 'clfa_mc_lock' ), 'Worker releases database lock' );
expect_status( dispatch_webhook(), 200 );

// Opposite direction: while the webhook owns the lock, a real worker cannot sync.
reset_fixture();
clfa_test_http( function () use ( $uid ) {
	$before = get_user_meta( $uid );
	$lock = get_option( 'clfa_mc_lock' );
	finish_worker( start_worker( 'blocked' ) );
	wp_cache_flush();
	verify( $lock && (int) $lock === (int) get_option( 'clfa_mc_lock' ) && $before === get_user_meta( $uid ), 'Worker cannot overwrite state or release webhook-owned lock' );
	return clfa_test_response();
} );
expect_status( dispatch_webhook(), 200 );
verify( ! get_option( 'clfa_mc_lock' ), 'Webhook releases lock after concurrent worker returns' );
reset_fixture();
add_option( 'clfa_mc_lock', time() - 700, '', false );
expect_status( dispatch_webhook(), 503, 'busy' );
verify( get_option( 'clfa_mc_lock' ) && 0 === $calls, 'Webhook does not steal even an abandoned worker lock' );

// Actual HTTP ingress: PHP parses the form, WordPress builds the request and
// runs authentication, routing, permission callbacks and response serialization.
$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:0', __DIR__ . '/wordpress-integration/router.php' ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', "$root/http.log", 'a' ), 2 => array( 'redirect', 1 ) ), $pipes );
verify( is_resource( $server ), 'HTTP test server started' );
fclose( $pipes[0] );
try {
	$deadline = microtime( true ) + 5;
	do {
		$log = file_get_contents( "$root/http.log" );
		if ( preg_match( '~http://127\.0\.0\.1:(\d+)~', $log, $match ) ) {
			break;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );
	verify( ! empty( $match[1] ), 'HTTP server bound an ephemeral localhost port' );
	$url = 'http://127.0.0.1:' . $match[1] . '/?rest_route=/clf-alumni/v1/mailchimp';
	foreach ( array(
		array( 'GET', '', 403 ),
		array( 'GET', '&key=synthetic-webhook-key', 200 ),
		array( 'POST', '&key%5B%5D=synthetic-webhook-key', 403 ),
		array( 'POST', '&key=synthetic-webhook-key', 200 ),
	) as [ $method, $query, $expected ] ) {
		reset_fixture();
		$handle = curl_init( $url . $query );
		curl_setopt_array( $handle, array(
			CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
			CURLOPT_CUSTOMREQUEST => $method,
		) );
		if ( 'POST' === $method ) {
			curl_setopt( $handle, CURLOPT_HTTPHEADER, array( 'Content-Type: application/x-www-form-urlencoded' ) );
			curl_setopt( $handle, CURLOPT_POSTFIELDS, 'type=unsubscribe&data%5Blist_id%5D=test-list&data%5Bemail%5D=member%2Brest%40example.test' );
		}
		$body = curl_exec( $handle );
		$status = curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );
		verify( $status === $expected && is_array( json_decode( $body, true ) ), "HTTP $method returns serialized REST $expected" );
		wp_cache_flush();
		$accepted = 'POST' === $method && 200 === $expected;
		verify( get_user_meta( $uid, 'clfa_mc_consent', true ) === ( $accepted ? '0' : '1' )
			&& (bool) get_option( 'clfa_mc_webhook_last_accepted' ) === $accepted, 'Wire request mutates consent and health only for authenticated POST' );
	}
} finally {
	proc_terminate( $server );
	proc_close( $server );
}
echo "WordPress REST integration tests passed.\n";
