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
	$etype    = get_post_meta( $post->ID, 'clfa_event_type', true );
	?>
	<table class="form-table">
	  <tr><th><label><?php esc_html_e( 'Event type label', 'clf-alumni' ); ?></label></th>
	    <td><input type="text" name="clfa_event_type" class="regular-text" value="<?php echo esc_attr( $etype ); ?>" placeholder="<?php esc_attr_e( 'e.g. Dinner forum, Golf outing, Roundtable', 'clf-alumni' ); ?>"> <span class="description"><?php esc_html_e( 'Optional — shown above the event title in the members area.', 'clf-alumni' ); ?></span></td></tr>
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
	foreach ( array( 'clfa_start', 'clfa_end', 'clfa_location', 'clfa_address', 'clfa_capacity', 'clfa_deadline', 'clfa_event_type' ) as $key ) {
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
		'posts_per_page' => -1,
		'meta_key'       => 'clfa_start',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
	) );
	$now      = time();
	$me       = get_current_user_id();
	$upcoming = array_values( array_filter( $events, function ( $e ) use ( $now ) {
		return clfa_event_end_ts( $e->ID ) >= $now;
	} ) );
	$past     = array_reverse( array_values( array_filter( $events, function ( $e ) use ( $now ) {
		return clfa_event_end_ts( $e->ID ) < $now && clfa_event_start_ts( $e->ID );
	} ) ) );

	$tab = sanitize_key( $_GET['tab'] ?? '' );
	if ( 'invited' === $tab ) {
		$upcoming = array_values( array_filter( $upcoming, fn( $e ) => (bool) clfa_get_rsvp( $e->ID, $me ) ) );
	} elseif ( 'going' === $tab ) {
		$upcoming = array_values( array_filter( $upcoming, function ( $e ) use ( $me ) {
			$r = clfa_get_rsvp( $e->ID, $me );
			return $r && 'yes' === $r->status;
		} ) );
	}
	$show_archive = isset( $_GET['archive'] );
	$past_show    = $show_archive ? $past : array_slice( $past, 0, 3 );

	ob_start();
	echo clfa_portal_nav( 'events' ); // phpcs:ignore
	echo clfa_portal_hero( // phpcs:ignore
		esc_html__( 'The year ahead', 'clf-alumni' ) . ' <span>· ' . esc_html( wp_date( 'Y' ) ) . '</span>',
		esc_html__( 'Make room', 'clf-alumni' ) . '<br>' . esc_html__( 'for', 'clf-alumni' ) . ' <em>' . esc_html__( 'one another.', 'clf-alumni' ) . '</em>',
		__( 'Dinners, roundtables, and gatherings for the Forum family. RSVP so we can hold your seat at the table.', 'clf-alumni' ),
		array( sprintf( _n( '%d upcoming', '%d upcoming', count( $upcoming ), 'clf-alumni' ), count( $upcoming ) ) )
	); ?>
	<div class="clfa-wrap clfa-events">
	  <div class="clfa-events-toolbar">
	    <div class="clfa-tabs">
	      <a class="<?php echo $tab ? '' : 'is-selected'; ?>" href="<?php echo esc_url( clfa_page_url( 'alumni-events' ) ); ?>"><?php esc_html_e( 'All upcoming', 'clf-alumni' ); ?></a>
	      <a class="<?php echo 'invited' === $tab ? 'is-selected' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'invited', clfa_page_url( 'alumni-events' ) ) ); ?>"><?php esc_html_e( 'My invitations', 'clf-alumni' ); ?></a>
	      <a class="<?php echo 'going' === $tab ? 'is-selected' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'going', clfa_page_url( 'alumni-events' ) ) ); ?>"><?php esc_html_e( "Going", 'clf-alumni' ); ?></a>
	    </div>
	    <span class="clfa-events-count"><?php echo esc_html( sprintf( _n( '%d event', '%d events', count( $upcoming ), 'clf-alumni' ), count( $upcoming ) ) ); ?></span>
	  </div>

	  <div class="clfa-event-list">
	  <?php if ( empty( $upcoming ) ) : ?>
	    <p class="clfa-muted" style="padding:34px 0;"><?php esc_html_e( 'Nothing here yet — check back soon.', 'clf-alumni' ); ?></p>
	  <?php else : ?>
	    <?php foreach ( $upcoming as $e ) :
			$ts    = clfa_event_start_ts( $e->ID );
			$rsvp  = clfa_get_rsvp( $e->ID, $me );
			$etype = get_post_meta( $e->ID, 'clfa_event_type', true );
			$loc   = get_post_meta( $e->ID, 'clfa_location', true );
			$addr  = get_post_meta( $e->ID, 'clfa_address', true );
			$cap   = (int) get_post_meta( $e->ID, 'clfa_capacity', true );
			$url   = add_query_arg( 'event', $e->ID, clfa_page_url( 'alumni-events' ) );
			$hide  = get_post_meta( $e->ID, 'clfa_hide_attendees', true );
			$going = $hide ? array() : clfa_event_attendees( $e->ID );
			$total = 0;
			foreach ( $going as $g ) {
				$total += max( 1, (int) $g->guests );
			} ?>
	      <article class="clfa-event-card">
	        <div class="clfa-event-date">
	          <small><?php echo esc_html( wp_date( 'M', $ts ) ); ?></small>
	          <strong><?php echo esc_html( wp_date( 'j', $ts ) ); ?></strong>
	          <span><?php echo esc_html( wp_date( 'D', $ts ) ); ?></span>
	        </div>
	        <div>
	          <?php if ( $etype ) : ?><span class="clfa-event-type"><?php echo esc_html( $etype ); ?></span><?php endif; ?>
	          <h2><?php echo esc_html( $e->post_title ); ?></h2>
	          <div class="clfa-event-meta">
	            <span>🕐 <?php echo esc_html( wp_date( 'g:i a', $ts ) ); ?></span>
	            <?php if ( $loc ) : ?>
	              <span>📍 <a class="clfa-venue" href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $addr ?: $loc ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $loc ); ?></a></span>
	            <?php endif; ?>
	          </div>
	        </div>
	        <div class="clfa-attendees-col">
	          <?php if ( $going ) : ?>
	            <p><?php esc_html_e( "Who's coming", 'clf-alumni' ); ?></p>
	            <div class="clfa-people">
	              <?php foreach ( array_slice( $going, 0, 5 ) as $g ) {
					echo clfa_avatar( (int) $g->user_id, 'clfa-avatar-xs' ); // phpcs:ignore
	              } ?>
	              <?php if ( count( $going ) > 5 ) : ?><span class="clfa-people-more">+<?php echo esc_html( count( $going ) - 5 ); ?></span><?php endif; ?>
	            </div>
	            <p class="clfa-going-line"><b><?php echo esc_html( sprintf( _n( '%d going', '%d going', $total, 'clf-alumni' ), $total ) ); ?></b><?php echo $cap ? esc_html( ' · ' . sprintf( __( 'capacity %d', 'clf-alumni' ), $cap ) ) : ''; ?></p>
	          <?php elseif ( $hide ) : ?>
	            <p class="clfa-going-line"><?php esc_html_e( 'Guest list kept private for this gathering.', 'clf-alumni' ); ?></p>
	          <?php else : ?>
	            <p class="clfa-going-line"><?php esc_html_e( 'Be the first to reply.', 'clf-alumni' ); ?></p>
	          <?php endif; ?>
	        </div>
	        <div class="clfa-respond">
	          <?php if ( $rsvp && 'yes' === $rsvp->status ) : ?>
	            <span class="clfa-status is-going">● <?php echo esc_html( sprintf( __( "You're going · %d", 'clf-alumni' ), (int) $rsvp->guests ) ); ?></span>
	            <a class="clfa-textlink" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Change response', 'clf-alumni' ); ?> →</a>
	          <?php elseif ( $rsvp && 'no' === $rsvp->status ) : ?>
	            <span class="clfa-status is-declined"><?php esc_html_e( 'You declined', 'clf-alumni' ); ?></span>
	            <a class="clfa-textlink" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Change response', 'clf-alumni' ); ?> →</a>
	          <?php elseif ( $rsvp ) : ?>
	            <span class="clfa-status is-awaiting"><?php esc_html_e( 'Awaiting your reply', 'clf-alumni' ); ?></span>
	            <a class="clfa-rsvp-btn" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'RSVP now', 'clf-alumni' ); ?> <span>→</span></a>
	          <?php else : ?>
	            <a class="clfa-textlink" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View details', 'clf-alumni' ); ?> →</a>
	          <?php endif; ?>
	        </div>
	      </article>
	    <?php endforeach; ?>
	  <?php endif; ?>
	  </div>

	  <?php if ( $past ) : ?>
	    <section class="clfa-past">
	      <div class="clfa-past-head">
	        <div><span class="clfa-mono-label"><?php esc_html_e( 'Looking back', 'clf-alumni' ); ?></span><h2><?php esc_html_e( 'Past events', 'clf-alumni' ); ?></h2></div>
	        <?php if ( count( $past ) > 3 ) : ?>
	          <a href="<?php echo esc_url( $show_archive ? clfa_page_url( 'alumni-events' ) . '#past' : add_query_arg( 'archive', '1', clfa_page_url( 'alumni-events' ) ) . '#past' ); ?>" id="past"><?php echo $show_archive ? esc_html__( 'Show fewer', 'clf-alumni' ) : esc_html__( 'View full archive', 'clf-alumni' ); ?></a>
	        <?php endif; ?>
	      </div>
	      <div class="clfa-past-list">
	        <?php foreach ( $past_show as $e ) :
				$ts   = clfa_event_start_ts( $e->ID );
				$loc  = get_post_meta( $e->ID, 'clfa_location', true );
				$rows = get_post_meta( $e->ID, 'clfa_hide_attendees', true ) ? array() : clfa_event_attendees( $e->ID );
				$tot  = 0;
				foreach ( $rows as $g ) {
					$tot += max( 1, (int) $g->guests );
				} ?>
	          <div class="clfa-past-item">
	            <small><?php echo esc_html( wp_date( 'M Y', $ts ) ); ?></small>
	            <h3><?php echo esc_html( $e->post_title ); ?></h3>
	            <p><?php echo esc_html( trim( ( $loc ? $loc : '' ) . ( $tot ? ( $loc ? ' · ' : '' ) . sprintf( __( '%d joined', 'clf-alumni' ), $tot ) : '' ) ) ); ?></p>
	          </div>
	        <?php endforeach; ?>
	      </div>
	    </section>
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
	$rsvp  = clfa_get_rsvp( $event_id, get_current_user_id() );
	$etype = get_post_meta( $event_id, 'clfa_event_type', true );
	ob_start();
	echo clfa_portal_nav( 'events' ); // phpcs:ignore ?>
	<div class="clfa-wrap clfa-eventsingle clfa-event-single">
	  <a class="clfa-back" href="<?php echo esc_url( clfa_page_url( 'alumni-events' ) ); ?>">← <?php esc_html_e( 'All events', 'clf-alumni' ); ?></a>
	  <p class="clfa-kicker"><?php echo esc_html( trim( ( $etype ? $etype . ' · ' : '' ) . clfa_event_when( $event_id ) ) ); ?></p>
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
