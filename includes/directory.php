<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   [clf_alumni_directory] — searchable member directory
   ============================================================ */
function clfa_directory_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}

	// Single-member view: /alumni-directory/?member=123
	if ( isset( $_GET['member'] ) ) {
		return clfa_render_single_member( (int) $_GET['member'] );
	}

	$search   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$class    = isset( $_GET['class_year'] ) ? sanitize_text_field( wp_unslash( $_GET['class_year'] ) ) : '';
	$industry = isset( $_GET['industry'] ) ? sanitize_text_field( wp_unslash( $_GET['industry'] ) ) : '';

	$args = array(
		'role'    => 'clf_alumni',
		'number'  => 500,
		'orderby' => 'display_name',
		'order'   => 'ASC',
		'meta_query' => array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array( 'key' => 'clfa_disabled', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'clfa_disabled', 'value' => '', 'compare' => '=' ),
			),
		),
	);
	if ( $class ) {
		$args['meta_query'][] = array( 'key' => 'clfa_class_year', 'value' => $class, 'compare' => '=' );
	}
	if ( $industry ) {
		$args['meta_query'][] = array( 'key' => 'clfa_industry', 'value' => $industry, 'compare' => '=' );
	}
	$members = get_users( $args );

	// Search across name, role, and company (mockup: "Search by name, role, or company")
	if ( $search ) {
		$needle  = mb_strtolower( $search );
		$members = array_values( array_filter( $members, function ( $m ) use ( $needle ) {
			$hay = mb_strtolower( $m->display_name . ' ' . get_user_meta( $m->ID, 'clfa_profession', true ) . ' ' . get_user_meta( $m->ID, 'clfa_company', true ) );
			return false !== mb_strpos( $hay, $needle );
		} ) );
	}

	// Distinct class years for the filter dropdown
	global $wpdb;
	$years = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'clfa_class_year' AND meta_value != '' ORDER BY meta_value DESC" );

	$total_members = count( get_users( array( 'role' => 'clf_alumni', 'fields' => 'ID' ) ) );
	$class_count   = count( $years );

	ob_start();
	echo clfa_portal_nav( 'directory' ); // phpcs:ignore
	echo clfa_portal_hero( // phpcs:ignore
		esc_html__( 'The member register', 'clf-alumni' ) . ' <span>· ' . esc_html( wp_date( 'Y' ) ) . '</span>',
		esc_html__( 'People who', 'clf-alumni' ) . ' <em>' . esc_html__( 'lead here.', 'clf-alumni' ) . '</em>',
		__( 'Every member of the Forum, in one place. Find a classmate, an industry peer, or your next great conversation.', 'clf-alumni' ),
		array(
			sprintf( _n( '%d member', '%d members', $total_members, 'clf-alumni' ), $total_members ),
			sprintf( _n( '%d class', '%d classes', $class_count, 'clf-alumni' ), max( 1, $class_count ) ),
		)
	); ?>
	<div class="clfa-wrap clfa-directory">
	  <form method="get" class="clfa-toolbar">
	    <label class="clfa-searchbox">🔍<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, role, or company…', 'clf-alumni' ); ?>"></label>
	    <span class="clfa-filter"><select name="class_year" onchange="this.form.submit()">
	      <option value=""><?php esc_html_e( 'All classes', 'clf-alumni' ); ?></option>
	      <?php foreach ( $years as $y ) : ?>
	        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $class, $y ); ?>><?php echo esc_html( sprintf( __( 'Class of %s', 'clf-alumni' ), $y ) ); ?></option>
	      <?php endforeach; ?>
	    </select></span>
	    <span class="clfa-filter"><select name="industry" onchange="this.form.submit()">
	      <option value=""><?php esc_html_e( 'All industries', 'clf-alumni' ); ?></option>
	      <?php foreach ( clfa_industries() as $ind ) : ?>
	        <option value="<?php echo esc_attr( $ind ); ?>" <?php selected( $industry, $ind ); ?>><?php echo esc_html( $ind ); ?></option>
	      <?php endforeach; ?>
	    </select></span>
	    <button type="submit" class="clfa-filterbtn"><?php esc_html_e( 'Search', 'clf-alumni' ); ?></button>
	    <?php if ( $search || $class || $industry ) : ?>
	      <a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>"><?php esc_html_e( 'Clear', 'clf-alumni' ); ?></a>
	    <?php endif; ?>
	  </form>

	  <div class="clfa-results-line">
	    <h2><?php echo ( $search || $class || $industry ) ? esc_html__( 'Matching members', 'clf-alumni' ) : esc_html__( 'All members', 'clf-alumni' ); ?></h2>
	    <span><?php echo esc_html( sprintf( __( 'Showing %d', 'clf-alumni' ), count( $members ) ) ); ?></span>
	  </div>

	  <div class="clfa-member-grid">
	    <?php if ( empty( $members ) ) : ?>
	      <div class="clfa-empty-state"><p><?php esc_html_e( 'No members match.', 'clf-alumni' ); ?></p><?php esc_html_e( 'Try widening your search or clearing the filters.', 'clf-alumni' ); ?></div>
	    <?php else : ?>
	      <?php foreach ( $members as $m ) :
			$year = get_user_meta( $m->ID, 'clfa_class_year', true );
			$prof = get_user_meta( $m->ID, 'clfa_profession', true );
			$comp = get_user_meta( $m->ID, 'clfa_company', true );
			$city = get_user_meta( $m->ID, 'clfa_city', true );
			$url  = add_query_arg( 'member', $m->ID, clfa_page_url( 'alumni-directory' ) ); ?>
	        <a class="clfa-member-card" href="<?php echo esc_url( $url ); ?>">
	          <?php echo clfa_avatar( $m->ID ); // phpcs:ignore ?>
	          <?php if ( $year ) : ?><span class="clfa-card-year"><?php echo esc_html( sprintf( __( 'Class of %s', 'clf-alumni' ), $year ) ); ?></span><?php endif; ?>
	          <h3><?php echo esc_html( $m->display_name ); ?></h3>
	          <?php if ( $prof ) : ?><p class="clfa-member-role"><?php echo esc_html( $prof ); ?></p><?php endif; ?>
	          <?php if ( $comp ) : ?><p class="clfa-member-company"><?php echo esc_html( $comp ); ?></p><?php endif; ?>
	          <span class="clfa-card-bottom">
	            <span class="clfa-member-city"><?php echo esc_html( $city ?: '' ); ?></span>
	            <span class="clfa-view-profile"><?php esc_html_e( 'View profile', 'clf-alumni' ); ?> →</span>
	          </span>
	        </a>
	      <?php endforeach; ?>
	    <?php endif; ?>
	  </div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_directory', 'clfa_directory_shortcode' );

/* ============================================================
   Single member profile view
   ============================================================ */
function clfa_render_single_member( $member_id ) {
	$member = get_userdata( $member_id );
	if ( ! $member || ! in_array( 'clf_alumni', (array) $member->roles, true ) || get_user_meta( $member_id, 'clfa_disabled', true ) ) {
		return clfa_portal_nav( 'directory' ) . '<div class="clfa-wrap"><p class="clfa-muted" style="padding-top:47px;">' . esc_html__( 'Member not found.', 'clf-alumni' ) . '</p><p><a class="clfa-back" href="' . esc_url( clfa_page_url( 'alumni-directory' ) ) . '">&larr; ' . esc_html__( 'Back to directory', 'clf-alumni' ) . '</a></p></div>';
	}

	$meta = array();
	foreach ( array_keys( clfa_profile_fields() ) as $key ) {
		$meta[ $key ] = get_user_meta( $member_id, $key, true );
	}
	$show_email = get_user_meta( $member_id, 'clfa_show_email', true );
	$show_phone = get_user_meta( $member_id, 'clfa_show_phone', true );
	$first      = $member->first_name ?: $member->display_name;
	$city       = $meta['clfa_city'] ?? '';
	$is_mentor  = function_exists( 'clfa_is_mentor' ) && clfa_is_mentor( $member_id );

	// Their live board posts
	$their_opps = get_posts( array(
		'post_type'      => 'clfa_opportunity',
		'post_status'    => 'publish',
		'author'         => $member_id,
		'posts_per_page' => -1,
	) );
	$their_opps = array_slice( array_values( array_filter( $their_opps, function ( $p ) {
		return ! clfa_opportunity_expired( $p->ID );
	} ) ), 0, 4 );
	$opp_types  = clfa_opportunity_types();

	ob_start();
	echo clfa_portal_nav( 'directory' ); // phpcs:ignore ?>
	<div class="clfa-wrap clfa-single">
	  <a class="clfa-back" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>">← <?php esc_html_e( 'Back to directory', 'clf-alumni' ); ?></a>
	  <?php if ( current_user_can( 'manage_options' ) ) : ?>
	    <a class="clfa-link-mono" style="float:right;" href="<?php echo esc_url( add_query_arg( 'member', $member_id, clfa_page_url( 'alumni-profile' ) ) ); ?>"><?php esc_html_e( 'Edit this profile (admin)', 'clf-alumni' ); ?> ↗</a>
	  <?php endif; ?>
	  <div style="border-bottom:1px solid #cec4b4;padding-bottom:44px;">
	    <p class="clfa-kicker"><?php esc_html_e( 'A fellow member', 'clf-alumni' ); ?><?php echo $city ? esc_html( ' · ' . $city ) : ''; ?></p>
	    <h1 class="clfa-title" style="font-size:clamp(48px,6vw,84px);letter-spacing:-.08em;line-height:.88;margin:0;"><?php esc_html_e( 'Meet', 'clf-alumni' ); ?> <em><?php echo esc_html( $member->display_name ); ?>.</em></h1>
	  </div>

	  <div class="clfa-profile-grid">
	    <div>
	      <div class="clfa-identity">
	        <?php echo clfa_avatar( $member_id, 'clfa-avatar-lg' ); // phpcs:ignore ?>
	        <div>
	          <h2><?php echo esc_html( $member->display_name ); ?></h2>
	          <?php if ( $meta['clfa_profession'] ) : ?><p><?php echo esc_html( $meta['clfa_profession'] ); ?></p><?php endif; ?>
	          <?php if ( $meta['clfa_company'] ) : ?><p><strong><?php echo esc_html( $meta['clfa_company'] ); ?></strong></p><?php endif; ?>
	          <p class="clfa-class"><?php
				$bits = array();
				if ( $meta['clfa_class_year'] ) {
					$bits[] = sprintf( __( 'Class of %s', 'clf-alumni' ), $meta['clfa_class_year'] );
				}
				if ( $meta['clfa_industry'] ) {
					$bits[] = $meta['clfa_industry'];
				}
				echo esc_html( implode( ' · ', $bits ) );
	          ?></p>
	        </div>
	      </div>

	      <?php if ( $meta['clfa_bio'] ) : ?>
	        <div class="clfa-bio-block">
	          <h3><?php esc_html_e( 'In their words', 'clf-alumni' ); ?></h3>
	          <div class="clfa-bio"><?php echo wpautop( esc_html( $meta['clfa_bio'] ) ); // phpcs:ignore ?></div>
	        </div>
	      <?php endif; ?>

	      <div class="clfa-detail-list">
	        <?php
	        $details = array(
				__( 'At the table', 'clf-alumni' )  => $meta['clfa_industry'],
				__( 'Class year', 'clf-alumni' )    => $meta['clfa_class_year'] ? sprintf( __( 'Class of %s', 'clf-alumni' ), $meta['clfa_class_year'] ) : '',
				__( 'Based in', 'clf-alumni' )      => $city,
				__( 'Spouse', 'clf-alumni' )        => $meta['clfa_spouse'],
	        );
	        foreach ( $details as $label => $value ) :
				if ( ! $value ) {
					continue;
				} ?>
	          <div class="clfa-detail"><label><?php echo esc_html( $label ); ?></label><strong><?php echo esc_html( $value ); ?></strong></div>
	        <?php endforeach; ?>
	      </div>
	    </div>

	    <aside class="clfa-profile-side">
	      <div class="clfa-contact-card">
	        <span class="clfa-mono-label"><?php esc_html_e( 'Get in touch', 'clf-alumni' ); ?></span>
	        <h3><?php echo esc_html( sprintf( __( 'Reach %s', 'clf-alumni' ), $first ) ); ?></h3>
	        <?php if ( $show_email ) : ?>
	          <a class="clfa-contact-row" href="mailto:<?php echo esc_attr( $member->user_email ); ?>">✉ <strong><?php echo esc_html( $member->user_email ); ?></strong><span><?php esc_html_e( 'Email', 'clf-alumni' ); ?></span></a>
	        <?php endif; ?>
	        <?php if ( $show_phone && $meta['clfa_phone'] ) : ?>
	          <a class="clfa-contact-row" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $meta['clfa_phone'] ) ); ?>">☎ <strong><?php echo esc_html( $meta['clfa_phone'] ); ?></strong><span><?php esc_html_e( 'Phone', 'clf-alumni' ); ?></span></a>
	        <?php endif; ?>
	        <?php if ( $meta['clfa_linkedin'] ) : ?>
	          <a class="clfa-contact-row" href="<?php echo esc_url( $meta['clfa_linkedin'] ); ?>" target="_blank" rel="noopener">in <strong>LinkedIn</strong><span><?php esc_html_e( 'Profile', 'clf-alumni' ); ?> ↗</span></a>
	        <?php endif; ?>
	        <?php if ( $meta['clfa_website'] ) : ?>
	          <a class="clfa-contact-row" href="<?php echo esc_url( $meta['clfa_website'] ); ?>" target="_blank" rel="noopener">🌐 <strong><?php esc_html_e( 'Website', 'clf-alumni' ); ?></strong><span>↗</span></a>
	        <?php endif; ?>
	        <?php if ( ! $show_email && ! ( $show_phone && $meta['clfa_phone'] ) && ! $meta['clfa_linkedin'] && ! $meta['clfa_website'] ) : ?>
	          <p style="font-size:12px;color:#6b6f66;margin:14px 0 0;"><?php echo esc_html( sprintf( __( '%s keeps contact details private.', 'clf-alumni' ), $first ) ); ?></p>
	        <?php endif; ?>
	        <?php if ( $show_email ) : ?>
	          <a class="clfa-message-btn" href="mailto:<?php echo esc_attr( $member->user_email ); ?>"><?php echo esc_html( sprintf( __( 'Send %s a note', 'clf-alumni' ), $first ) ); ?> <span>→</span></a>
	        <?php endif; ?>
	      </div>

	      <?php if ( $is_mentor ) : ?>
	        <div class="clfa-mentor-card">
	          <span class="clfa-mono-label"><?php esc_html_e( 'Mentoring', 'clf-alumni' ); ?></span>
	          <p><?php
				$note = get_user_meta( $member_id, 'clfa_mentor_note', true );
				echo esc_html( $note ?: sprintf( __( '%s is part of the CLF mentor roster and open to walking alongside fellow members.', 'clf-alumni' ), $first ) );
	          ?></p>
	          <div class="clfa-mentor-status">● <?php esc_html_e( 'Open to mentoring', 'clf-alumni' ); ?></div>
	          <p style="margin:16px 0 0;"><a class="clfa-link-mono" href="<?php echo esc_url( add_query_arg( 'mentor', $member_id, clfa_page_url( 'alumni-mentors' ) ) ); ?>"><?php esc_html_e( 'View mentor profile', 'clf-alumni' ); ?> ↗</a></p>
	        </div>
	      <?php endif; ?>
	    </aside>
	  </div>

	  <?php if ( $their_opps ) : ?>
	    <section class="clfa-member-opps">
	      <div class="clfa-member-opps-head">
	        <h2><?php echo esc_html( sprintf( __( 'On %s\'s board', 'clf-alumni' ), $first ) ); ?></h2>
	        <span><?php echo esc_html( sprintf( _n( '%d active post', '%d active posts', count( $their_opps ), 'clf-alumni' ), count( $their_opps ) ) ); ?></span>
	      </div>
	      <?php foreach ( $their_opps as $p ) :
			$ptype = get_post_meta( $p->ID, 'clfa_opp_type', true ); ?>
	        <article class="clfa-member-opp">
	          <div>
	            <span class="clfa-opp-kind is-<?php echo esc_attr( $ptype ); ?>"><?php echo esc_html( $opp_types[ $ptype ] ?? $ptype ); ?></span>
	            <h3><?php echo esc_html( $p->post_title ); ?></h3>
	            <p><?php echo esc_html( wp_trim_words( $p->post_content, 28 ) ); ?></p>
	          </div>
	          <a class="clfa-link-mono" href="<?php echo esc_url( clfa_page_url( 'alumni-board' ) ); ?>"><?php esc_html_e( 'View on the board', 'clf-alumni' ); ?> →</a>
	        </article>
	      <?php endforeach; ?>
	    </section>
	  <?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
