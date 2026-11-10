<?php
defined( 'ABSPATH' ) || exit;

function clfa_mc_consent( $user_id, $consent, $source ) {
	$previous = (bool) get_user_meta( $user_id, 'clfa_mc_consent', true );
	update_user_meta( $user_id, 'clfa_mc_consent', $consent ? 1 : 0 );
	if ( $previous !== (bool) $consent ) {
		update_user_meta( $user_id, 'clfa_mc_consent_record', array(
			'source' => $source, 'actor' => get_current_user_id(), 'at' => gmdate( 'c' ), 'consent' => (bool) $consent,
		) );
	}
	if ( $consent || $previous ) {
		clfa_mc_queue( $user_id );
	}
}

function clfa_mc_queue( $user_id ) {
	update_user_meta( $user_id, 'clfa_mc_revision', wp_generate_uuid4() );
	update_user_meta( $user_id, 'clfa_mc_pending', 1 );
	update_user_meta( $user_id, 'clfa_mc_attempts', 0 );
	update_user_meta( $user_id, 'clfa_mc_next', time() );
	update_user_meta( $user_id, 'clfa_mc_result', 'Queued for Mailchimp sync.' );
}

function clfa_mc_unsubscribe( $path ) {
	$remote = clfa_mc_request( 'GET', $path );
	if ( clfa_mc_missing( $remote ) ) {
		return true;
	}
	if ( is_wp_error( $remote ) ) {
		return $remote;
	}
	if ( in_array( $remote['status'] ?? '', array( 'subscribed', 'pending' ), true ) ) {
		return clfa_mc_request( 'PATCH', $path, array( 'status' => 'unsubscribed' ) );
	}
	return true;
}

function clfa_mc_sync_user( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return 'Account no longer exists.';
	}
	$s = clfa_mc_settings();
	$consent = (bool) get_user_meta( $user_id, 'clfa_mc_consent', true );
	$previous = get_user_meta( $user_id, 'clfa_mc_identity', true );
	// A changed address does not inherit consent; retire only the previously
	// synced address. The person can explicitly opt in again for the new one.
	if ( $previous && strtolower( $previous['email'] ) !== strtolower( $user->user_email ) ) {
		$result = clfa_mc_unsubscribe( clfa_mc_member_path( $previous['audience'], $previous['email'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		delete_user_meta( $user_id, 'clfa_mc_identity' );
		update_user_meta( $user_id, 'clfa_mc_consent', 0 );
		return 'Email changed. Previous address unsubscribed; opt in again for the new address.';
	}
	if ( ! $consent ) {
		if ( $previous ) {
			$result = clfa_mc_unsubscribe( clfa_mc_member_path( $previous['audience'], $previous['email'] ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return 'Not subscribed through the website.';
	}
	if ( get_user_meta( $user_id, 'clfa_disabled', true ) ) {
		return 'Skipped: account is inactive.';
	}
	$path = clfa_mc_member_path( $s['audience'], $user->user_email );
	$remote = clfa_mc_request( 'GET', $path );
	if ( is_wp_error( $remote ) && ! clfa_mc_missing( $remote ) ) {
		return $remote;
	}
	if ( ! is_wp_error( $remote ) && ! in_array( $remote['status'] ?? '', array( 'subscribed', 'pending' ), true ) ) {
		update_user_meta( $user_id, 'clfa_mc_consent', 0 );
		return 'Mailchimp status: ' . sanitize_key( $remote['status'] ?? 'unknown' ) . '. Not resubscribed. Use the audience signup form to opt in again.';
	}
	$member = clfa_mc_request( 'PUT', $path, clfa_mc_member_payload( $user, $s['double_optin'] ) );
	if ( is_wp_error( $member ) ) {
		return $member;
	}
	update_user_meta( $user_id, 'clfa_mc_identity', array( 'email' => $user->user_email, 'audience' => $s['audience'] ) );
	$desired = clfa_mc_tags( $user_id );
	// Read all remote tags, and remove only tags this integration owns.
	$tags = clfa_mc_request( 'GET', $path . '/tags?count=1000' );
	if ( is_wp_error( $tags ) ) {
		return $tags;
	}
	if ( ( $tags['total_items'] ?? 0 ) > 1000 ) {
		return new WP_Error( 'tags', 'Contact has too many tags to safely reconcile automatically.' );
	}
	$changes = array_map( function ( $tag ) { return array( 'name' => $tag, 'status' => 'active' ); }, $desired );
	foreach ( $tags['tags'] ?? array() as $tag ) {
		if ( 0 === strpos( $tag['name'], 'CLF:' ) && ! in_array( $tag['name'], $desired, true ) ) {
			$changes[] = array( 'name' => $tag['name'], 'status' => 'inactive' );
		}
	}
	$result = clfa_mc_request( 'POST', $path . '/tags', array( 'tags' => $changes ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return 'Synced — ' . sanitize_key( $member['status'] ?? 'unknown' ) . ( 'pending' === ( $member['status'] ?? '' ) ? ' (awaiting email confirmation).' : '.' );
}

function clfa_mc_worker() {
	if ( ! clfa_mc_ready() ) {
		return;
	}
	// Atomic option lock avoids overlapping WP-Cron workers.
	$lock = (int) get_option( 'clfa_mc_lock', 0 );
	if ( $lock && $lock < time() - 600 ) {
		delete_option( 'clfa_mc_lock' );
	}
	if ( ! add_option( 'clfa_mc_lock', time(), '', false ) ) {
		return;
	}
	try {
		$started = microtime( true );
		$users = get_users( array( 'fields' => 'ID', 'number' => 5, 'meta_query' => array(
			array( 'key' => 'clfa_mc_pending', 'value' => 1 ),
			array( 'key' => 'clfa_mc_next', 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ),
		) ) );
		foreach ( $users as $uid ) {
			$revision = get_user_meta( $uid, 'clfa_mc_revision', true );
			$result = clfa_mc_sync_user( $uid );
			// A profile/consent change during the request must get its own sync.
			if ( $revision !== get_user_meta( $uid, 'clfa_mc_revision', true ) ) {
				continue;
			}
			if ( is_wp_error( $result ) ) {
				$attempt = (int) get_user_meta( $uid, 'clfa_mc_attempts', true ) + 1;
				update_user_meta( $uid, 'clfa_mc_attempts', $attempt );
				update_user_meta( $uid, 'clfa_mc_next', time() + min( 3600, 300 * pow( 2, $attempt - 1 ) ) );
				update_user_meta( $uid, 'clfa_mc_result', $result->get_error_message() . ( $attempt >= 5 ? ' Retry limit reached; use Sync selected after fixing the issue.' : ' Retry scheduled.' ) );
				if ( $attempt >= 5 ) {
					delete_user_meta( $uid, 'clfa_mc_pending' );
				}
			} else {
				delete_user_meta( $uid, 'clfa_mc_pending' );
				update_user_meta( $uid, 'clfa_mc_result', $result );
			}
			update_user_meta( $uid, 'clfa_mc_checked', gmdate( 'c' ) );
			if ( microtime( true ) - $started > 20 ) {
				break; // Leave remaining contacts queued for the next cron run.
			}
		}
	} finally {
		delete_option( 'clfa_mc_lock' );
	}
}
add_action( 'clfa_mc_tick', 'clfa_mc_worker' );
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['clfa_five_minutes'] = array( 'interval' => 300, 'display' => 'Every five minutes' );
	return $schedules;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'clfa_mc_tick' ) ) {
		wp_schedule_event( time() + 60, 'clfa_five_minutes', 'clfa_mc_tick' );
	}
} );

function clfa_mc_profile_fields( $user_id ) {
	$s = clfa_mc_settings();
	if ( ! $s['enabled'] && ! get_user_meta( $user_id, 'clfa_mc_consent', true ) ) {
		return;
	}
	?>
	<div class="clfa-section"><?php esc_html_e( 'CLF newsletter', 'clf-alumni' ); ?></div>
	<input type="hidden" name="clfa_mc_present" value="1">
	<label class="clfa-check"><input type="checkbox" name="clfa_mc_optin" value="1" <?php checked( get_user_meta( $user_id, 'clfa_mc_consent', true ), 1 ); ?>>
	<?php esc_html_e( 'Send me CLF announcements and the monthly newsletter.', 'clf-alumni' ); ?></label>
	<p class="clfa-muted clfa-small"><?php esc_html_e( 'Your name, email, class year and leadership tags will be shared with Mailchimp. You can unsubscribe at any time. Event emails are separate. When editing for someone else, only opt them in if they have consented.', 'clf-alumni' ); ?></p>
	<p class="clfa-muted clfa-small"><?php echo esc_html( get_user_meta( $user_id, 'clfa_mc_result', true ) ); ?></p>
	<?php
}
add_action( 'clfa_profile_extra_fields', 'clfa_mc_profile_fields' );
add_action( 'clfa_profile_extra_save', function ( $user_id ) {
	if ( ! isset( $_POST['clfa_mc_present'] ) ) {
		return;
	}
	// Parent save handler verified the profile nonce and target-user permission.
	clfa_mc_consent( $user_id, ! empty( $_POST['clfa_mc_optin'] ), get_current_user_id() === (int) $user_id ? 'member_profile' : 'admin_profile' );
} );

function clfa_mc_meta_changed( $meta_id, $user_id, $key, $value ) {
	if ( in_array( $key, array( 'first_name', 'last_name', 'clfa_class_year', 'clfa_leader_class' ), true )
		&& get_user_meta( $user_id, 'clfa_mc_consent', true ) ) {
		clfa_mc_queue( $user_id );
	}
}
add_action( 'added_user_meta', 'clfa_mc_meta_changed', 10, 4 );
add_action( 'updated_user_meta', 'clfa_mc_meta_changed', 10, 4 );
add_action( 'deleted_user_meta', 'clfa_mc_meta_changed', 10, 4 );
add_action( 'profile_update', function ( $user_id, $old ) {
	$user = get_userdata( $user_id );
	if ( $user && strtolower( $old->user_email ) !== strtolower( $user->user_email )
		&& get_user_meta( $user_id, 'clfa_mc_identity', true ) ) {
		clfa_mc_consent( $user_id, false, 'email_changed' );
	}
}, 10, 2 );
add_action( 'clfa_member_created', function ( $user_id ) {
	$s = clfa_mc_settings();
	if ( $s['enabled'] && $s['auto_enroll'] ) {
		clfa_mc_consent( $user_id, true, 'admin_automatic_enrollment' );
	}
} );
