<?php
// Real WordPress mutations fire the production hooks; only HTTP is mocked.
require __DIR__ . '/bootstrap.php';

function profile_verify( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}

function profile_meta( $uid, $key ) {
	return get_user_meta( $uid, 'clfa_mc_' . $key, true );
}

function profile_fixture() {
	static $number = 0;
	++$number;
	$uid = wp_insert_user( array(
		'user_login' => "synthetic-profile-$number",
		'user_email' => "profile-$number@example.test",
		'user_pass' => 'synthetic-only-password',
	) );
	profile_verify( ! is_wp_error( $uid ), 'Synthetic profile created' );
	update_user_meta( $uid, 'clfa_mc_consent', 1 );
	update_user_meta( $uid, 'clfa_mc_identity', array(
		'email' => get_userdata( $uid )->user_email, 'audience' => 'test-list',
	) );
	return $uid;
}

// Ordered expectations reject extra requests, wrong identities, and wrong
// payloads, and assert that every required request actually happened.
function profile_http( $steps, $run ) {
	$index = 0;
	remove_all_filters( 'pre_http_request' );
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $steps, &$index ) {
		if ( ! isset( $steps[$index] ) ) {
			throw new RuntimeException( 'Unexpected extra HTTP request.' );
		}
		$step = $steps[$index++];
		profile_verify( $url === 'https://us1.api.mailchimp.com/3.0/' . $step['path']
			&& $args['method'] === $step['method'], 'Expected HTTP method and identity' );
		if ( isset( $step['body'] ) ) {
			profile_verify( json_decode( $args['body'], true ) === $step['body'], 'Expected HTTP payload' );
		}
		if ( isset( $step['during'] ) ) {
			$step['during']();
		}
		return clfa_test_response( $step['status'] ?? 'subscribed', $step['code'] ?? 200 );
	}, 10, 3 );
	try {
		$run();
		profile_verify( $index === count( $steps ), 'All expected HTTP requests consumed' );
	} finally {
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', function () {
			throw new RuntimeException( 'Unexpected outbound HTTP request.' );
		}, 1000 );
	}
}

function profile_change_email( $uid ) {
	$result = wp_update_user( array( 'ID' => $uid, 'user_email' => "changed-$uid@example.test" ) );
	profile_verify( ! is_wp_error( $result ), 'Real WordPress profile update succeeds' );
}

function profile_retire( $path ) {
	return array(
		array( 'method' => 'GET', 'path' => $path ),
		array( 'method' => 'PATCH', 'path' => $path, 'body' => array( 'status' => 'unsubscribed' ) ),
	);
}

update_option( 'clfa_mailchimp', array( 'enabled' => true, 'audience' => 'test-list' ), false );
profile_verify( true === clfa_mc_store_key( 'syntheticProfileOnly-us1' ), 'Synthetic connection configured' );
$users = array();
try {
	$uid = $users[] = profile_fixture();
	wp_set_current_user( $uid );
	$identity = profile_meta( $uid, 'identity' );
	$path = clfa_mc_member_path( $identity['audience'], $identity['email'] );
	profile_change_email( $uid );
	profile_verify( ! profile_meta( $uid, 'consent' ) && profile_meta( $uid, 'pending' )
		&& profile_meta( $uid, 'revision' ), 'Email change revokes consent and queues retirement' );
	$record = profile_meta( $uid, 'consent_record' );
	profile_verify( $record['source'] === 'email_changed' && $record['actor'] === $uid
		&& false === $record['consent'] && ! empty( $record['at'] ), 'Email change records consent revocation' );
	profile_verify( profile_meta( $uid, 'identity' ) === $identity, 'Old identity retained until retirement succeeds' );
	// Failure must retain the old address and retry, never address the new one.
	profile_http( array( array( 'method' => 'GET', 'path' => $path, 'code' => 500 ) ), 'clfa_mc_worker' );
	profile_verify( profile_meta( $uid, 'identity' ) === $identity && ! profile_meta( $uid, 'consent' )
		&& profile_meta( $uid, 'pending' ) && 1 === (int) profile_meta( $uid, 'attempts' )
		&& profile_meta( $uid, 'next' ) > time(), 'Failed retirement retains identity and schedules retry without consent' );
	update_user_meta( $uid, 'clfa_mc_next', time() - 1 );
	profile_http( profile_retire( $path ), 'clfa_mc_worker' );
	profile_verify( ! profile_meta( $uid, 'identity' ) && ! profile_meta( $uid, 'pending' )
		&& ! profile_meta( $uid, 'consent' ), 'Retry retires old identity without subscribing new email' );
	profile_http( array(), 'clfa_mc_worker' );

	// Actual add/update/delete APIs, for every watched key, not direct handler calls.
	$uid = $users[] = profile_fixture();
	foreach ( array( 'first_name', 'last_name', 'clfa_class_year', 'clfa_leader_class' ) as $key ) {
		delete_user_meta( $uid, $key );
		foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
			delete_user_meta( $uid, 'clfa_mc_pending' );
			$before = profile_meta( $uid, 'revision' );
			if ( 'add' === $operation ) {
				add_user_meta( $uid, $key, '2024' );
			} elseif ( 'update' === $operation ) {
				update_user_meta( $uid, $key, '2025' );
			} else {
				delete_user_meta( $uid, $key );
			}
			profile_verify( profile_meta( $uid, 'pending' ) && $before !== profile_meta( $uid, 'revision' ),
				"$operation $key queues a new revision via real meta hook" );
		}
	}
	delete_user_meta( $uid, 'clfa_mc_pending' );
	$before = profile_meta( $uid, 'revision' );
	update_user_meta( $uid, 'description', 'Synthetic unrelated profile field' );
	profile_verify( ! profile_meta( $uid, 'pending' ) && $before === profile_meta( $uid, 'revision' ),
		'Unrelated metadata does not queue a sync' );
	update_user_meta( $uid, 'clfa_mc_consent', 0 );
	foreach ( array( 'first_name', 'last_name', 'clfa_class_year', 'clfa_leader_class' ) as $key ) {
		add_user_meta( $uid, $key, '2024' );
		update_user_meta( $uid, $key, '2025' );
		delete_user_meta( $uid, $key );
	}
	profile_verify( ! profile_meta( $uid, 'pending' ) && $before === profile_meta( $uid, 'revision' ),
		'Meta changes without consent never queue enrollment' );

	// Interleave real profile saves inside the HTTP boundary, after the worker
	// captured its revision. No sleeps or direct invocation of hook callbacks.
	foreach ( array( 'email', 'optout' ) as $change ) {
		foreach ( array( 200, 500 ) as $code ) {
			$uid = $users[] = profile_fixture();
			wp_set_current_user( $uid );
			$identity = profile_meta( $uid, 'identity' );
			$path = clfa_mc_member_path( $identity['audience'], $identity['email'] );
			clfa_mc_queue( $uid );
			$old_revision = profile_meta( $uid, 'revision' );
			$new_state = null;
			$steps = array(
				array( 'method' => 'GET', 'path' => $path ),
				array( 'method' => 'PUT', 'path' => $path,
					'body' => clfa_mc_member_payload( get_userdata( $uid ), true ) ),
				array( 'method' => 'GET', 'path' => $path . '/tags?count=1000' ),
				array( 'method' => 'POST', 'path' => $path . '/tags', 'code' => $code,
					'during' => function () use ( $uid, $change, &$new_state ) {
						if ( 'email' === $change ) {
							profile_change_email( $uid );
						} else {
							$_POST = array( 'clfa_mc_present' => '1' );
							do_action( 'clfa_profile_extra_save', $uid );
							$_POST = array();
						}
						$new_state = array_map( function ( $key ) use ( $uid ) {
							return profile_meta( $uid, $key );
						}, array( 'revision', 'pending', 'attempts', 'next', 'result', 'checked' ) );
					} ),
			);
			profile_http( $steps, 'clfa_mc_worker' );
			profile_verify( $new_state && $old_revision !== $new_state[0], "$change during HTTP $code creates newer revision" );
			$after = array_map( function ( $key ) use ( $uid ) {
				return profile_meta( $uid, $key );
			}, array( 'revision', 'pending', 'attempts', 'next', 'result', 'checked' ) );
			profile_verify( $after === $new_state && $after[1] && 0 === (int) $after[2],
				"Stale HTTP $code completion preserves newer $change queue and retry state" );
			profile_verify( ! profile_meta( $uid, 'consent' ) && profile_meta( $uid, 'identity' ) === $identity,
				'In-flight completion does not transfer consent or lose old identity' );
			profile_verify( ! get_option( 'clfa_mc_lock' ), 'Worker releases lock after revision conflict' );
			profile_http( profile_retire( $path ), 'clfa_mc_worker' );
			profile_verify( ! profile_meta( $uid, 'pending' ) && ! profile_meta( $uid, 'consent' ),
				'Next worker processes the newer revision without resubscription' );
		}
	}
} finally {
	$_POST = array();
	wp_set_current_user( 0 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $uid ) {
		wp_delete_user( $uid );
	}
}
echo "Profile sync WordPress integration tests passed.\n";
