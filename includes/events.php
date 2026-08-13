<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Event post type (admin-managed, not publicly queryable)
   ============================================================ */
function clfa_register_event_cpt() {
	register_post_type( 'clfa_event', array(
		'labels' => array(
			'name'          => __( 'Alumni Events', 'clf-alumni' ),
			'singular_name' => __( 'Alumni Event', 'clf-alumni' ),
			'add_new_item'  => __( 'Add Alumni Event', 'clf-alumni' ),
			'edit_item'     => __( 'Edit Alumni Event', 'clf-alumni' ),
		),
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => 'clf-alumni',
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'show_in_rest'        => false,
		'supports'            => array( 'title', 'editor' ),
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
	) );
}
add_action( 'init', 'clfa_register_event_cpt' );

/* ---- Event meta box ---- */
function clfa_event_meta_boxes() {
	add_meta_box( 'clfa_event_details', __( 'Event Details', 'clf-alumni' ), 'clfa_event_details_box', 'clfa_event', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'clfa_event_meta_boxes' );

function clfa_event_details_box( $post ) {
	wp_nonce_field( 'clfa_event_save', 'clfa_event_nonce' );
	$start    = get_post_meta( $post->ID, 'clfa_start', true );
	$end      = get_post_meta( $post->ID, 'clfa_end', true );
	$location = get_post_meta( $post->ID, 'clfa_location', true );
	$address  = get_post_meta( $post->ID, 'clfa_address', true );
	$capacity = get_post_meta( $post->ID, 'clfa_capacity', true );
	$deadline = get_post_meta( $post->ID, 'clfa_deadline', true );
	$hide     = get_post_meta( $post->ID, 'clfa_hide_attendees', true );
	?>
	<table class="form-table">
	  <tr><th><label><?php esc_html_e( 'Starts', 'clf-alumni' ); ?> *</label></th>
	    <td><input type="datetime-local" name="clfa_start" value="<?php echo esc_attr( $start ); ?>" required></td></tr>
	  <tr><th><label><?php esc_html_e( 'Ends', 'clf-alumni' ); ?></label></th>
	    <td><input type="datetime-local" name="clfa_end" value="<?php echo esc_attr( $end ); ?>"> <span class="description"><?php esc_html_e( 'Optional — defaults to 2 hours after start.', 'clf-alumni' ); ?></span></td></tr>
	  <tr><th><label><?php esc_html_e( 'Location', 'clf-alumni' ); ?></label></th>
	    <td><input type="text" name="clfa_location" class="regular-text" value="<?php echo esc_attr( $location ); ?>" placeholder="<?php esc_attr_e( 'e.g. Myers Park Country Club, Charlotte', 'clf-alumni' ); ?>"></td></tr>
	  <tr><th><label><?php esc_html_e( 'Address (for Google Maps)', 'clf-alumni' ); ?></label></th>
	    <td>
	      <input type="text" name="clfa_address" id="clfa_address" class="regular-text" value="<?php echo esc_attr( $address ); ?>" placeholder="<?php esc_attr_e( 'e.g. 2415 Roswell Ave, Charlotte, NC 28209', 'clf-alumni' ); ?>">
	      <span class="description"><?php esc_html_e( 'Optional — makes the venue clickable and shows a map for members. The preview below updates as you type.', 'clf-alumni' ); ?></span>
	      <div id="clfa_map_preview" style="margin-top:10px;<?php echo $address ? '' : 'display:none;'; ?>">
	        <iframe id="clfa_map_iframe" src="<?php echo $address ? esc_url( 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed' ) : ''; ?>" style="width:100%;max-width:560px;height:260px;border:1px solid #ccd0d4;" loading="lazy" referrerpolicy="no-referrer"></iframe>
	      </div>
	      <script>
	      (function(){
	        var f=document.getElementById('clfa_address'),w=document.getElementById('clfa_map_preview'),i=document.getElementById('clfa_map_iframe'),t;
	        if(!f){return;}
	        f.addEventListener('input',function(){
	          clearTimeout(t);
	          t=setTimeout(function(){
	            var v=f.value.trim();
	            if(v){ i.src='https://www.google.com/maps?q='+encodeURIComponent(v)+'&output=embed'; w.style.display=''; }
	            else { w.style.display='none'; i.src=''; }
	          },800);
	        });
	      })();
	      </script>
	    </td></tr>
	  <tr><th><label><?php esc_html_e( 'Capacity (participants)', 'clf-alumni' ); ?></label></th>
	    <td><input type="number" name="clfa_capacity" min="0" value="<?php echo esc_attr( $capacity ); ?>"> <span class="description"><?php esc_html_e( 'Optional — leave empty for unlimited.', 'clf-alumni' ); ?></span></td></tr>
	  <tr><th><label><?php esc_html_e( 'RSVP deadline', 'clf-alumni' ); ?></label></th>
	    <td><input type="datetime-local" name="clfa_deadline" value="<?php echo esc_attr( $deadline ); ?>"> <span class="description"><?php esc_html_e( 'Optional — RSVPs close at event start otherwise.', 'clf-alumni' ); ?></span></td></tr>
	  <tr><th><label><?php esc_html_e( 'Attendee list', 'clf-alumni' ); ?></label></th>
	    <td><label><input type="checkbox" name="clfa_hide_attendees" value="1" <?php checked( $hide ); ?>> <?php esc_html_e( 'Hide the "Who\'s coming" list from members for this event', 'clf-alumni' ); ?></label> <span class="description"><?php esc_html_e( 'Use for sensitive gatherings.', 'clf-alumni' ); ?></span></td></tr>
	</table>
	<p><?php esc_html_e( 'After publishing, use the "Invitations & RSVPs" box (or CLF Alumni → Events) to invite members.', 'clf-alumni' ); ?></p>
	<?php
}

function clfa_save_event_meta( $post_id ) {
	if ( ! isset( $_POST['clfa_event_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clfa_event_nonce'] ), 'clfa_event_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	foreach ( array( 'clfa_start', 'clfa_end', 'clfa_location', 'clfa_address', 'clfa_capacity', 'clfa_deadline' ) as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
		}
	}
	// Checkbox: absent from $_POST when unchecked.
	update_post_meta( $post_id, 'clfa_hide_attendees', empty( $_POST['clfa_hide_attendees'] ) ? '' : '1' );
}
add_action( 'save_post_clfa_event', 'clfa_save_event_meta' );

/* ============================================================
   Event helpers
   ============================================================ */
/* Parse a stored datetime-local string in the SITE timezone → UTC unix ts */
function clfa_local_ts( $str ) {
	if ( ! $str ) {
		return 0;
	}
	try {
		return ( new DateTimeImmutable( $str, wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		return 0;
	}
}

function clfa_event_start_ts( $event_id ) {
	return clfa_local_ts( get_post_meta( $event_id, 'clfa_start', true ) );
}

function clfa_event_end_ts( $event_id ) {
	$end = clfa_local_ts( get_post_meta( $event_id, 'clfa_end', true ) );
	if ( $end ) {
		return $end;
	}
	$start = clfa_event_start_ts( $event_id );
	return $start ? $start + 2 * HOUR_IN_SECONDS : 0;
}

function clfa_rsvp_cutoff_ts( $event_id ) {
	$deadline = clfa_local_ts( get_post_meta( $event_id, 'clfa_deadline', true ) );
	return $deadline ?: clfa_event_start_ts( $event_id );
}

function clfa_rsvp_open( $event_id ) {
	$cutoff = clfa_rsvp_cutoff_ts( $event_id );
	return $cutoff && time() < $cutoff;
}

function clfa_event_when( $event_id ) {
	$start = clfa_event_start_ts( $event_id );
	if ( ! $start ) {
		return '';
	}
	return wp_date( 'l, F j, Y \a\t g:i a', $start ); // renders in site timezone
}

/* Google Calendar link (UTC instants derived from site-timezone fields) */
function clfa_gcal_link( $event_id ) {
	$start = clfa_event_start_ts( $event_id );
	if ( ! $start ) {
		return '';
	}
	$end = clfa_event_end_ts( $event_id );
	$fmt = function ( $ts ) {
		return gmdate( 'Ymd\THis\Z', $ts );
	};
	// add_query_arg url-encodes values — pass them raw to avoid double-encoding
	return add_query_arg( array(
		'action'   => 'TEMPLATE',
		'text'     => get_the_title( $event_id ),
		'dates'    => $fmt( $start ) . '/' . $fmt( $end ),
		'location' => clfa_event_full_location( $event_id ),
		'details'  => wp_strip_all_tags( get_post_field( 'post_content', $event_id ) ),
	), 'https://calendar.google.com/calendar/render' );
}

/* Venue + street address combined, for calendar entries */
function clfa_event_full_location( $event_id ) {
	$loc     = get_post_meta( $event_id, 'clfa_location', true );
	$address = get_post_meta( $event_id, 'clfa_address', true );
	if ( $loc && $address ) {
		return $loc . ', ' . $address;
	}
	return $loc ? $loc : $address;
}

/* ============================================================
   .ics download — /?clfa_ics=<event_id>&t=<rsvp token or member>
   ============================================================ */
function clfa_serve_ics() {
	if ( ! isset( $_GET['clfa_ics'] ) ) {
		return;
	}
	$event_id = (int) $_GET['clfa_ics'];
	$token    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
	$allowed  = clfa_is_member() || ( $token && clfa_get_rsvp_by_token( $token ) && (int) clfa_get_rsvp_by_token( $token )->event_id === $event_id );
	if ( ! $allowed || 'clfa_event' !== get_post_type( $event_id ) ) {
		status_header( 403 );
		exit;
	}
	$start = clfa_event_start_ts( $event_id );
	if ( ! $start ) {
		status_header( 404 );
		exit;
	}
	$end = clfa_event_end_ts( $event_id );
	$fmt = function ( $ts ) {
		return gmdate( 'Ymd\THis\Z', $ts );
	};
	$esc = function ( $s ) {
		return preg_replace( '/([,;\\\\])/', '\\\\$1', str_replace( array( "\r\n", "\n" ), '\n', $s ) );
	};
	$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CLF Alumni//EN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\n" .
		'UID:clfa-event-' . $event_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . "\r\n" .
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n" .
		'DTSTART:' . $fmt( $start ) . "\r\n" .
		'DTEND:' . $fmt( $end ) . "\r\n" .
		'SUMMARY:' . $esc( get_the_title( $event_id ) ) . "\r\n" .
		'LOCATION:' . $esc( clfa_event_full_location( $event_id ) ) . "\r\n" .
		'DESCRIPTION:' . $esc( wp_strip_all_tags( get_post_field( 'post_content', $event_id ) ) ) . "\r\n" .
		"END:VEVENT\r\nEND:VCALENDAR\r\n";
	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="clf-event-' . $event_id . '.ics"' );
	echo $ics; // phpcs:ignore
	exit;
}
add_action( 'init', 'clfa_serve_ics' );

/* ============================================================
   [clf_alumni_events] — upcoming events in the members area
   ============================================================ */
function clfa_events_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}
	if ( isset( $_GET['event'] ) ) {
		return clfa_render_member_event( (int) $_GET['event'] );
	}
	$events = get_posts( array(
		'post_type'      => 'clfa_event',
		'posts_per_page' => 50,
		'meta_key'       => 'clfa_start',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
	) );
	$now      = time();
	$upcoming = array_filter( $events, function ( $e ) use ( $now ) {
		return clfa_event_end_ts( $e->ID ) >= $now;
	} );

	ob_start(); ?>
	<div class="clfa-wrap clfa-events">
	  <div class="clfa-dirhead">
	    <div>
	      <p class="clfa-kicker"><?php esc_html_e( 'Alumni Network — events', 'clf-alumni' ); ?></p>
	      <h2 class="clfa-title"><?php esc_html_e( 'Show', 'clf-alumni' ); ?> <em><?php esc_html_e( 'up.', 'clf-alumni' ); ?></em></h2>
	    </div>
	    <div class="clfa-dirlinks"><a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>">&larr; <?php esc_html_e( 'Directory', 'clf-alumni' ); ?></a></div>
	  </div>
	  <?php if ( empty( $upcoming ) ) : ?>
	    <p class="clfa-muted clfa-empty"><?php esc_html_e( 'No upcoming events yet — check back soon.', 'clf-alumni' ); ?></p>
	  <?php else : ?>
	    <div class="clfa-eventlist">
	      <?php foreach ( $upcoming as $e ) :
			$rsvp = clfa_get_rsvp( $e->ID, get_current_user_id() ); ?>
	        <a class="clfa-cardlink" href="<?php echo esc_url( add_query_arg( 'event', $e->ID, clfa_page_url( 'alumni-events' ) ) ); ?>">
	          <div class="clfa-card clfa-eventcard">
	            <div class="clfa-eventdate">
	              <strong><?php echo esc_html( wp_date( 'j', clfa_event_start_ts( $e->ID ) ) ); ?></strong>
	              <span><?php echo esc_html( wp_date( 'M Y', clfa_event_start_ts( $e->ID ) ) ); ?></span>
	            </div>
	            <div class="clfa-cardbody">
	              <h3><?php echo esc_html( $e->post_title ); ?></h3>
	              <p><?php echo esc_html( clfa_event_when( $e->ID ) ); ?><?php $loc = get_post_meta( $e->ID, 'clfa_location', true ); echo $loc ? esc_html( ' — ' . $loc ) : ''; ?></p>
	              <?php if ( $rsvp && 'yes' === $rsvp->status ) : ?><span class="clfa-badge clfa-badge-yes"><?php echo esc_html( sprintf( __( 'Going · %d', 'clf-alumni' ), (int) $rsvp->guests ) ); ?></span>
	              <?php elseif ( $rsvp && 'no' === $rsvp->status ) : ?><span class="clfa-badge"><?php esc_html_e( 'Not going', 'clf-alumni' ); ?></span>
	              <?php elseif ( $rsvp ) : ?><span class="clfa-badge clfa-badge-open"><?php esc_html_e( 'Please RSVP', 'clf-alumni' ); ?></span><?php endif; ?>
	            </div>
	          </div>
	        </a>
	      <?php endforeach; ?>
	    </div>
	  <?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_events', 'clfa_events_shortcode' );

/* "Who's coming" list — members area only, never on the tokenized RSVP page */
function clfa_event_attendees_html( $event_id ) {
	if ( ! clfa_is_member() || get_post_meta( $event_id, 'clfa_hide_attendees', true ) ) {
		return '';
	}
	$attendees = clfa_event_attendees( $event_id );
	if ( empty( $attendees ) ) {
		return '';
	}
	$total = 0;
	foreach ( $attendees as $a ) {
		$total += (int) $a->guests;
	}
	ob_start(); ?>
	<div class="clfa-attendees">
	  <div class="clfa-section"><?php echo esc_html( sprintf( __( 'Who\'s coming · %d going', 'clf-alumni' ), $total ) ); ?></div>
	  <ul class="clfa-attendlist">
	    <?php foreach ( $attendees as $a ) :
			$user = get_userdata( (int) $a->user_id );
			$name = $a->attendee_name ?: ( $user ? $user->display_name : '' );
			if ( ! $name ) {
				continue;
			} ?>
	      <li class="clfa-attendee">
	        <?php echo clfa_member_photo( (int) $a->user_id ); // phpcs:ignore ?>
	        <span class="clfa-attendname"><?php echo esc_html( $name ); ?><?php if ( (int) $a->guests > 1 ) : ?> <span class="clfa-muted"><?php echo esc_html( sprintf( __( '+%d', 'clf-alumni' ), (int) $a->guests - 1 ) ); ?></span><?php endif; ?></span>
	      </li>
	    <?php endforeach; ?>
	  </ul>
	</div>
	<?php
	return ob_get_clean();
}

/* Single event view in the members area (RSVP inline when invited) */
function clfa_render_member_event( $event_id ) {
	if ( 'clfa_event' !== get_post_type( $event_id ) ) {
		return '<div class="clfa-wrap"><p class="clfa-muted">' . esc_html__( 'Event not found.', 'clf-alumni' ) . '</p></div>';
	}
	$rsvp = clfa_get_rsvp( $event_id, get_current_user_id() );
	ob_start(); ?>
	<div class="clfa-wrap clfa-eventsingle">
	  <p><a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-events' ) ); ?>">&larr; <?php esc_html_e( 'All events', 'clf-alumni' ); ?></a></p>
	  <p class="clfa-kicker"><?php echo esc_html( clfa_event_when( $event_id ) ); ?></p>
	  <h2 class="clfa-title"><?php echo esc_html( get_the_title( $event_id ) ); ?></h2>
	  <?php
	  $loc     = get_post_meta( $event_id, 'clfa_location', true );
	  $address = get_post_meta( $event_id, 'clfa_address', true );
	  $map_q   = $address ? $address : $loc;
	  if ( $loc || $address ) :
			$maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $map_q );
		?>
	    <p class="clfa-muted">
	      <a class="clfa-textlink" href="<?php echo esc_url( $maps_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $loc ? $loc : $address ); ?> ↗</a>
	      <?php if ( $loc && $address ) : ?><br><span class="clfa-small"><?php echo esc_html( $address ); ?></span><?php endif; ?>
	    </p>
	    <?php if ( $address ) : ?>
	      <div class="clfa-eventmap"><iframe src="<?php echo esc_url( 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed' ); ?>" style="width:100%;height:280px;border:0;" loading="lazy" referrerpolicy="no-referrer" title="<?php esc_attr_e( 'Map of event location', 'clf-alumni' ); ?>"></iframe></div>
	    <?php endif; ?>
	  <?php endif; ?>
	  <div class="clfa-bio"><?php echo wpautop( esc_html( wp_strip_all_tags( get_post_field( 'post_content', $event_id ) ) ) ); // phpcs:ignore ?></div>
	  <p class="clfa-calrow">
	    <a class="clfa-textlink" href="<?php echo esc_url( clfa_gcal_link( $event_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add to Google Calendar', 'clf-alumni' ); ?> ↗</a>
	    <a class="clfa-textlink" href="<?php echo esc_url( add_query_arg( 'clfa_ics', $event_id, home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Download .ics', 'clf-alumni' ); ?></a>
	  </p>
	  <?php
	  if ( $rsvp ) {
			echo clfa_rsvp_form_html( $rsvp, clfa_page_url( 'alumni-events' ) . '?event=' . $event_id ); // phpcs:ignore
	  } else {
			echo '<p class="clfa-muted">' . esc_html__( 'This event is invitation-based and you are not on the invite list. Reach out to CLF if you think that\'s a mistake.', 'clf-alumni' ) . '</p>';
	  }
	  echo clfa_event_attendees_html( $event_id ); // phpcs:ignore
	  ?>
	</div>
	<?php
	return ob_get_clean();
}
