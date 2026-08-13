<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   [clf_alumni_directory] — searchable member directory
   ============================================================ */
function clfa_directory_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}

	// Single-member view: /alumni/?member=123
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
	if ( $search ) {
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = array( 'display_name', 'user_email' );
	}
	$members = get_users( $args );

	// Distinct class years for the filter dropdown
	global $wpdb;
	$years = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'clfa_class_year' AND meta_value != '' ORDER BY meta_value DESC" );

	ob_start(); ?>
	<div class="clfa-wrap clfa-directory">
	  <div class="clfa-dirhead">
	    <div>
	      <p class="clfa-kicker"><?php esc_html_e( 'Alumni Network — members only', 'clf-alumni' ); ?></p>
	      <h2 class="clfa-title"><?php esc_html_e( 'Find your', 'clf-alumni' ); ?> <em><?php esc_html_e( 'people.', 'clf-alumni' ); ?></em></h2>
	    </div>
	    <div class="clfa-dirlinks">
	      <a class="clfa-btn" href="<?php echo esc_url( clfa_page_url( 'alumni-profile' ) ); ?>"><?php esc_html_e( 'Edit my profile', 'clf-alumni' ); ?></a>
	      <a class="clfa-textlink" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'clf-alumni' ); ?></a>
	    </div>
	  </div>

	  <form method="get" class="clfa-filters">
	    <input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name…', 'clf-alumni' ); ?>">
	    <select name="class_year">
	      <option value=""><?php esc_html_e( 'All class years', 'clf-alumni' ); ?></option>
	      <?php foreach ( $years as $y ) : ?>
	        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $class, $y ); ?>><?php echo esc_html( sprintf( __( 'Class of %s', 'clf-alumni' ), $y ) ); ?></option>
	      <?php endforeach; ?>
	    </select>
	    <select name="industry">
	      <option value=""><?php esc_html_e( 'All industries', 'clf-alumni' ); ?></option>
	      <?php foreach ( clfa_industries() as $ind ) : ?>
	        <option value="<?php echo esc_attr( $ind ); ?>" <?php selected( $industry, $ind ); ?>><?php echo esc_html( $ind ); ?></option>
	      <?php endforeach; ?>
	    </select>
	    <button type="submit" class="clfa-btn"><?php esc_html_e( 'Filter', 'clf-alumni' ); ?></button>
	    <?php if ( $search || $class || $industry ) : ?>
	      <a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>"><?php esc_html_e( 'Clear', 'clf-alumni' ); ?></a>
	    <?php endif; ?>
	  </form>

	  <?php if ( empty( $members ) ) : ?>
	    <p class="clfa-muted clfa-empty"><?php esc_html_e( 'No members match — try widening your search.', 'clf-alumni' ); ?></p>
	  <?php else : ?>
	    <p class="clfa-count"><?php echo esc_html( sprintf( _n( '%d member', '%d members', count( $members ), 'clf-alumni' ), count( $members ) ) ); ?></p>
	    <div class="clfa-grid">
	      <?php foreach ( $members as $m ) :
			$year = get_user_meta( $m->ID, 'clfa_class_year', true );
			$prof = get_user_meta( $m->ID, 'clfa_profession', true );
			$comp = get_user_meta( $m->ID, 'clfa_company', true );
			$ind  = get_user_meta( $m->ID, 'clfa_industry', true );
			$url  = add_query_arg( 'member', $m->ID, clfa_page_url( 'alumni-directory' ) ); ?>
	        <a class="clfa-cardlink" href="<?php echo esc_url( $url ); ?>">
	          <div class="clfa-card clfa-membercard">
	            <?php echo clfa_member_photo( $m->ID, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	            <div class="clfa-cardbody">
	              <h3><?php echo esc_html( $m->display_name ); ?></h3>
	              <?php if ( $year ) : ?><span class="clfa-badge"><?php echo esc_html( sprintf( __( 'Class of %s', 'clf-alumni' ), $year ) ); ?></span><?php endif; ?>
	              <?php if ( $prof || $comp ) : ?><p><?php echo esc_html( trim( $prof . ( $prof && $comp ? ' — ' : '' ) . $comp ) ); ?></p><?php endif; ?>
	              <?php if ( $ind ) : ?><small><?php echo esc_html( $ind ); ?></small><?php endif; ?>
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
add_shortcode( 'clf_alumni_directory', 'clfa_directory_shortcode' );

/* ============================================================
   Single member profile view
   ============================================================ */
function clfa_render_single_member( $member_id ) {
	$member = get_userdata( $member_id );
	if ( ! $member || ! in_array( 'clf_alumni', (array) $member->roles, true ) || get_user_meta( $member_id, 'clfa_disabled', true ) ) {
		return '<div class="clfa-wrap"><p class="clfa-muted">' . esc_html__( 'Member not found.', 'clf-alumni' ) . '</p><p><a class="clfa-textlink" href="' . esc_url( clfa_page_url( 'alumni-directory' ) ) . '">&larr; ' . esc_html__( 'Back to directory', 'clf-alumni' ) . '</a></p></div>';
	}

	$meta = array();
	foreach ( array_keys( clfa_profile_fields() ) as $key ) {
		$meta[ $key ] = get_user_meta( $member_id, $key, true );
	}
	$show_email = get_user_meta( $member_id, 'clfa_show_email', true );
	$show_phone = get_user_meta( $member_id, 'clfa_show_phone', true );

	ob_start(); ?>
	<div class="clfa-wrap clfa-single">
	  <p><a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>">&larr; <?php esc_html_e( 'Back to directory', 'clf-alumni' ); ?></a></p>
	  <div class="clfa-singlegrid">
	    <div class="clfa-singlephoto"><?php echo clfa_member_photo( $member_id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	    <div>
	      <p class="clfa-kicker"><?php echo $meta['clfa_class_year'] ? esc_html( sprintf( __( 'CLF Class of %s', 'clf-alumni' ), $meta['clfa_class_year'] ) ) : esc_html__( 'CLF Alumni', 'clf-alumni' ); ?></p>
	      <h2 class="clfa-title"><?php echo esc_html( $member->display_name ); ?></h2>
	      <?php if ( $meta['clfa_spouse'] ) : ?>
	        <p class="clfa-muted"><?php echo esc_html( sprintf( __( 'Married to %s', 'clf-alumni' ), $meta['clfa_spouse'] ) ); ?></p>
	      <?php endif; ?>
	      <?php if ( $meta['clfa_profession'] || $meta['clfa_company'] ) : ?>
	        <p class="clfa-work"><?php echo esc_html( trim( $meta['clfa_profession'] . ( $meta['clfa_profession'] && $meta['clfa_company'] ? ' — ' : '' ) . $meta['clfa_company'] ) ); ?>
	        <?php if ( $meta['clfa_industry'] ) : ?><span class="clfa-badge"><?php echo esc_html( $meta['clfa_industry'] ); ?></span><?php endif; ?></p>
	      <?php endif; ?>
	      <?php if ( $meta['clfa_bio'] ) : ?>
	        <div class="clfa-bio"><?php echo wpautop( esc_html( $meta['clfa_bio'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	      <?php endif; ?>
	      <div class="clfa-contact">
	        <?php if ( $show_email ) : ?>
	          <a class="clfa-btn" href="mailto:<?php echo esc_attr( $member->user_email ); ?>"><?php esc_html_e( 'Email', 'clf-alumni' ); ?></a>
	        <?php endif; ?>
	        <?php if ( $show_phone && $meta['clfa_phone'] ) : ?>
	          <a class="clfa-btn clfa-btn-outline" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $meta['clfa_phone'] ) ); ?>"><?php echo esc_html( $meta['clfa_phone'] ); ?></a>
	        <?php endif; ?>
	        <?php if ( $meta['clfa_linkedin'] ) : ?>
	          <a class="clfa-textlink" href="<?php echo esc_url( $meta['clfa_linkedin'] ); ?>" target="_blank" rel="noopener">LinkedIn ↗</a>
	        <?php endif; ?>
	        <?php if ( $meta['clfa_website'] ) : ?>
	          <a class="clfa-textlink" href="<?php echo esc_url( $meta['clfa_website'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Website', 'clf-alumni' ); ?> ↗</a>
	        <?php endif; ?>
	      </div>
	    </div>
	  </div>
	</div>
	<?php
	return ob_get_clean();
}
