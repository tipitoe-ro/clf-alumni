<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Admin page: CLF Alumni → Events (invites + RSVP dashboard)
   ============================================================ */
function clfa_events_admin_menu() {
	add_submenu_page( 'clf-alumni', __( 'Invitations & RSVPs', 'clf-alumni' ), __( 'Invitations & RSVPs', 'clf-alumni' ), 'manage_options', 'clfa-events', 'clfa_events_admin_page' );
}
add_action( 'admin_menu', 'clfa_events_admin_menu', 15 );

/* ---- Send an invitation email for one RSVP row ---- */
function clfa_send_invitation( $rsvp, $is_reminder = false ) {
	$user     = get_userdata( $rsvp->user_id );
	$event_id = (int) $rsvp->event_id;
	if ( ! $user || ! get_post( $event_id ) ) {
		return false;
	}
	$rsvp_url = add_query_arg( 'clfa_rsvp', rawurlencode( $rsvp->token ), home_url( '/' ) );
	$when     = clfa_event_when( $event_id );
	$loc      = get_post_meta( $event_id, 'clfa_location', true );
	$deadline = get_post_meta( $event_id, 'clfa_deadline', true );
	$name     = $user->first_name ?: $user->display_name;

	$heading = $is_reminder
		? sprintf( __( 'Reminder: %s', 'clf-alumni' ), get_the_title( $event_id ) )
		: get_the_title( $event_id );
	$intro = $is_reminder
		? sprintf( __( 'Hi %s — we haven\'t heard from you yet, and we\'d love to know if you\'re coming.', 'clf-alumni' ), $name )
		: sprintf( __( 'Hi %s — you\'re invited to a CLF alumni gathering.', 'clf-alumni' ), $name );

	$body =
		'<h1 style="font-size:28px;margin:0 0 16px;font-weight:600;color:#eee9df;">' . esc_html( $heading ) . '</h1>' .
		'<p style="line-height:1.7;color:#c6c8c3;">' . esc_html( $intro ) . '</p>' .
		'<table style="margin:24px 0;color:#eee9df;font-size:14px;line-height:2;">' .
		'<tr><td style="color:#d4a492;padding-right:16px;">' . esc_html__( 'When', 'clf-alumni' ) . '</td><td>' . esc_html( $when ) . '</td></tr>' .
		( $loc ? '<tr><td style="color:#d4a492;padding-right:16px;">' . esc_html__( 'Where', 'clf-alumni' ) . '</td><td>' . esc_html( $loc ) . '</td></tr>' : '' ) .
		( $deadline ? '<tr><td style="color:#d4a492;padding-right:16px;">' . esc_html__( 'RSVP by', 'clf-alumni' ) . '</td><td>' . esc_html( wp_date( 'F j, Y', clfa_local_ts( $deadline ) ) ) . '</td></tr>' : '' ) .
		'</table>' .
		'<p style="line-height:1.7;color:#c6c8c3;">' . esc_html__( 'Tap the button below to RSVP — it takes less than a minute, no password needed. Let us know your name, whether your wife/husband is coming, and how many participants total.', 'clf-alumni' ) . '</p>';

	$footer = '<a href="' . esc_url( clfa_gcal_link( $event_id ) ) . '" style="color:#8a877e;">' . esc_html__( 'Add to Google Calendar', 'clf-alumni' ) . '</a> · <a href="' . esc_url( add_query_arg( array( 'clfa_ics' => $event_id, 't' => $rsvp->token ), home_url( '/' ) ) ) . '" style="color:#8a877e;">' . esc_html__( 'Download calendar file (.ics)', 'clf-alumni' ) . '</a>';

	return clfa_send_branded(
		$user->user_email,
		$is_reminder ? sprintf( __( 'Reminder — RSVP for %s', 'clf-alumni' ), get_the_title( $event_id ) ) : sprintf( __( 'You\'re invited: %s', 'clf-alumni' ), get_the_title( $event_id ) ),
		$body,
		__( 'RSVP now', 'clf-alumni' ),
		$rsvp_url,
		$footer
	);
}

/* ---- The admin page ---- */
function clfa_events_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table    = clfa_rsvp_table();
	$event_id = isset( $_GET['event'] ) ? (int) $_GET['event'] : 0;
	$notice   = '';

	/* -- CSV export -- */
	if ( $event_id && isset( $_GET['clfa_export'] ) && check_admin_referer( 'clfa_export_' . $event_id ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d ORDER BY status DESC, attendee_name", $event_id ) ); // phpcs:ignore
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rsvps-event-' . $event_id . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Name', 'Email', 'Status', 'Spouse attending', 'Participants', 'Note', 'Invited', 'Responded' ) );
		foreach ( $rows as $r ) {
			$u = get_userdata( $r->user_id );
			fputcsv( $out, array( $r->attendee_name, $u ? $u->user_email : '', $r->status, $r->spouse_attending ? 'yes' : 'no', $r->guests, $r->note, $r->invited_at, $r->responded_at ) );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	/* -- Invite / resend actions -- */
	if ( $event_id && isset( $_POST['clfa_invite_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clfa_invite_nonce'] ), 'clfa_invite_' . $event_id ) ) {
		$mode = sanitize_key( $_POST['invite_mode'] ?? '' );
		$sent = 0;
		$new  = 0;

		if ( 'remind' === $mode ) {
			$pending = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d AND status = 'pending'", $event_id ) ); // phpcs:ignore
			foreach ( $pending as $r ) {
				if ( clfa_send_invitation( $r, true ) ) {
					$sent++;
				}
			}
			$notice = sprintf( __( 'Reminder sent to %d non-responder(s).', 'clf-alumni' ), $sent );
		} else {
			$user_ids = array();
			if ( 'all' === $mode ) {
				$user_ids = get_users( array( 'role' => 'clf_alumni', 'fields' => 'ID', 'number' => 2000 ) );
			} elseif ( 'class' === $mode && ! empty( $_POST['class_year'] ) ) {
				$user_ids = get_users( array( 'role' => 'clf_alumni', 'fields' => 'ID', 'number' => 2000, 'meta_key' => 'clfa_class_year', 'meta_value' => sanitize_text_field( wp_unslash( $_POST['class_year'] ) ) ) );
			} elseif ( 'picked' === $mode && ! empty( $_POST['members'] ) ) {
				$user_ids = array_map( 'intval', (array) $_POST['members'] );
			}
			foreach ( $user_ids as $uid ) {
				if ( get_user_meta( $uid, 'clfa_disabled', true ) ) {
					continue;
				}
				$rsvp = clfa_invite_user( $event_id, $uid );
				if ( $rsvp ) {
					$new++;
					if ( clfa_send_invitation( $rsvp ) ) {
						$sent++;
					}
				}
			}
			$notice = sprintf( __( '%1$d new invitation(s) created, %2$d email(s) sent. Members already invited were skipped.', 'clf-alumni' ), $new, $sent );
		}
	}

	/* -- Event list (no event selected) -- */
	$events = get_posts( array( 'post_type' => 'clfa_event', 'posts_per_page' => 100, 'meta_key' => 'clfa_start', 'orderby' => 'meta_value', 'order' => 'DESC' ) );
	echo '<div class="wrap"><h1>' . esc_html__( 'Alumni Events — Invitations & RSVPs', 'clf-alumni' ) . '</h1>';
	if ( $notice ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
	}

	if ( ! $event_id ) {
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No events yet.', 'clf-alumni' ) . ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=clfa_event' ) ) . '">' . esc_html__( 'Create your first event.', 'clf-alumni' ) . '</a></p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event', 'clf-alumni' ) . '</th><th>' . esc_html__( 'When', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Invited', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Yes', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Participants', 'clf-alumni' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $events as $e ) {
			$counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) invited, SUM(status='yes') yes, COALESCE(SUM(CASE WHEN status='yes' THEN guests ELSE 0 END),0) heads FROM $table WHERE event_id = %d", $e->ID ) ); // phpcs:ignore
			echo '<tr><td><strong>' . esc_html( $e->post_title ) . '</strong></td><td>' . esc_html( clfa_event_when( $e->ID ) ) . '</td><td>' . (int) $counts->invited . '</td><td>' . (int) $counts->yes . '</td><td>' . (int) $counts->heads . '</td>';
			echo '<td><a class="button" href="' . esc_url( admin_url( 'admin.php?page=clfa-events&event=' . $e->ID ) ) . '">' . esc_html__( 'Manage', 'clf-alumni' ) . '</a> <a class="button" href="' . esc_url( get_edit_post_link( $e->ID ) ) . '">' . esc_html__( 'Edit', 'clf-alumni' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
		return;
	}

	/* -- Single-event dashboard -- */
	$event = get_post( $event_id );
	if ( ! $event || 'clfa_event' !== $event->post_type ) {
		echo '<p>' . esc_html__( 'Event not found.', 'clf-alumni' ) . '</p></div>';
		return;
	}
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d ORDER BY FIELD(status,'yes','pending','no'), attendee_name", $event_id ) ); // phpcs:ignore
	$yes     = array_filter( $rows, fn( $r ) => 'yes' === $r->status );
	$no      = array_filter( $rows, fn( $r ) => 'no' === $r->status );
	$pending = array_filter( $rows, fn( $r ) => 'pending' === $r->status );
	$heads   = array_sum( array_map( fn( $r ) => (int) $r->guests, $yes ) );
	$years   = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'clfa_class_year' AND meta_value != '' ORDER BY meta_value DESC" );
	$capacity = (int) get_post_meta( $event_id, 'clfa_capacity', true );

	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=clfa-events' ) ) . '">&larr; ' . esc_html__( 'All events', 'clf-alumni' ) . '</a></p>';
	echo '<h2>' . esc_html( $event->post_title ) . '</h2><p>' . esc_html( clfa_event_when( $event_id ) );
	$loc = get_post_meta( $event_id, 'clfa_location', true );
	echo $loc ? esc_html( ' — ' . $loc ) : '';
	echo '</p>';

	echo '<div style="display:flex;gap:24px;margin:16px 0;flex-wrap:wrap;">';
	foreach ( array(
		__( 'Invited', 'clf-alumni' )      => count( $rows ),
		__( 'Going', 'clf-alumni' )        => count( $yes ),
		__( 'Participants', 'clf-alumni' ) => $heads . ( $capacity ? ' / ' . $capacity : '' ),
		__( 'Declined', 'clf-alumni' )     => count( $no ),
		__( 'No reply', 'clf-alumni' )     => count( $pending ),
	) as $label => $num ) {
		echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:14px 22px;"><div style="font-size:22px;font-weight:600;">' . esc_html( (string) $num ) . '</div><div style="color:#666;">' . esc_html( $label ) . '</div></div>';
	}
	echo '</div>';

	/* Invite form */
	echo '<h3>' . esc_html__( 'Send invitations', 'clf-alumni' ) . '</h3>';
	echo '<form method="post" style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:640px;">';
	wp_nonce_field( 'clfa_invite_' . $event_id, 'clfa_invite_nonce' );
	echo '<p><label><input type="radio" name="invite_mode" value="all" checked> ' . esc_html__( 'All active members', 'clf-alumni' ) . '</label></p>';
	echo '<p><label><input type="radio" name="invite_mode" value="class"> ' . esc_html__( 'One class year:', 'clf-alumni' ) . '</label> <select name="class_year">';
	foreach ( $years as $y ) {
		echo '<option value="' . esc_attr( $y ) . '">' . esc_html( $y ) . '</option>';
	}
	echo '</select></p>';
	echo '<p><label><input type="radio" name="invite_mode" value="picked"> ' . esc_html__( 'Hand-picked members:', 'clf-alumni' ) . '</label></p>';
	echo '<div style="max-height:180px;overflow:auto;border:1px solid #ddd;padding:8px;margin:4px 0 12px;">';
	foreach ( get_users( array( 'role' => 'clf_alumni', 'orderby' => 'display_name', 'number' => 2000 ) ) as $u ) {
		if ( get_user_meta( $u->ID, 'clfa_disabled', true ) ) {
			continue;
		}
		echo '<label style="display:block;"><input type="checkbox" name="members[]" value="' . (int) $u->ID . '"> ' . esc_html( $u->display_name . ' (' . $u->user_email . ')' ) . '</label>';
	}
	echo '</div>';
	echo '<p><button class="button button-primary">' . esc_html__( 'Send invitations', 'clf-alumni' ) . '</button> ';
	echo '<button class="button" name="invite_mode" value="remind" onclick="return confirm(\'' . esc_js( __( 'Send a reminder email to everyone who has not replied?', 'clf-alumni' ) ) . '\')">' . esc_html__( 'Remind non-responders now', 'clf-alumni' ) . '</button></p>';
	echo '<p class="description">' . esc_html__( 'Members already invited to this event are never emailed twice by "Send invitations" — use the reminder button for follow-ups. An automatic reminder also goes out 3 days before the RSVP deadline.', 'clf-alumni' ) . '</p>';
	echo '</form>';

	/* Response table */
	echo '<h3 style="margin-top:24px;">' . esc_html__( 'Responses', 'clf-alumni' ) . ' <a class="button" style="margin-left:8px;" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=clfa-events&event=' . $event_id . '&clfa_export=1' ), 'clfa_export_' . $event_id ) ) . '">' . esc_html__( 'Export CSV', 'clf-alumni' ) . '</a></h3>';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Status', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Spouse', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Participants', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Note', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Responded', 'clf-alumni' ) . '</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="6">' . esc_html__( 'No invitations yet.', 'clf-alumni' ) . '</td></tr>';
	}
	foreach ( $rows as $r ) {
		$u      = get_userdata( $r->user_id );
		$status = 'yes' === $r->status ? '<span style="color:#2e7d32;font-weight:600;">' . esc_html__( 'Going', 'clf-alumni' ) . '</span>' : ( 'no' === $r->status ? '<span style="color:#a94d3b;">' . esc_html__( 'Declined', 'clf-alumni' ) . '</span>' : esc_html__( 'No reply', 'clf-alumni' ) );
		echo '<tr><td><strong>' . esc_html( $r->attendee_name ?: ( $u ? $u->display_name : '#' . $r->user_id ) ) . '</strong><br><small>' . esc_html( $u ? $u->user_email : '' ) . '</small></td>';
		echo '<td>' . $status . '</td>'; // phpcs:ignore
		echo '<td>' . ( $r->spouse_attending ? esc_html__( 'Yes', 'clf-alumni' ) : '—' ) . '</td>';
		echo '<td>' . ( 'yes' === $r->status ? (int) $r->guests : '—' ) . '</td>';
		echo '<td>' . esc_html( (string) $r->note ) . '</td>';
		echo '<td>' . esc_html( $r->responded_at ? mysql2date( 'M j, g:ia', $r->responded_at ) : '' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ============================================================
   Automatic reminders — daily cron, 3 days before deadline
   ============================================================ */
function clfa_schedule_cron() {
	if ( ! wp_next_scheduled( 'clfa_daily_reminders' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'clfa_daily_reminders' );
	}
}
add_action( 'init', 'clfa_schedule_cron' );

function clfa_run_reminders() {
	global $wpdb;
	$table  = clfa_rsvp_table();
	$events = get_posts( array( 'post_type' => 'clfa_event', 'posts_per_page' => 100 ) );
	$now    = time();
	foreach ( $events as $e ) {
		$cutoff = clfa_rsvp_cutoff_ts( $e->ID );
		if ( ! $cutoff || $cutoff < $now || $cutoff - $now > 3 * DAY_IN_SECONDS ) {
			continue; // no deadline, already passed, or more than 3 days out
		}
		$pending = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d AND status = 'pending' AND reminded = 0", $e->ID ) ); // phpcs:ignore
		foreach ( $pending as $r ) {
			if ( clfa_send_invitation( $r, true ) ) {
				$wpdb->update( $table, array( 'reminded' => 1 ), array( 'id' => $r->id ) ); // phpcs:ignore
			}
		}
	}
}
add_action( 'clfa_daily_reminders', 'clfa_run_reminders' );
