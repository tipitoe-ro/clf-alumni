<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Opportunities board — members-only posts:
   job openings, business opportunities, looking-for.
   CPT clfa_opportunity (never public), shortcode
   [clf_alumni_opportunities] on the alumni-board page.
   ============================================================ */

function clfa_opportunity_types() {
	return array(
		'job'      => __( 'Job opening', 'clf-alumni' ),
		'business' => __( 'Business opportunity', 'clf-alumni' ),
		'looking'  => __( 'Looking for…', 'clf-alumni' ),
	);
}

function clfa_register_opportunity_cpt() {
	register_post_type( 'clfa_opportunity', array(
		'labels'          => array(
			'name'          => __( 'Opportunities', 'clf-alumni' ),
			'singular_name' => __( 'Opportunity', 'clf-alumni' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'clf-alumni',
		'show_in_rest'    => false,
		'supports'        => array( 'title', 'editor', 'author' ),
		// Admin-only in wp-admin — regular Authors/Editors must not be able to
		// bypass the moderated front-end submission flow.
		'capabilities'    => array(
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
		'map_meta_cap'    => false,
	) );
}
add_action( 'init', 'clfa_register_opportunity_cpt' );

/* Moderation toggle lives with the email settings option group */
function clfa_moderation_enabled() {
	return (bool) get_option( 'clfa_moderate_opportunities', 0 );
}

/* ---- Admin: moderation toggle + digest info under the CPT list ---- */
function clfa_opportunity_admin_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-clfa_opportunity' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['clfa_moderation_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clfa_moderation_nonce'] ), 'clfa_moderation' ) ) {
		update_option( 'clfa_moderate_opportunities', empty( $_POST['clfa_moderate'] ) ? 0 : 1 );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Setting saved.', 'clf-alumni' ) . '</p></div>';
	}
	echo '<div class="notice notice-info"><form method="post" style="padding:8px 0;">';
	wp_nonce_field( 'clfa_moderation', 'clfa_moderation_nonce' );
	echo '<label><input type="checkbox" name="clfa_moderate" ' . checked( clfa_moderation_enabled(), true, false ) . '> ' . esc_html__( 'Review posts before they appear (member submissions arrive as Pending)', 'clf-alumni' ) . '</label> ';
	echo '<button class="button" style="margin-left:12px;">' . esc_html__( 'Save', 'clf-alumni' ) . '</button>';
	echo '<span style="margin-left:16px;color:#666;">' . esc_html__( 'Weekly digest goes out Mondays to members who opted in on their profile.', 'clf-alumni' ) . '</span>';
	echo '</form></div>';
}
add_action( 'admin_notices', 'clfa_opportunity_admin_notice' );

/* ============================================================
   Member submission
   ============================================================ */
function clfa_handle_opportunity_submit() {
	if ( ! isset( $_POST['clfa_opp_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clfa_opp_nonce'] ), 'clfa_submit_opportunity' ) || ! clfa_is_member() ) {
		return;
	}
	$title   = sanitize_text_field( wp_unslash( $_POST['opp_title'] ?? '' ) );
	$body    = sanitize_textarea_field( wp_unslash( $_POST['opp_body'] ?? '' ) );
	$type    = sanitize_key( $_POST['opp_type'] ?? '' );
	$contact = sanitize_text_field( wp_unslash( $_POST['opp_contact'] ?? '' ) );
	$expires = sanitize_text_field( wp_unslash( $_POST['opp_expires'] ?? '' ) );
	if ( ! $title || ! $body || ! isset( clfa_opportunity_types()[ $type ] ) ) {
		wp_safe_redirect( add_query_arg( 'posted', 'invalid', clfa_page_url( 'alumni-board' ) ) );
		exit;
	}
	// Validate expiry (date input, site timezone, must be in the future)
	$expires_ts = $expires ? clfa_local_ts( $expires . ' 23:59:59' ) : 0;
	if ( $expires_ts && $expires_ts < time() ) {
		$expires_ts = 0;
		$expires    = '';
	}
	$post_id = wp_insert_post( array(
		'post_type'    => 'clfa_opportunity',
		'post_status'  => clfa_moderation_enabled() ? 'pending' : 'publish',
		'post_title'   => $title,
		'post_content' => $body,
		'post_author'  => get_current_user_id(),
	), true );
	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect( add_query_arg( 'posted', 'error', clfa_page_url( 'alumni-board' ) ) );
		exit;
	}
	update_post_meta( $post_id, 'clfa_opp_type', $type );
	update_post_meta( $post_id, 'clfa_opp_contact', $contact );
	update_post_meta( $post_id, 'clfa_opp_expires', $expires );

	// Tell the admin when moderation is on
	if ( clfa_moderation_enabled() ) {
		wp_mail(
			get_option( 'admin_email' ),
			__( '[CLF Alumni] Opportunity awaiting review', 'clf-alumni' ),
			sprintf( __( "\"%1\$s\" was submitted to the opportunities board and is pending review:\n%2\$s", 'clf-alumni' ), $title, admin_url( 'post.php?post=' . $post_id . '&action=edit' ) )
		);
	}
	wp_safe_redirect( add_query_arg( 'posted', clfa_moderation_enabled() ? 'pending' : 'live', clfa_page_url( 'alumni-board' ) ) );
	exit;
}
add_action( 'template_redirect', 'clfa_handle_opportunity_submit', 5 );

/* Author (or admin) can close their own post */
function clfa_handle_opportunity_close() {
	if ( ! isset( $_GET['clfa_close_opp'] ) || ! clfa_is_member() ) {
		return;
	}
	$post_id = (int) $_GET['clfa_close_opp'];
	if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'clfa_close_' . $post_id ) ) {
		return;
	}
	$post = get_post( $post_id );
	if ( $post && 'clfa_opportunity' === $post->post_type && ( (int) $post->post_author === get_current_user_id() || current_user_can( 'manage_options' ) ) ) {
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
	}
	wp_safe_redirect( add_query_arg( 'posted', 'closed', clfa_page_url( 'alumni-board' ) ) );
	exit;
}
add_action( 'template_redirect', 'clfa_handle_opportunity_close', 5 );

/* ---- Active = published and not expired ---- */
function clfa_opportunity_expired( $post_id ) {
	$expires = get_post_meta( $post_id, 'clfa_opp_expires', true );
	if ( ! $expires ) {
		return false;
	}
	$ts = clfa_local_ts( $expires . ' 23:59:59' );
	return $ts && $ts < time();
}

function clfa_get_active_opportunities( $type = '' ) {
	$q = array(
		'post_type'      => 'clfa_opportunity',
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $type ) {
		$q['meta_key']   = 'clfa_opp_type'; // phpcs:ignore
		$q['meta_value'] = $type;           // phpcs:ignore
	}
	return array_filter( get_posts( $q ), fn( $p ) => ! clfa_opportunity_expired( $p->ID ) );
}

/* ============================================================
   [clf_alumni_opportunities] — the board
   ============================================================ */
function clfa_opportunities_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}
	$type   = sanitize_key( $_GET['type'] ?? '' );
	$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$types  = clfa_opportunity_types();
	$posts  = array_values( clfa_get_active_opportunities( isset( $types[ $type ] ) ? $type : '' ) );
	if ( $search ) {
		$needle = mb_strtolower( $search );
		$posts  = array_values( array_filter( $posts, function ( $p ) use ( $needle ) {
			return false !== mb_strpos( mb_strtolower( $p->post_title . ' ' . $p->post_content ), $needle );
		} ) );
	}
	$digest_on = (bool) get_user_meta( get_current_user_id(), 'clfa_weekly_digest', true );

	ob_start();
	echo clfa_portal_nav( 'board' ); // phpcs:ignore
	echo clfa_portal_hero( // phpcs:ignore
		esc_html__( 'The network at work', 'clf-alumni' ) . ' <span>· ' . esc_html( wp_date( 'Y' ) ) . '</span>',
		esc_html__( 'The', 'clf-alumni' ) . ' <em>' . esc_html__( 'board.', 'clf-alumni' ) . '</em>',
		__( 'Job openings, business opportunities, and asks — shared alumni to alumni, never public. Post one, answer one.', 'clf-alumni' ),
		array( sprintf( _n( '%d active post', '%d active posts', count( $posts ), 'clf-alumni' ), count( $posts ) ) )
	); ?>
	<div class="clfa-wrap clfa-board">
	  <?php if ( isset( $_GET['posted'] ) ) : ?>
	    <?php if ( 'live' === $_GET['posted'] ) : ?><p class="clfa-success"><?php esc_html_e( 'Your post is live on the board.', 'clf-alumni' ); ?></p>
	    <?php elseif ( 'pending' === $_GET['posted'] ) : ?><p class="clfa-success"><?php esc_html_e( 'Thanks — your post is in review and will appear once approved.', 'clf-alumni' ); ?></p>
	    <?php elseif ( 'closed' === $_GET['posted'] ) : ?><p class="clfa-success"><?php esc_html_e( 'Post closed.', 'clf-alumni' ); ?></p>
	    <?php elseif ( 'invalid' === $_GET['posted'] ) : ?><p class="clfa-error"><?php esc_html_e( 'Please give your post a title, a description, and a type.', 'clf-alumni' ); ?></p>
	    <?php else : ?><p class="clfa-error"><?php esc_html_e( 'Something went wrong — please try again.', 'clf-alumni' ); ?></p><?php endif; ?>
	  <?php endif; ?>

	  <div class="clfa-board-toolbar">
	    <div class="clfa-filter-tabs">
	      <a class="clfa-filter-tab <?php echo $type ? '' : 'is-active'; ?>" href="<?php echo esc_url( clfa_page_url( 'alumni-board' ) ); ?>"><?php esc_html_e( 'All posts', 'clf-alumni' ); ?></a>
	      <?php foreach ( $types as $key => $label ) : ?>
	        <a class="clfa-filter-tab <?php echo $type === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'type', $key, clfa_page_url( 'alumni-board' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
	      <?php endforeach; ?>
	    </div>
	    <div class="clfa-board-tools">
	      <form method="get" class="clfa-board-search">🔍<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search the board…', 'clf-alumni' ); ?>">
	        <?php if ( $type ) : ?><input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>"><?php endif; ?>
	      </form>
	      <a class="clfa-btn" href="#post">+ <?php esc_html_e( 'Post to the board', 'clf-alumni' ); ?></a>
	    </div>
	  </div>

	  <p class="clfa-board-note">📌 <span><b><?php esc_html_e( 'House rule:', 'clf-alumni' ); ?></b> <?php esc_html_e( 'posts stay members-only and come down when filled. Reply directly to the poster.', 'clf-alumni' ); ?></span></p>

	  <div class="clfa-board-list">
	  <?php if ( ! $posts ) : ?>
	    <p class="clfa-muted" style="padding:34px 0;"><?php esc_html_e( 'Nothing on the board right now — be the first to post below.', 'clf-alumni' ); ?></p>
	  <?php else : ?>
	      <?php foreach ( $posts as $p ) :
				$ptype   = get_post_meta( $p->ID, 'clfa_opp_type', true );
				$contact = get_post_meta( $p->ID, 'clfa_opp_contact', true );
				$expires = get_post_meta( $p->ID, 'clfa_opp_expires', true );
				$author  = get_userdata( $p->post_author );
				$icons   = array( 'job' => '💼', 'business' => '🤝', 'looking' => '🔎' ); ?>
	        <article class="clfa-opp-card">
	          <div class="clfa-opp-kind is-<?php echo esc_attr( $ptype ); ?>"><?php echo esc_html( ( $icons[ $ptype ] ?? '' ) . ' ' . ( $types[ $ptype ] ?? $ptype ) ); ?></div>
	          <div class="clfa-opp-main">
	            <h2><?php echo esc_html( $p->post_title ); ?></h2>
	            <div class="clfa-opp-body"><?php echo wpautop( esc_html( $p->post_content ) ); // phpcs:ignore ?></div>
	            <?php if ( $author ) :
					$ayear = get_user_meta( $author->ID, 'clfa_class_year', true ); ?>
	              <a class="clfa-opp-by" href="<?php echo esc_url( add_query_arg( 'member', $author->ID, clfa_page_url( 'alumni-directory' ) ) ); ?>">
	                <?php echo clfa_avatar( $author->ID ); // phpcs:ignore ?>
	                <span><strong><?php echo esc_html( $author->display_name ); ?></strong><small><?php echo esc_html( $ayear ? sprintf( __( 'Class of %s', 'clf-alumni' ), $ayear ) : __( 'CLF alumni', 'clf-alumni' ) ); ?></small></span>
	              </a>
	            <?php endif; ?>
	          </div>
	          <div class="clfa-opp-action">
	            <span class="clfa-opp-date"><?php echo esc_html( get_the_date( 'M j, Y', $p ) ); ?></span>
	            <?php if ( $contact ) : ?><span class="clfa-textlink"><?php echo esc_html( $contact ); ?></span><?php endif; ?>
	            <?php if ( $expires ) : ?><span class="clfa-opp-expiry"><?php echo esc_html( sprintf( __( 'Open until %s', 'clf-alumni' ), wp_date( 'M j', clfa_local_ts( $expires . ' 12:00:00' ) ) ) ); ?></span><?php endif; ?>
	            <?php if ( (int) $p->post_author === get_current_user_id() || current_user_can( 'manage_options' ) ) : ?>
	              <a class="clfa-oppclose" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'clfa_close_opp', $p->ID, clfa_page_url( 'alumni-board' ) ), 'clfa_close_' . $p->ID ) ); ?>"><?php esc_html_e( 'Close this post', 'clf-alumni' ); ?></a>
	            <?php endif; ?>
	          </div>
	        </article>
	      <?php endforeach; ?>
	  <?php endif; ?>
	  </div>

	  <div class="clfa-board-footer">
	    <div><strong><?php esc_html_e( 'Never miss a post.', 'clf-alumni' ); ?></strong><small><?php esc_html_e( 'Get a weekly digest of new board activity every Monday morning.', 'clf-alumni' ); ?></small></div>
	    <a class="clfa-digest-btn <?php echo $digest_on ? 'is-on' : ''; ?>" href="<?php echo esc_url( clfa_page_url( 'alumni-profile' ) ); ?>"><?php echo $digest_on ? esc_html__( '✓ Digest is on', 'clf-alumni' ) : esc_html__( 'Turn on in your profile', 'clf-alumni' ); ?></a>
	  </div>

	  <div class="clfa-post-section" id="post">
	    <span class="clfa-mono-label"><?php esc_html_e( 'Share an opportunity', 'clf-alumni' ); ?></span>
	    <h2><?php esc_html_e( 'Put it on the', 'clf-alumni' ); ?> <em><?php esc_html_e( 'board.', 'clf-alumni' ); ?></em></h2>
	  <form method="post" class="clfa-form clfa-oppform">
	    <?php wp_nonce_field( 'clfa_submit_opportunity', 'clfa_opp_nonce' ); ?>
	    <div class="clfa-row">
	      <label class="clfa-field"><span><?php esc_html_e( 'Type', 'clf-alumni' ); ?></span>
	        <select name="opp_type" required>
	          <?php foreach ( $types as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
	        </select></label>
	      <label class="clfa-field"><span><?php esc_html_e( 'Open until (optional)', 'clf-alumni' ); ?></span>
	        <input type="date" name="opp_expires"></label>
	    </div>
	    <label class="clfa-field"><span><?php esc_html_e( 'Title', 'clf-alumni' ); ?></span>
	      <input type="text" name="opp_title" maxlength="150" required></label>
	    <label class="clfa-field"><span><?php esc_html_e( 'Description', 'clf-alumni' ); ?></span>
	      <textarea name="opp_body" rows="5" maxlength="4000" required></textarea></label>
	    <label class="clfa-field"><span><?php esc_html_e( 'How should people reach you? (email, phone, "message me on LinkedIn"…)', 'clf-alumni' ); ?></span>
	      <input type="text" name="opp_contact" maxlength="190"></label>
	    <button type="submit" class="clfa-btn"><?php esc_html_e( 'Post to the board', 'clf-alumni' ); ?></button>
	    <?php if ( clfa_moderation_enabled() ) : ?>
	      <p class="clfa-muted clfa-small"><?php esc_html_e( 'Posts are reviewed by CLF before they appear.', 'clf-alumni' ); ?></p>
	    <?php endif; ?>
	  </form>
	  </div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_opportunities', 'clfa_opportunities_shortcode' );

/* ============================================================
   Weekly digest — Mondays, to members who opted in.
   Sends opportunities published in the last 7 days.
   ============================================================ */
/* WP core ships a 'weekly' schedule since 5.4 — register it defensively for older installs */
function clfa_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => __( 'Once Weekly', 'clf-alumni' ) );
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'clfa_cron_schedules' );

function clfa_schedule_digest() {
	if ( ! wp_next_scheduled( 'clfa_weekly_digest' ) ) {
		// Next Monday 08:00 site time
		$next = ( new DateTimeImmutable( 'next monday 08:00', wp_timezone() ) )->getTimestamp();
		wp_schedule_event( $next, 'weekly', 'clfa_weekly_digest' );
	}
}
add_action( 'init', 'clfa_schedule_digest' );

function clfa_send_weekly_digest() {
	$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	$posts = array_filter( get_posts( array(
		'post_type'      => 'clfa_opportunity',
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'date_query'     => array( array( 'after' => $since, 'column' => 'post_date_gmt' ) ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	) ), fn( $p ) => ! clfa_opportunity_expired( $p->ID ) );
	if ( ! $posts ) {
		return; // nothing new — skip the email entirely
	}
	$types = clfa_opportunity_types();
	$items = '';
	foreach ( $posts as $p ) {
		$author = get_userdata( $p->post_author );
		$items .=
			'<div style="border-left:3px solid #a94d3b;padding:2px 0 2px 14px;margin:0 0 18px;">' .
			'<p style="margin:0;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#d4a492;">' . esc_html( $types[ get_post_meta( $p->ID, 'clfa_opp_type', true ) ] ?? '' ) . '</p>' .
			'<p style="margin:4px 0;font-size:17px;font-weight:600;color:#eee9df;">' . esc_html( $p->post_title ) . '</p>' .
			'<p style="margin:0;line-height:1.6;color:#c6c8c3;font-size:14px;">' . esc_html( wp_trim_words( $p->post_content, 30 ) ) . '</p>' .
			( $author ? '<p style="margin:4px 0 0;font-size:12px;color:#8a929b;">' . esc_html( sprintf( __( 'Posted by %s', 'clf-alumni' ), $author->display_name ) ) . '</p>' : '' ) .
			'</div>';
	}
	$body =
		'<h1 style="font-size:26px;margin:0 0 8px;font-weight:600;color:#eee9df;">' . esc_html__( 'This week on the board', 'clf-alumni' ) . '</h1>' .
		'<p style="line-height:1.7;color:#c6c8c3;margin:0 0 24px;">' . esc_html( sprintf( _n( '%d new opportunity from your fellow alumni.', '%d new opportunities from your fellow alumni.', count( $posts ), 'clf-alumni' ), count( $posts ) ) ) . '</p>' .
		$items;

	$members = get_users( array( 'role' => 'clf_alumni', 'number' => 2000, 'fields' => 'ID', 'meta_key' => 'clfa_weekly_digest', 'meta_value' => 1 ) );
	foreach ( $members as $uid ) {
		if ( get_user_meta( $uid, 'clfa_disabled', true ) ) {
			continue;
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			continue;
		}
		clfa_send_branded(
			$user->user_email,
			__( 'CLF Alumni — new opportunities this week', 'clf-alumni' ),
			$body,
			__( 'Open the board', 'clf-alumni' ),
			clfa_page_url( 'alumni-board' ),
			esc_html__( 'You get this weekly digest because you opted in on your profile — you can turn it off there any time.', 'clf-alumni' )
		);
	}
}
add_action( 'clfa_weekly_digest', 'clfa_send_weekly_digest' );
