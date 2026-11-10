<?php
// Real WordPress queries/options/meta and production worker; only HTTP is mocked.
require __DIR__ . '/bootstrap.php';

function retry_verify( $condition, $message ) {
if ( ! $condition ) {
throw new RuntimeException( $message );
}
echo "PASS: $message\n";
}

function retry_meta( $uid, $key ) {
return get_user_meta( $uid, 'clfa_mc_' . $key, true );
}

function retry_snapshot( $uid ) {
wp_cache_flush();
return get_user_meta( $uid );
}

// An empty step list forbids all HTTP, including requests to unrelated users.
function retry_run( $uid, $steps ) {
$index = 0;
$path = 'https://us1.api.mailchimp.com/3.0/' .
clfa_mc_member_path( 'test-list', get_userdata( $uid )->user_email );
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$index, $steps, $path ) {
if ( ! isset( $steps[$index] ) ) {
throw new RuntimeException( 'Unexpected outbound HTTP request.' );
}
$step = $steps[$index++];
retry_verify( $url === $path . ( $step['suffix'] ?? '' )
&& $args['method'] === $step['method'], 'Worker requests only the expected member endpoint and method' );
retry_verify( false !== get_option( 'clfa_mc_lock', false ), 'Worker holds lock during HTTP' );
return $step['response'];
}, 10, 3 );
try {
// Exercise the registered cron hook, not a copied worker implementation.
do_action( 'clfa_mc_tick' );
retry_verify( count( $steps ) === $index, 'Worker consumes exactly the expected HTTP requests' );
} finally {
remove_all_filters( 'pre_http_request' );
add_filter( 'pre_http_request', function () {
throw new RuntimeException( 'Unexpected outbound HTTP request.' );
}, 1000 );
}
retry_verify( false === get_option( 'clfa_mc_lock', false ), 'Worker deletes its lock after this run' );
}

update_option( 'clfa_mailchimp', array( 'enabled' => true, 'audience' => 'test-list' ), false );
retry_verify( true === clfa_mc_store_key( 'syntheticRetriesOnly-us1' ), 'Synthetic worker connection configured' );
$users = array();
try {
$failures = array(
'rate-limit' => array( clfa_test_response( 'subscribed', 429 ), 'Mailchimp HTTP 429: Synthetic response. Check the audience, required merge fields, and account settings.' ),
'server-error' => array( clfa_test_response( 'subscribed', 500 ), 'Mailchimp HTTP 500: Synthetic response. Check the audience, required merge fields, and account settings.' ),
'network' => array( new WP_Error( 'http_request_failed', 'Synthetic transport failure' ), 'Mailchimp could not be reached. The sync will retry.' ),
);
foreach ( $failures as $kind => list( $response, $diagnostic ) ) {
$uid = wp_insert_user( array(
'user_login' => "synthetic-retry-$kind", 'user_email' => "retry-$kind@example.test",
'user_pass' => 'synthetic-only-password', 'role' => 'clf_alumni',
) );
retry_verify( ! is_wp_error( $uid ), "$kind: synthetic member created" );
$users[] = $uid;
update_user_meta( $uid, 'clfa_mc_consent', 1 );
clfa_mc_queue( $uid );
$revision = retry_meta( $uid, 'revision' );

// Future work must be excluded by the real WP_User_Query, not by a stub.
update_user_meta( $uid, 'clfa_mc_next', time() + 86400 );
$before = retry_snapshot( $uid );
retry_run( $uid, array() );
retry_verify( $before === retry_snapshot( $uid ), "$kind: future-dated queue is completely unchanged" );

// Advance eligibility via metadata rather than sleeping or replacing time().
// These explicit expectations protect the five-attempt, one-hour-cap contract.
foreach ( array( 300, 600, 1200, 2400, 3600 ) as $index => $delay ) {
$attempt = $index + 1;
update_user_meta( $uid, 'clfa_mc_next', time() - 1 );
$start = time();
retry_run( $uid, array( array( 'method' => 'GET', 'response' => $response ) ) );
$end = time();
wp_cache_flush();
retry_verify( $attempt === (int) retry_meta( $uid, 'attempts' ), "$kind: failure increments attempts exactly once to $attempt" );
$next = (int) retry_meta( $uid, 'next' );
retry_verify( $next >= $start + $delay && $next <= $end + $delay, "$kind: attempt $attempt schedules the expected $delay-second delay" );
$suffix = $attempt < 5 ? ' Retry scheduled.' : ' Retry limit reached; use Sync selected after fixing the issue.';
retry_verify( $diagnostic . $suffix === retry_meta( $uid, 'result' ), "$kind: attempt $attempt retains the diagnostic and correct recovery instruction" );
$checked = strtotime( retry_meta( $uid, 'checked' ) );
retry_verify( $checked >= $start && $checked <= $end, "$kind: attempt $attempt records its check time" );
retry_verify( $revision === retry_meta( $uid, 'revision' ) && 1 === (int) retry_meta( $uid, 'consent' ),
"$kind: failures preserve revision and consent" );
retry_verify( $attempt < 5
? 1 === (int) retry_meta( $uid, 'pending' )
: ! metadata_exists( 'user', $uid, 'clfa_mc_pending' ),
"$kind: pending work remains only below the retry limit" );
$before = retry_snapshot( $uid );
retry_run( $uid, array() );
retry_verify( $before === retry_snapshot( $uid ), "$kind: immediate cron rerun does not retry or mutate state" );
}

// Even when the last backoff expires, exhaustion must prevent more attempts.
update_user_meta( $uid, 'clfa_mc_next', time() - 1 );
$before = retry_snapshot( $uid );
for ( $run = 0; $run < 2; ++$run ) {
retry_run( $uid, array() );
retry_verify( $before === retry_snapshot( $uid ), "$kind: exhausted member stays stopped even when due" );
}

$start = time();
clfa_mc_queue( $uid );
wp_cache_flush();
retry_verify( 0 === (int) retry_meta( $uid, 'attempts' )
&& 1 === (int) retry_meta( $uid, 'pending' )
&& $revision !== retry_meta( $uid, 'revision' )
&& (int) retry_meta( $uid, 'next' ) >= $start
&& (int) retry_meta( $uid, 'next' ) <= time()
&& 'Queued for Mailchimp sync.' === retry_meta( $uid, 'result' ),
"$kind: explicit requeue resets attempts, deadline, message and revision" );
retry_run( $uid, array(
array( 'method' => 'GET', 'response' => clfa_test_response( 'subscribed' ) ),
array( 'method' => 'PUT', 'response' => clfa_test_response( 'pending' ) ),
array( 'method' => 'GET', 'suffix' => '/tags?count=1000', 'response' => clfa_test_response() ),
array( 'method' => 'POST', 'suffix' => '/tags', 'response' => clfa_test_response( 'pending', 204 ) ),
) );
wp_cache_flush();
retry_verify( ! metadata_exists( 'user', $uid, 'clfa_mc_pending' )
&& 0 === (int) retry_meta( $uid, 'attempts' )
&& 'Synced — pending (awaiting email confirmation).' === retry_meta( $uid, 'result' )
&& array( 'email' => get_userdata( $uid )->user_email, 'audience' => 'test-list' ) === retry_meta( $uid, 'identity' ),
"$kind: requeued member syncs successfully and clears pending work" );
$before = retry_snapshot( $uid );
retry_run( $uid, array() );
retry_verify( $before === retry_snapshot( $uid ), "$kind: successful member is not processed again" );
}
} finally {
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $users as $uid ) {
wp_delete_user( $uid );
}
}
echo "Worker retry WordPress integration tests passed.\n";
