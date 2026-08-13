<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   RSVP data access (custom table wp_clfa_rsvp)
   ============================================================ */
function clfa_rsvp_table() {
	global $wpdb;
	return $wpdb->prefix . 'clfa_rsvp';
}

function clfa_install_rsvp_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . clfa_rsvp_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_id bigint(20) unsigned NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		token varchar(64) NOT NULL,
		status varchar(10) NOT NULL DEFAULT 'pending',
		attendee_name varchar(190) NOT NULL DEFAULT '',
		spouse_attending tinyint(1) NOT NULL DEFAULT 0,
		guests int(11) NOT NULL DEFAULT 0,
		note text NULL,
		invited_at datetime NULL,
		responded_at datetime NULL,
		reminded tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY event_user (event_id,user_id),
		UNIQUE KEY token (token),
		KEY event_status (event_id,status)
	) $charset;" );
}

function clfa_get_rsvp( $event_id, $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . clfa_rsvp_table() . ' WHERE event_id = %d AND user_id = %d', $event_id, $user_id ) ); // phpcs:ignore
}

function clfa_get_rsvp_by_token( $token ) {
	global $wpdb;
	static $cache = array();
	if ( ! isset( $cache[ $token ] ) ) {
		$cache[ $token ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . clfa_rsvp_table() . ' WHERE token = %s', $token ) ); // phpcs:ignore
	}
	return $cache[ $token ];
}

function clfa_invite_user( $event_id, $user_id ) {
	global $wpdb;
	if ( clfa_get_rsvp( $event_id, $user_id ) ) {
		return false; // already invited
	}
	$user = get_userdata( $user_id );
	$wpdb->insert( clfa_rsvp_table(), array( // phpcs:ignore
		'event_id'      => $event_id,
		'user_id'       => $user_id,
		'token'         => wp_generate_password( 40, false ),
		'status'        => 'pending',
		'attendee_name' => $user ? $user->display_name : '',
		'invited_at'    => current_time( 'mysql' ),
	) );
	return clfa_get_rsvp( $event_id, $user_id );
}

/* Total confirmed participants for capacity checks */
function clfa_event_participants( $event_id, $exclude_rsvp_id = 0 ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
		'SELECT COALESCE(SUM(guests),0) FROM ' . clfa_rsvp_table() . " WHERE event_id = %d AND status = 'yes' AND id != %d",
		$event_id, $exclude_rsvp_id
	) );
}

/* ============================================================
   RSVP form HTML (shared: token page + members area)
   ============================================================ */
function clfa_rsvp_form_html( $rsvp, $action_url, $token_mode = false ) {
	$event_id = (int) $rsvp->event_id;
	$open     = clfa_rsvp_open( $event_id );
	$capacity = (int) get_post_meta( $event_id, 'clfa_capacity', true );
	$left     = $capacity ? max( 0, $capacity - clfa_event_participants( $event_id, $rsvp->id ) ) : -1;

	ob_start(); ?>
	<div class="clfa-rsvpbox">
	  <div class="clfa-section"><?php esc_html_e( 'Your RSVP', 'clf-alumni' ); ?></div>
	  <?php if ( isset( $_GET['rsvp'] ) && 'saved' === $_GET['rsvp'] ) : ?>
	    <p class="clfa-success"><?php esc_html_e( 'Thank you — your RSVP is saved. You can change it any time before the deadline.', 'clf-alumni' ); ?></p>
	  <?php elseif ( isset( $_GET['rsvp'] ) && 'full' === $_GET['rsvp'] ) : ?>
	    <p class="clfa-error"><?php esc_html_e( 'Sorry — the event doesn\'t have room for that many participants. Try a smaller number or contact CLF.', 'clf-alumni' ); ?></p>
	  <?php endif; ?>
	  <?php if ( ! $open ) : ?>
	    <p class="clfa-muted"><?php esc_html_e( 'RSVPs for this event are closed.', 'clf-alumni' ); ?>
	      <?php if ( 'yes' === $rsvp->status ) : ?> <?php echo esc_html( sprintf( __( 'You are down as going with %d participant(s).', 'clf-alumni' ), (int) $rsvp->guests ) ); ?><?php endif; ?></p>
	  <?php else : ?>
	    <?php if ( $capacity && $left >= 0 ) : ?>
	      <p class="clfa-count"><?php echo esc_html( sprintf( __( '%d spots remaining', 'clf-alumni' ), $left ) ); ?></p>
	    <?php endif; ?>
	    <form method="post" action="<?php echo esc_url( $action_url ); ?>" class="clfa-form clfa-rsvpform">
	      <?php if ( $token_mode ) : ?>
	        <input type="hidden" name="clfa_rsvp_token" value="<?php echo esc_attr( $rsvp->token ); ?>">
	      <?php else : ?>
	        <?php wp_nonce_field( 'clfa_rsvp_' . $rsvp->id, 'clfa_rsvp_nonce' ); ?>
	        <input type="hidden" name="clfa_rsvp_id" value="<?php echo (int) $rsvp->id; ?>">
	      <?php endif; ?>
	      <div class="clfa-yesno">
	        <label class="clfa-check"><input type="radio" name="status" value="yes" <?php checked( $rsvp->status, 'yes' ); ?> required> <?php esc_html_e( 'Yes, we\'ll be there', 'clf-alumni' ); ?></label>
	        <label class="clfa-check"><input type="radio" name="status" value="no" <?php checked( $rsvp->status, 'no' ); ?>> <?php esc_html_e( 'Can\'t make it', 'clf-alumni' ); ?></label>
	      </div>
	      <div class="clfa-rsvpdetails">
	        <label class="clfa-field"><span><?php esc_html_e( 'Your name', 'clf-alumni' ); ?></span>
	          <input type="text" name="attendee_name" value="<?php echo esc_attr( $rsvp->attendee_name ); ?>" required></label>
	        <label class="clfa-check"><input type="checkbox" name="spouse_attending" <?php checked( $rsvp->spouse_attending ); ?>> <?php esc_html_e( 'My wife/husband is attending too', 'clf-alumni' ); ?></label>
	        <label class="clfa-field"><span><?php esc_html_e( 'Total number of participants (including you and your spouse)', 'clf-alumni' ); ?></span>
	          <input type="number" name="guests" min="1" max="20" value="<?php echo esc_attr( max( 1, (int) $rsvp->guests ) ); ?>"></label>
	        <label class="clfa-field"><span><?php esc_html_e( 'Anything we should know? (dietary needs, arriving late…)', 'clf-alumni' ); ?></span>
	          <textarea name="note" rows="3"><?php echo esc_textarea( (string) $rsvp->note ); ?></textarea></label>
	      </div>
	      <button type="submit" class="clfa-btn"><?php esc_html_e( 'Save my RSVP', 'clf-alumni' ); ?></button>
	    </form>
	  <?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/* ---- Shared save logic; returns redirect flag string ---- */
function clfa_apply_rsvp_post( $rsvp ) {
	$event_id = (int) $rsvp->event_id;
	if ( ! clfa_rsvp_open( $event_id ) ) {
		return 'closed';
	}
	$status = ( isset( $_POST['status'] ) && 'yes' === $_POST['status'] ) ? 'yes' : 'no';
	$guests = max( 1, min( 20, (int) ( $_POST['guests'] ?? 1 ) ) );
	if ( 'no' === $status ) {
		$guests = 0;
	}
	global $wpdb;
	// Capacity check (only for "yes") — under a per-event MySQL lock so two
	// simultaneous submissions can't both grab the last spots.
	$capacity  = (int) get_post_meta( $event_id, 'clfa_capacity', true );
	$lock_name = '';
	if ( 'yes' === $status && $capacity ) {
		$lock_name = 'clfa_event_' . $event_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ); // phpcs:ignore
		if ( clfa_event_participants( $event_id, $rsvp->id ) + $guests > $capacity ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore
			return 'full';
		}
	}
	$wpdb->update( clfa_rsvp_table(), array( // phpcs:ignore
		'status'           => $status,
		'attendee_name'    => sanitize_text_field( wp_unslash( $_POST['attendee_name'] ?? '' ) ),
		'spouse_attending' => empty( $_POST['spouse_attending'] ) ? 0 : 1,
		'guests'           => $guests,
		'note'             => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
		'responded_at'     => current_time( 'mysql' ),
	), array( 'id' => $rsvp->id ) );
	if ( $lock_name ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore
	}
	return 'saved';
}

/* ============================================================
   One-click tokenized RSVP page — /?clfa_rsvp=<token>
   Standalone branded page, no login needed.
   ============================================================ */
function clfa_token_rsvp_page() {
	if ( ! isset( $_REQUEST['clfa_rsvp'] ) && ! isset( $_POST['clfa_rsvp_token'] ) ) {
		return;
	}
	$token = sanitize_text_field( wp_unslash( $_POST['clfa_rsvp_token'] ?? $_REQUEST['clfa_rsvp'] ) );
	$rsvp  = $token ? clfa_get_rsvp_by_token( $token ) : null;

	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );

	$flag = '';
	if ( $rsvp && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['clfa_rsvp_token'] ) ) {
		$flag = clfa_apply_rsvp_post( $rsvp );
		wp_safe_redirect( add_query_arg( array( 'clfa_rsvp' => rawurlencode( $token ), 'rsvp' => $flag ), home_url( '/' ) ) );
		exit;
	}

	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CLF — RSVP</title>';
	echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,600&display=swap">';
	echo '<link rel="stylesheet" href="' . esc_url( CLFA_URL . 'assets/clf-alumni.css?v=' . CLFA_VERSION ) . '">';
	echo '<style>body{margin:0;background:#eee9df;font-family:Manrope,sans-serif;color:#172638}.clfa-shell{max-width:760px;margin:0 auto;padding:48px 22px}.clfa-shellhead{font:11px "DM Mono";letter-spacing:.16em;text-transform:uppercase;color:#a94d3b;margin-bottom:34px}</style></head><body>';
	echo '<div class="clfa-shell"><p class="clfa-shellhead">Charlotte Leadership Forum — Alumni</p>';

	if ( ! $rsvp || 'clfa_event' !== get_post_type( (int) $rsvp->event_id ) ) {
		echo '<h2 style="letter-spacing:-.04em;">' . esc_html__( 'This RSVP link isn\'t valid.', 'clf-alumni' ) . '</h2><p style="color:#636660;">' . esc_html__( 'It may have been replaced by a newer invitation. Check your latest email from CLF or contact us.', 'clf-alumni' ) . '</p>';
	} else {
		$event_id = (int) $rsvp->event_id;
		echo '<div class="clfa-wrap">';
		echo '<p class="clfa-kicker">' . esc_html( clfa_event_when( $event_id ) ) . '</p>';
		echo '<h2 class="clfa-title">' . esc_html( get_the_title( $event_id ) ) . '</h2>';
		$loc = get_post_meta( $event_id, 'clfa_location', true );
		if ( $loc ) {
			echo '<p class="clfa-muted">' . esc_html( $loc ) . '</p>';
		}
		echo '<div class="clfa-bio">' . wpautop( esc_html( wp_strip_all_tags( get_post_field( 'post_content', $event_id ) ) ) ) . '</div>'; // phpcs:ignore
		echo '<p class="clfa-calrow"><a class="clfa-textlink" href="' . esc_url( clfa_gcal_link( $event_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Add to Google Calendar', 'clf-alumni' ) . ' ↗</a> <a class="clfa-textlink" href="' . esc_url( add_query_arg( array( 'clfa_ics' => $event_id, 't' => $rsvp->token ), home_url( '/' ) ) ) . '">' . esc_html__( 'Download .ics', 'clf-alumni' ) . '</a></p>';
		echo clfa_rsvp_form_html( $rsvp, add_query_arg( 'clfa_rsvp', rawurlencode( $token ), home_url( '/' ) ), true ); // phpcs:ignore
		echo '</div>';
	}
	echo '</div></body></html>';
	exit;
}
add_action( 'template_redirect', 'clfa_token_rsvp_page', 4 );

/* ============================================================
   Members-area RSVP save (nonce-protected, logged in)
   ============================================================ */
function clfa_member_rsvp_save() {
	if ( ! isset( $_POST['clfa_rsvp_id'], $_POST['clfa_rsvp_nonce'] ) ) {
		return;
	}
	if ( ! clfa_is_member() || ! wp_verify_nonce( sanitize_key( $_POST['clfa_rsvp_nonce'] ), 'clfa_rsvp_' . (int) $_POST['clfa_rsvp_id'] ) ) {
		return;
	}
	global $wpdb;
	$rsvp = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . clfa_rsvp_table() . ' WHERE id = %d', (int) $_POST['clfa_rsvp_id'] ) ); // phpcs:ignore
	if ( ! $rsvp || (int) $rsvp->user_id !== get_current_user_id() ) {
		return;
	}
	$flag = clfa_apply_rsvp_post( $rsvp );
	wp_safe_redirect( add_query_arg( array( 'event' => (int) $rsvp->event_id, 'rsvp' => $flag ), clfa_page_url( 'alumni-events' ) ) );
	exit;
}
add_action( 'template_redirect', 'clfa_member_rsvp_save', 5 );
