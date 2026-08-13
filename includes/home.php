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
	// Fetch all events (oldest events would otherwise crowd out upcoming
	// ones under a fixed limit); the filter below keeps only future ones.
	$events = get_posts( array(
		'post_type'      => 'clfa_event',
		'posts_per_page' => -1,
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

/* ---- [clf_alumni_home] — morning-briefing dashboard ---- */
function clfa_home_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}
	$user  = wp_get_current_user();
	$first = $user->first_name ?: $user->display_name;

	// Single-announcement view: ?announcement=ID opens the full post.
	if ( isset( $_GET['announcement'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$a = get_post( (int) $_GET['announcement'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $a && 'clfa_announcement' === $a->post_type && 'publish' === $a->post_status ) {
			ob_start();
			echo clfa_portal_nav( 'home' ); // phpcs:ignore
			?>
			<div class="clfa-wrap clfa-ann-single">
			  <a class="clfa-link-mono clfa-back" href="<?php echo esc_url( clfa_page_url( 'alumni-home' ) ); ?>">← <?php esc_html_e( 'Back to home', 'clf-alumni' ); ?></a>
			  <div class="clfa-ann-date"><?php echo esc_html( get_the_date( 'M j, Y', $a ) ); ?></div>
			  <span class="clfa-ann-type"><?php esc_html_e( 'From CLF', 'clf-alumni' ); ?></span>
			  <h1><?php echo esc_html( $a->post_title ); ?></h1>
			  <div class="clfa-ann-full"><?php echo wpautop( esc_html( wp_strip_all_tags( $a->post_content ) ) ); // phpcs:ignore ?></div>
			</div>
			<?php
			return ob_get_clean();
		}
	}

	$hour     = (int) wp_date( 'G' );
	$greeting = __( 'Good morning,', 'clf-alumni' );
	if ( $hour >= 12 && $hour < 18 ) {
		$greeting = __( 'Good afternoon,', 'clf-alumni' );
	} elseif ( $hour >= 18 || $hour < 4 ) {
		$greeting = __( 'Good evening,', 'clf-alumni' );
	}

	$class_year   = get_user_meta( $user->ID, 'clfa_class_year', true );
	$member_since = wp_date( 'Y', strtotime( $user->user_registered ) );
	$meta         = array();
	if ( $class_year ) {
		$meta[] = sprintf( __( 'Class of %s', 'clf-alumni' ), $class_year );
	}
	$meta[] = sprintf( __( 'Member since %s', 'clf-alumni' ), $member_since );

	$announcements = clfa_home_announcements( 4 );
	$events        = clfa_home_upcoming_events();
	$latest_opp    = current( clfa_get_active_opportunities() );
	$new_members   = clfa_home_new_members( 3 );
	$member_count  = count( get_users( array( 'role' => 'clf_alumni', 'fields' => 'ID' ) ) );

	global $wpdb;
	$class_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->usermeta} WHERE meta_key = 'clfa_class_year' AND meta_value != ''" );

	ob_start();
	echo clfa_portal_nav( 'home' ); // phpcs:ignore
	echo clfa_portal_hero( // phpcs:ignore
		esc_html__( 'The alumni portal', 'clf-alumni' ) . ' <span>— ' . esc_html( wp_date( 'Y' ) ) . '</span>',
		esc_html( $greeting ) . '<br><em>' . esc_html( $first ) . '.</em>',
		__( 'Your place to stay close to the people, ideas, and work that make the Forum worth returning to.', 'clf-alumni' ),
		$meta
	); ?>
	<div class="clfa-wrap clfa-home-wrap">
	  <div class="clfa-home-grid">
	    <section>
	      <div class="clfa-section-head"><h2><?php esc_html_e( 'From the Forum', 'clf-alumni' ); ?></h2></div>
	      <?php if ( ! $announcements ) : ?>
	        <p class="clfa-muted" style="padding:26px 0;"><?php esc_html_e( 'No announcements right now — enjoy the quiet.', 'clf-alumni' ); ?></p>
	      <?php else : ?>
	        <?php foreach ( $announcements as $a ) : ?>
	          <article class="clfa-announcement">
	            <div class="clfa-ann-date"><?php echo esc_html( get_the_date( 'M j, Y', $a ) ); ?></div>
	            <div>
	              <span class="clfa-ann-type"><?php esc_html_e( 'From CLF', 'clf-alumni' ); ?></span>
	              <h3><?php echo esc_html( $a->post_title ); ?></h3>
	              <div class="clfa-ann-body clfa-clamp2"><?php echo esc_html( wp_strip_all_tags( $a->post_content ) ); ?></div>
	              <a class="clfa-link-mono clfa-readmore" href="<?php echo esc_url( add_query_arg( 'announcement', $a->ID, clfa_page_url( 'alumni-home' ) ) ); ?>"><?php esc_html_e( 'Read more', 'clf-alumni' ); ?> ↗</a>
	            </div>
	          </article>
	        <?php endforeach; ?>
	      <?php endif; ?>

	      <div class="clfa-home-events">
	        <div class="clfa-section-head"><h2><?php esc_html_e( 'On the calendar', 'clf-alumni' ); ?></h2><a class="clfa-link-mono" href="<?php echo esc_url( clfa_page_url( 'alumni-events' ) ); ?>"><?php esc_html_e( 'All events', 'clf-alumni' ); ?> ↗</a></div>
	        <?php if ( ! $events ) : ?>
	          <p class="clfa-muted" style="padding:19px 0;"><?php esc_html_e( 'No upcoming events yet — check back soon.', 'clf-alumni' ); ?></p>
	        <?php else : ?>
	          <?php foreach ( $events as $e ) :
					$ts    = clfa_event_start_ts( $e->ID );
					$loc   = get_post_meta( $e->ID, 'clfa_location', true );
					$rsvp  = function_exists( 'clfa_get_rsvp' ) ? clfa_get_rsvp( $e->ID, get_current_user_id() ) : null;
					$url   = add_query_arg( 'event', $e->ID, clfa_page_url( 'alumni-events' ) );
					$going = $rsvp && 'yes' === $rsvp->status; ?>
	            <article class="clfa-home-event">
	              <div class="clfa-home-event-date"><span><?php echo esc_html( wp_date( 'M', $ts ) ); ?></span><b><?php echo esc_html( wp_date( 'j', $ts ) ); ?></b></div>
	              <div>
	                <h3><?php echo esc_html( $e->post_title ); ?></h3>
	                <p><?php echo esc_html( ( $loc ? $loc . ' · ' : '' ) . wp_date( 'g:i a', $ts ) ); ?></p>
	              </div>
	              <?php if ( $going ) : ?>
	                <a class="clfa-rsvp-chip is-confirmed" href="<?php echo esc_url( $url ); ?>">✓ <?php esc_html_e( 'Going', 'clf-alumni' ); ?></a>
	              <?php elseif ( $rsvp && 'no' === $rsvp->status ) : ?>
	                <a class="clfa-rsvp-chip" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Declined', 'clf-alumni' ); ?></a>
	              <?php else : ?>
	                <a class="clfa-rsvp-chip" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'RSVP', 'clf-alumni' ); ?></a>
	              <?php endif; ?>
	            </article>
	          <?php endforeach; ?>
	        <?php endif; ?>
	      </div>
	    </section>

	    <aside class="clfa-home-side">
	      <div class="clfa-side-card">
	        <span class="clfa-mono-label"><?php esc_html_e( 'Your circle', 'clf-alumni' ); ?></span>
	        <h2><?php esc_html_e( 'Find the right conversation.', 'clf-alumni' ); ?></h2>
	        <p><?php echo esc_html( sprintf( __( 'Search %1$d leaders across %2$d classes, industries, and neighborhoods.', 'clf-alumni' ), $member_count, max( 1, $class_count ) ) ); ?></p>
	        <a class="clfa-link-mono" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>"><?php esc_html_e( 'Browse directory', 'clf-alumni' ); ?> ›</a>
	      </div>
	      <?php if ( $latest_opp ) : ?>
	        <div class="clfa-side-dark">
	          <span class="clfa-mono-label"><?php esc_html_e( 'New on the board', 'clf-alumni' ); ?></span>
	          <h2><?php echo esc_html( $latest_opp->post_title ); ?></h2>
	          <p><?php echo esc_html( wp_trim_words( $latest_opp->post_content, 22 ) ); ?></p>
	          <a class="clfa-link-mono" href="<?php echo esc_url( clfa_page_url( 'alumni-board' ) ); ?>"><?php esc_html_e( 'View opportunity', 'clf-alumni' ); ?> ↗</a>
	        </div>
	      <?php endif; ?>
	      <div class="clfa-new-members">
	        <div class="clfa-section-head"><h2><?php esc_html_e( 'New members', 'clf-alumni' ); ?></h2><a class="clfa-link-mono" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>"><?php esc_html_e( 'See all', 'clf-alumni' ); ?> ↗</a></div>
	        <?php if ( ! $new_members ) : ?>
	          <p class="clfa-muted" style="padding:13px 0;"><?php esc_html_e( 'No members yet.', 'clf-alumni' ); ?></p>
	        <?php else : ?>
	          <?php foreach ( $new_members as $m ) :
					$prof = get_user_meta( $m->ID, 'clfa_profession', true );
					$comp = get_user_meta( $m->ID, 'clfa_company', true );
					$year = get_user_meta( $m->ID, 'clfa_class_year', true ); ?>
	            <a class="clfa-new-member" href="<?php echo esc_url( add_query_arg( 'member', $m->ID, clfa_page_url( 'alumni-directory' ) ) ); ?>">
	              <?php echo clfa_avatar( $m->ID, 'clfa-avatar-sm' ); // phpcs:ignore ?>
	              <div><strong><?php echo esc_html( $m->display_name ); ?></strong><small><?php echo esc_html( trim( $prof . ( $prof && $comp ? ', ' : '' ) . $comp ) ); ?></small></div>
	              <?php if ( $year ) : ?><span class="clfa-nm-year"><?php echo esc_html( sprintf( __( 'Class of %s', 'clf-alumni' ), $year ) ); ?></span><?php endif; ?>
	            </a>
	          <?php endforeach; ?>
	        <?php endif; ?>
	      </div>
	    </aside>
	  </div>
	</div>
	<section class="clfa-quick">
	  <div class="clfa-quick-inner">
	    <div><span class="clfa-mono-label"><?php esc_html_e( 'Keep moving', 'clf-alumni' ); ?></span><h2><?php esc_html_e( 'Make the most', 'clf-alumni' ); ?><br><?php esc_html_e( 'of your membership.', 'clf-alumni' ); ?></h2></div>
	    <div class="clfa-quick-links">
	      <a class="clfa-quick-link" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>"><strong><?php esc_html_e( 'Meet the network', 'clf-alumni' ); ?></strong><small><?php esc_html_e( 'Directory', 'clf-alumni' ); ?> ↗</small></a>
	      <a class="clfa-quick-link" href="<?php echo esc_url( clfa_page_url( 'alumni-mentors' ) ); ?>"><strong><?php esc_html_e( 'Find a mentor', 'clf-alumni' ); ?></strong><small><?php esc_html_e( 'Mentors', 'clf-alumni' ); ?> ↗</small></a>
	      <a class="clfa-quick-link" href="<?php echo esc_url( clfa_page_url( 'alumni-profile' ) ); ?>"><strong><?php esc_html_e( 'Complete your profile', 'clf-alumni' ); ?></strong><small><?php esc_html_e( 'My profile', 'clf-alumni' ); ?> ↗</small></a>
	    </div>
	  </div>
	</section>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_home', 'clfa_home_shortcode' );
