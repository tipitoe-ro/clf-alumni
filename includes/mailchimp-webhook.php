<?php
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
register_rest_route( 'clf-alumni/v1', '/mailchimp', array(
'methods' => array( 'GET', 'POST' ),
'permission_callback' => 'clfa_mc_webhook_permission',
'callback' => 'clfa_mc_webhook',
) );
} );

function clfa_mc_webhook( $request ) {
$response = clfa_mc_process_webhook( $request );
// Only completed POSTs count, not URL probes or requests needing a retry.
// Store no request data, credentials, contact identifiers or API responses.
if ( 'POST' === $request->get_method() && ! is_wp_error( $response ) ) {
update_option( 'clfa_mc_webhook_last_accepted', time(), false );
}
return $response;
}

function clfa_mc_process_webhook( $request ) {
// Mailchimp validates a callback URL with GET; never change data on that probe.
if ( 'GET' === $request->get_method() ) {
return new WP_REST_Response( array( 'ok' => true ), 200 );
}
if ( ! clfa_mc_ready() ) {
return new WP_Error( 'unavailable', 'Mailchimp integration is paused or unconfigured.', array( 'status' => 503 ) );
}
$body = $request->get_body_params();
$data = $body['data'] ?? null;
if ( ! is_string( $body['type'] ?? null ) || ! is_array( $data )
|| ! is_string( $data['list_id'] ?? null ) || ! is_string( $data['email'] ?? null )
|| ! is_email( $data['email'] ) ) {
return new WP_Error( 'payload', 'Invalid webhook payload.', array( 'status' => 400 ) );
}
$s = clfa_mc_settings();
if ( $data['list_id'] !== $s['audience'] ) {
return new WP_Error( 'audience', 'Unexpected audience.', array( 'status' => 400 ) );
}
$ok = new WP_REST_Response( array( 'ok' => true ), 200 );
if ( ! in_array( $body['type'], array( 'subscribe', 'unsubscribe', 'cleaned' ), true ) ) {
return $ok;
}
$user = get_user_by( 'email', $data['email'] );
if ( ! $user ) {
return $ok;
}
$uid = $user->ID;
$identity = get_user_meta( $uid, 'clfa_mc_identity', true );
// Do not enroll arbitrary audience contacts or apply an old address's events.
if ( ! is_array( $identity ) || ( $identity['audience'] ?? '' ) !== $s['audience']
|| strtolower( $identity['email'] ?? '' ) !== strtolower( $data['email'] ) ) {
return $ok;
}
// Share the worker lock. A concurrent worker/event must retry, not overwrite
// a newer result. Only the cron worker recovers abandoned locks after 10 minutes.
if ( ! add_option( 'clfa_mc_lock', time(), '', false ) ) {
return new WP_Error( 'busy', 'Sync in progress. Retry this event.', array( 'status' => 503 ) );
}
try {
$revision = get_user_meta( $uid, 'clfa_mc_revision', true );
$remote = clfa_mc_request( 'GET', clfa_mc_member_path( $s['audience'], $data['email'] ) );
if ( is_wp_error( $remote ) && ! clfa_mc_missing( $remote ) ) {
// This read/increment/write is protected by the shared sync lock above.
$health = get_option( 'clfa_mc_webhook_verification', array() );
update_option( 'clfa_mc_webhook_verification', array(
'failures' => max( 0, (int) ( $health['failures'] ?? 0 ) ) + 1,
'last_failure' => time(),
), false );
return new WP_Error( 'verification', 'Could not verify contact. Retry this event.', array( 'status' => 503 ) );
}
if ( $revision !== get_user_meta( $uid, 'clfa_mc_revision', true )
|| strtolower( get_userdata( $uid )->user_email ?? '' ) !== strtolower( $data['email'] ) ) {
return new WP_Error( 'changed', 'Contact changed. Retry this event.', array( 'status' => 503 ) );
}
// Use current API state, not delivery order, timestamps or untrusted event data.
$status = clfa_mc_missing( $remote ) ? 'removed' : ( $remote['status'] ?? '' );
if ( in_array( $status, array( 'unsubscribed', 'cleaned', 'transactional', 'archived', 'removed' ), true ) ) {
if ( get_user_meta( $uid, 'clfa_mc_consent', true ) ) {
update_user_meta( $uid, 'clfa_mc_consent', 0 );
update_user_meta( $uid, 'clfa_mc_consent_record', array(
'source' => 'mailchimp_webhook', 'actor' => 0, 'at' => gmdate( 'c' ), 'consent' => false,
) );
}
$result = 'Mailchimp status: ' . $status . '. Not subscribed.';
} elseif ( 'subscribed' === $status && get_user_meta( $uid, 'clfa_mc_consent', true ) ) {
$result = 'Synced — subscribed (email confirmation verified).';
} else {
// A subscribe event NEVER grants consent or cancels a queued local opt-out.
return $ok;
}
update_user_meta( $uid, 'clfa_mc_result', $result );
update_user_meta( $uid, 'clfa_mc_checked', gmdate( 'c' ) );
// Leave any pending work intact (e.g. tags or a local opt-out).
return $ok;
} finally {
delete_option( 'clfa_mc_lock' );
}
}
