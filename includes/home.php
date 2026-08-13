<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Member home — [clf_alumni_home] on the alumni-home page.
   Greets the member and surfaces network news: announcements,
   upcoming events (+ RSVP status), fresh opportunities, and
   recently joined members.
   ============================================================ */

/* ---- Announcements CPT (admin-managed, never public) ---- */
function clfa_register_announcement_cpt() {
	register_post_type( 'clfa_announcement', array(
		'labels'       => array(
			'name'          => __( 'Announcements', 'clf-alumni' ),
			'singular_name' => __( 'Announcement', 'clf-alumni' ),
			'add_new_item'  => __( 'Add Announcement', 'clf-alumni' ),
			'edit_item'     => __( 'Edit Announcement', 'clf-alumni' ),
		),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => 'clf-alumni',
		'show_in_rest' => false,
		'supports'     => array( 'title', 'editor' ),
		// Admins only — same rationale as opportunities: don't let
		// Authors/Editors post to the members area via wp-admin.
		'capabilities' => array(
			'edit_post'           => 'manage_options',
			'read_post'           => 'manage_options',
			'delete_post'         => 'manage_options',
			'edit_posts'          => 'manage_options',
			'edit_others_posts'   => 'manage_options',
			'publish_posts'       => 'manage_options',
			'read_private_posts'  => 'manage_options',
			'delete_posts'        => 'manage_options',
			'delete_others_posts' => 'manage_options',
			'create_posts'        => 'manage_options',
		),
		'map_meta_cap' => false,
	) );
}
add_action( 'init', 'clfa_register_announcement_cpt' );

/* Small hint on the announcements list screen */
function clfa_announcement_admin_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-clfa_announcement' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-info"><p>' . esc_html__( 'Published announcements appear on the members\' home page (newest first). Move an announcement to draft or trash to take it down.', 'clf-alumni' ) . '</p></div>';
}
add_action( 'admin_notices', 'clfa_announcement_admin_notice' );

/* ---- Data helpers ---- */
function clfa_home_announcements( $limit = 5 ) {
	return get_posts( array(
		'post_type'      => 'clfa_announcement',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
}

function clfa_home_upcoming_events( $limit = 3 ) {
	$events = get_posts( array(
		'post_type'      => 'clfa_event',
		'posts_per_page' => 50,
		'meta_key'       => 'clfa_start',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
	) );
	$now      = time();
	$upcoming = array_values( array_filter( $events, function ( $e ) use ( $now ) {
		return clfa_event_end_ts( $e->ID ) >= $now;
	} ) );
	return array_slice( $upcoming, 0, $limit );
}

function clfa_home_new_members( $limit = 6 ) {
	$users = get_users( array(
		'role'    => 'clf_alumni',
		'number'  => $limit + 10, // headroom for disabled accounts we skip
		'orderby' => 'registered',
		'order'   => 'DESC',
	) );
	$out = array();
	foreach ( $users as $u ) {
		if ( get_user_meta( $u->ID, 'clfa_disabled', true ) ) {
			continue;
		}
		$out[] = $u;
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

/* ---- RSVP badge shared with the events list look ---- */
function clfa_home_rsvp_badge( $event_id ) {
	if ( ! function_exists( 'clfa_get_rsvp' ) ) {
		return '';
	}
	$rsvp = clfa_get_rsvp( $event_id, get_current_user_id() );
	if ( ! $rsvp ) {
		return '';
	}
	if ( 'yes' === $rsvp->status ) {
		return '<span class="clfa-badge clfa-badge-yes">' . esc_html( sprintf( __( 'Going · %d', 'clf-alumni' ), (int) $rsvp->guests ) ) . '</span>';
	}
	if ( 'no' === $rsvp->status ) {
		return '<span class="clfa-badge">' . esc_html__( 'Not going', 'clf-alumni' ) . '</span>';
	}
	return '<span class="clfa-badge clfa-badge-open">' . esc_html__( 'Please RSVP', 'clf-alumni' ) . '</span>';
}

/* ---- [clf_alumni_home] ---- */
function clfa_home_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}
	$user  = wp_get_current_user();
	$first = $user->first_name ?: $user->display_name;

	$announcements = clfa_home_announcements();
	$events        = clfa_home_upcoming_events();
	$opps          = array_slice( clfa_get_active_opportunities(), 0, 5 );
	$new_members   = clfa_home_new_members();
	$opp_types     = clfa_opportunity_types();

	ob_start(); ?>
	<div class="clfa-wrap clfa-home">
	  <div class="clfa-dirhead">
	    <div>
	      <p class="clfa-kicker"><?php esc_html_e( 'Alumni Network — home', 'clf-alumni' ); ?></p>
	      <h2 class="clfa-title"><?php echo esc_html( sprintf( __( 'Welcome back, %s', 'clf-alumni' ), $first ) ); ?><em><?php esc_html_e( '.', 'clf-alumni' ); ?></em></h2>
	      <p class="clfa-muted"><?php esc_html_e( 'Here\'s what\'s happening across the network.', 'clf-alumni' ); ?></p>
	    </div>
	    <div class="clfa-dirlinks">
	      <a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni' ) ); ?>"><?php esc_html_e( 'Directory', 'clf-alumni' ); ?></a>
	      <a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-mentors' ) ); ?>"><?php esc_html_e( 'Mentors', 'clf-alumni' ); ?></a>
	      <a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-profile' ) ); ?>"><?php esc_html_e( 'My profile', 'clf-alumni' ); ?></a>
	    </div>
	  </div>

	  <?php if ( $announcements ) : ?>
	    <div class="clfa-section"><?php esc_html_e( 'From CLF', 'clf-alumni' ); ?></div>
	    <div class="clfa-announcements">
	      <?php foreach ( $announcements as $a ) : ?>
	        <div class="clfa-announcement">
	          <p class="clfa-anndate"><?php echo esc_html( get_the_date( 'M j, Y', $a ) ); ?></p>
	          <h3><?php echo esc_html( $a->post_title ); ?></h3>
	          <div class="clfa-annbody"><?php echo wpautop( esc_html( wp_strip_all_tags( $a->post_content ) ) ); // phpcs:ignore ?></div>
	        </div>
	      <?php endforeach; ?>
	    </div>
	  <?php endif; ?>

	  <div class="clfa-homegrid">
	    <div class="clfa-homecol">
	      <div class="clfa-section"><?php esc_html_e( 'Upcoming events', 'clf-alumni' ); ?></div>
	      <?php if ( ! $events ) : ?>
	        <p class="clfa-muted"><?php esc_html_e( 'No upcoming events yet — check back soon.', 'clf-alumni' ); ?></p>
	      <?php else : ?>
	        <div class="clfa-eventlist">
	          <?php foreach ( $events as $e ) : ?>
	            <a class="clfa-cardlink" href="<?php echo esc_url( add_query_arg( 'event', $e->ID, clfa_page_url( 'alumni-events' ) ) ); ?>">
	              <div class="clfa-card clfa-eventcard">
	                <div class="clfa-eventdate">
	                  <strong><?php echo esc_html( wp_date( 'j', clfa_event_start_ts( $e->ID ) ) ); ?></strong>
	                  <span><?php echo esc_html( wp_date( 'M Y', clfa_event_start_ts( $e->ID ) ) ); ?></span>
	                </div>
	                <div class="clfa-cardbody">
	                  <h3><?php echo esc_html( $e->post_title ); ?></h3>
	                  <p><?php echo esc_html( clfa_event_when( $e->ID ) ); ?><?php $loc = get_post_meta( $e->ID, 'clfa_location', true ); echo $loc ? esc_html( ' — ' . $loc ) : ''; ?></p>
	                  <?php echo clfa_home_rsvp_badge( $e->ID ); // phpcs:ignore ?>
	                </div>
	              </div>
	            </a>
	          <?php endforeach; ?>
	        </div>
	        <p class="clfa-homemore"><a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-events' ) ); ?>"><?php esc_html_e( 'All events →', 'clf-alumni' ); ?></a></p>
	      <?php endif; ?>

	      <div class="clfa-section"><?php esc_html_e( 'New on the board', 'clf-alumni' ); ?></div>
	      <?php if ( ! $opps ) : ?>
	        <p class="clfa-muted"><?php esc_html_e( 'Nothing on the board right now — got something to share?', 'clf-alumni' ); ?> <a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-board' ) . '#post' ); ?>"><?php esc_html_e( 'Post an opportunity', 'clf-alumni' ); ?></a></p>
	      <?php else : ?>
	        <div class="clfa-homeopps">
	          <?php foreach ( $opps as $p ) :
					$ptype  = get_post_meta( $p->ID, 'clfa_opp_type', true );
					$author = get_userdata( $p->post_author ); ?>
	            <a class="clfa-homeopp" href="<?php echo esc_url( clfa_page_url( 'alumni-board' ) ); ?>">
	              <span class="clfa-badge clfa-badge-<?php echo esc_attr( $ptype ); ?>"><?php echo esc_html( $opp_types[ $ptype ] ?? $ptype ); ?></span>
	              <span class="clfa-homeopptitle"><?php echo esc_html( $p->post_title ); ?></span>
	              <span class="clfa-homeoppmeta"><?php echo esc_html( get_the_date( 'M j', $p ) ); ?><?php echo $author ? esc_html( ' · ' . $author->display_name ) : ''; ?></span>
	            </a>
	          <?php endforeach; ?>
	        </div>
	        <p class="clfa-homemore"><a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-board' ) ); ?>"><?php esc_html_e( 'Open the board →', 'clf-alumni' ); ?></a></p>
	      <?php endif; ?>
	    </div>

	    <div class="clfa-homecol">
	      <div class="clfa-section"><?php esc_html_e( 'New members', 'clf-alumni' ); ?></div>
	      <?php if ( ! $new_members ) : ?>
	        <p class="clfa-muted"><?php esc_html_e( 'No members yet.', 'clf-alumni' ); ?></p>
	      <?php else : ?>
	        <ul class="clfa-attendlist clfa-newmembers">
	          <?php foreach ( $new_members as $m ) : ?>
	            <li class="clfa-attendee">
	              <a class="clfa-cardlink clfa-newmember" href="<?php echo esc_url( add_query_arg( 'member', $m->ID, clfa_page_url( 'alumni' ) ) ); ?>">
	                <?php echo clfa_member_photo( $m->ID ); // phpcs:ignore ?>
	                <span class="clfa-attendname"><?php echo esc_html( $m->display_name ); ?>
	                  <?php $year = get_user_meta( $m->ID, 'clfa_class_year', true ); if ( $year ) : ?>
	                    <span class="clfa-muted clfa-small"><?php echo esc_html( sprintf( __( 'Class of %s', 'clf-alumni' ), $year ) ); ?></span>
	                  <?php endif; ?>
	                </span>
	              </a>
	            </li>
	          <?php endforeach; ?>
	        </ul>
	        <p class="clfa-homemore"><a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni' ) ); ?>"><?php esc_html_e( 'Browse the directory →', 'clf-alumni' ); ?></a></p>
	      <?php endif; ?>
	    </div>
	  </div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_home', 'clfa_home_shortcode' );
