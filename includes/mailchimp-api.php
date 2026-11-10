<?php
defined( 'ABSPATH' ) || exit;

function clfa_mc_settings() {
	return wp_parse_args( get_option( 'clfa_mailchimp', array() ), array(
		'enabled' => false, 'audience' => '', 'double_optin' => true, 'auto_enroll' => false,
	) );
}

/* The credential stays on the WordPress server, never in HTML or JavaScript.
 * A wp-config constant takes priority; otherwise store authenticated ciphertext.
 */
function clfa_mc_key() {
	if ( defined( 'CLFA_MAILCHIMP_API_KEY' ) ) {
		return CLFA_MAILCHIMP_API_KEY;
	}
	$stored = get_option( 'clfa_mailchimp_key', '' );
	if ( ! $stored || ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$parts = json_decode( $stored, true );
	if ( ! is_array( $parts ) || ! isset( $parts['iv'], $parts['tag'], $parts['data'] ) ) {
		return '';
	}
	return openssl_decrypt( base64_decode( $parts['data'] ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, base64_decode( $parts['iv'] ), base64_decode( $parts['tag'] ) ) ?: '';
}

function clfa_mc_store_key( $key ) {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return new WP_Error( 'crypto', 'OpenSSL is required to store a Mailchimp key securely.' );
	}
	if ( ! preg_match( '/^[a-zA-Z0-9]+-us[0-9]+$/D', $key ) ) {
		return new WP_Error( 'key', 'Enter a valid Mailchimp API key, including its -us server suffix.' );
	}
	$iv = random_bytes( 12 );
	$tag = '';
	$data = openssl_encrypt( $key, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
	if ( false === $data ) {
		return new WP_Error( 'crypto', 'Could not encrypt the Mailchimp key.' );
	}
	update_option( 'clfa_mailchimp_key', wp_json_encode( array(
		'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'data' => base64_encode( $data ),
	) ), false );
	return true;
}

function clfa_mc_ready() {
	$s = clfa_mc_settings();
	return $s['enabled'] && $s['audience'] && clfa_mc_key();
}

function clfa_mc_request( $method, $path, $body = null ) {
	$key = clfa_mc_key();
	if ( ! preg_match( '/-((?:us)[0-9]+)$/D', $key, $matches ) ) {
		return new WP_Error( 'configuration', 'Mailchimp is not connected. Configure the API key in CLF → Mailchimp.' );
	}
	$args = array(
		'method' => $method, 'timeout' => 15, 'redirection' => 0,
		'headers' => array( 'Authorization' => 'Basic ' . base64_encode( 'clf:' . $key ), 'Content-Type' => 'application/json' ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$response = wp_remote_request( 'https://' . $matches[1] . '.api.mailchimp.com/3.0/' . ltrim( $path, '/' ), $args );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'network', 'Mailchimp could not be reached. The sync will retry.' );
	}
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 ) {
		// Do not log raw responses: they may contain subscriber data.
		$title = is_array( $data ) ? sanitize_text_field( $data['title'] ?? 'Request failed' ) : 'Request failed';
		return new WP_Error( 'mailchimp_' . $code, 'Mailchimp HTTP ' . $code . ': ' . $title . '. Check the audience, required merge fields, and account settings.', array( 'status' => $code ) );
	}
	if ( 204 !== $code && ! is_array( $data ) ) {
		return new WP_Error( 'response', 'Mailchimp returned an unexpected response.' );
	}
	return $data ?: array();
}

function clfa_mc_member_path( $audience, $email ) {
	return 'lists/' . rawurlencode( $audience ) . '/members/' . md5( strtolower( trim( $email ) ) );
}

function clfa_mc_missing( $result ) {
	return is_wp_error( $result ) && 'mailchimp_404' === $result->get_error_code();
}

/* Pure payload builder: NEVER include "status" in an upsert. Existing
 * unsubscribed/cleaned/pending contacts must keep their Mailchimp status.
 */
function clfa_mc_member_payload( $user, $double_optin ) {
	return array(
		'email_address' => $user->user_email,
		'status_if_new' => $double_optin ? 'pending' : 'subscribed',
		'merge_fields' => array( 'FNAME' => $user->first_name, 'LNAME' => $user->last_name ),
	);
}

function clfa_mc_tags( $user_id ) {
	$tags = array( 'CLF:Website' );
	$class = get_user_meta( $user_id, 'clfa_class_year', true );
	if ( $class ) {
		$tags[] = 'CLF:Class of ' . $class;
	}
	$years = clfa_leader_years( $user_id );
	if ( $years ) {
		$tags[] = 'CLF:Class leader';
		foreach ( $years as $year ) {
			$tags[] = 'CLF:Leads ' . $year;
		}
	}
	return $tags;
}
