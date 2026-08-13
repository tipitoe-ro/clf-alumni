<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Mentorship — mentors are exclusively CLF alumni.
   Opt-in lives on the member profile; discovery via
   [clf_alumni_mentors] on the alumni-mentors page.
   ============================================================ */

function clfa_expertise_areas() {
	$defaults = array(
		'Leadership & management', 'Career transitions', 'Starting a business',
		'Faith & work integration', 'Finance & investing', 'Sales & business development',
		'Marketing & communications', 'Nonprofit & ministry', 'Real estate',
		'Technology', 'Law & governance', 'Family & life balance',
	);
	return apply_filters( 'clfa_expertise_areas', $defaults );
}

function clfa_is_mentor( $user_id ) {
	return (bool) get_user_meta( $user_id, 'clfa_mentor', true );
}

/* ---- Profile form section (rendered by profile.php via hook) ---- */
function clfa_mentor_profile_fields( $user_id ) {
	$areas    = (array) get_user_meta( $user_id, 'clfa_mentor_areas', true );
	$capacity = get_user_meta( $user_id, 'clfa_mentor_capacity', true );
	$note     = get_user_meta( $user_id, 'clfa_mentor_note', true );
	?>
	<div class="clfa-section"><?php esc_html_e( 'Mentorship', 'clf-alumni' ); ?></div>
	<label class="clfa-check clfa-mentortoggle"><input type="checkbox" name="clfa_mentor" <?php checked( clfa_is_mentor( $user_id ) ); ?>>
	  <strong><?php esc_html_e( 'I\'m willing to mentor fellow alumni', 'clf-alumni' ); ?></strong></label>
	<div class="clfa-mentorfields">
	  <p class="clfa-muted clfa-small"><?php esc_html_e( 'You\'ll appear on the "Find a mentor" page. Members can reach out — you decide how it goes from there.', 'clf-alumni' ); ?></p>
	  <span class="clfa-fieldlabel"><?php esc_html_e( 'Areas where you can help', 'clf-alumni' ); ?></span>
	  <div class="clfa-checkgrid">
	    <?php foreach ( clfa_expertise_areas() as $area ) : ?>
	      <label class="clfa-check"><input type="checkbox" name="clfa_mentor_areas[]" value="<?php echo esc_attr( $area ); ?>" <?php checked( in_array( $area, $areas, true ) ); ?>> <?php echo esc_html( $area ); ?></label>
	    <?php endforeach; ?>
	  </div>
	  <label class="clfa-field"><span><?php esc_html_e( 'How many mentees can you take on right now?', 'clf-alumni' ); ?></span>
	    <select name="clfa_mentor_capacity">
	      <?php foreach ( array( '1' => __( 'One at a time', 'clf-alumni' ), '2' => __( 'Up to two', 'clf-alumni' ), '3' => __( 'Up to three', 'clf-alumni' ), 'open' => __( 'Open — depends on the fit', 'clf-alumni' ) ) as $val => $label ) : ?>
	        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $capacity, $val ); ?>><?php echo esc_html( $label ); ?></option>
	      <?php endforeach; ?>
	    </select></label>
	  <label class="clfa-field"><span><?php esc_html_e( 'How you can help (a sentence or two)', 'clf-alumni' ); ?></span>
	    <textarea name="clfa_mentor_note" rows="3" maxlength="400"><?php echo esc_textarea( $note ); ?></textarea></label>
	</div>
	<?php
}
add_action( 'clfa_profile_extra_fields', 'clfa_mentor_profile_fields' );

/* ---- Save (hooked into the profile save handler) ---- */
function clfa_mentor_profile_save( $user_id ) {
	update_user_meta( $user_id, 'clfa_mentor', empty( $_POST['clfa_mentor'] ) ? 0 : 1 ); // phpcs:ignore
	$areas = array_values( array_intersect( array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['clfa_mentor_areas'] ?? array() ) ) ), clfa_expertise_areas() ) ); // phpcs:ignore
	update_user_meta( $user_id, 'clfa_mentor_areas', $areas );
	$cap = sanitize_key( $_POST['clfa_mentor_capacity'] ?? '' ); // phpcs:ignore
	update_user_meta( $user_id, 'clfa_mentor_capacity', in_array( $cap, array( '1', '2', '3', 'open' ), true ) ? $cap : '' );
	update_user_meta( $user_id, 'clfa_mentor_note', sanitize_textarea_field( wp_unslash( $_POST['clfa_mentor_note'] ?? '' ) ) ); // phpcs:ignore
	// Digest preference lives on the profile too (see opportunities.php)
	update_user_meta( $user_id, 'clfa_weekly_digest', empty( $_POST['clfa_weekly_digest'] ) ? 0 : 1 ); // phpcs:ignore
}
add_action( 'clfa_profile_extra_save', 'clfa_mentor_profile_save' );

/* ============================================================
   Express interest — emails the mentor
   ============================================================ */
function clfa_handle_mentor_interest() {
	if ( ! isset( $_POST['clfa_interest_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clfa_interest_nonce'] ), 'clfa_mentor_interest' ) || ! clfa_is_member() ) {
		return;
	}
	$mentor_id = (int) ( $_POST['mentor_id'] ?? 0 );
	$mentor    = get_userdata( $mentor_id );
	if ( ! $mentor || ! clfa_is_mentor( $mentor_id ) || ! clfa_is_member( $mentor_id ) ) {
		return;
	}
	// Simple throttle: one request per member per mentor per week.
	$throttle_key = 'clfa_interest_' . get_current_user_id() . '_' . $mentor_id;
	if ( get_transient( $throttle_key ) ) {
		wp_safe_redirect( add_query_arg( array( 'mentor' => $mentor_id, 'interest' => 'already' ), clfa_page_url( 'alumni-mentors' ) ) );
		exit;
	}
	$me      = wp_get_current_user();
	$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
	$class   = get_user_meta( $me->ID, 'clfa_class_year', true );
	$prof    = trim( get_user_meta( $me->ID, 'clfa_profession', true ) . ' — ' . get_user_meta( $me->ID, 'clfa_company', true ), ' —' );

	$body =
		'<h1 style="font-size:26px;margin:0 0 16px;font-weight:600;color:#eee9df;">' . esc_html( sprintf( __( '%s would like your mentorship', 'clf-alumni' ), $me->display_name ) ) . '</h1>' .
		'<p style="line-height:1.7;color:#c6c8c3;">' . esc_html( sprintf( __( 'Hi %s — a fellow CLF alum saw your mentor profile and raised their hand.', 'clf-alumni' ), $mentor->first_name ?: $mentor->display_name ) ) . '</p>' .
		'<table style="margin:22px 0;color:#eee9df;font-size:14px;line-height:2;">' .
		'<tr><td style="color:#d4a492;padding-right:16px;">' . esc_html__( 'Who', 'clf-alumni' ) . '</td><td>' . esc_html( $me->display_name . ( $class ? ' (Class of ' . $class . ')' : '' ) ) . '</td></tr>' .
		( $prof ? '<tr><td style="color:#d4a492;padding-right:16px;">' . esc_html__( 'Work', 'clf-alumni' ) . '</td><td>' . esc_html( $prof ) . '</td></tr>' : '' ) .
		'<tr><td style="color:#d4a492;padding-right:16px;">' . esc_html__( 'Email', 'clf-alumni' ) . '</td><td><a href="mailto:' . esc_attr( $me->user_email ) . '" style="color:#eee9df;">' . esc_html( $me->user_email ) . '</a></td></tr>' .
		'</table>' .
		( $message ? '<p style="line-height:1.7;color:#c6c8c3;border-left:3px solid #a94d3b;padding-left:14px;">' . nl2br( esc_html( $message ) ) . '</p>' : '' ) .
		'<p style="line-height:1.7;color:#c6c8c3;">' . esc_html__( 'Just reply to this email (or write them directly) to take it from here — CLF simply makes the introduction.', 'clf-alumni' ) . '</p>';

	$sent = clfa_send_branded(
		$mentor->user_email,
		sprintf( __( 'Mentorship request from %s', 'clf-alumni' ), $me->display_name ),
		$body,
		__( 'View their profile', 'clf-alumni' ),
		add_query_arg( 'member', $me->ID, clfa_page_url( 'alumni' ) ),
		esc_html__( 'You\'re receiving this because you opted in as a CLF mentor. You can opt out any time on your profile.', 'clf-alumni' )
	);
	if ( $sent ) {
		set_transient( $throttle_key, 1, WEEK_IN_SECONDS );
	}
	wp_safe_redirect( add_query_arg( array( 'mentor' => $mentor_id, 'interest' => $sent ? 'sent' : 'error' ), clfa_page_url( 'alumni-mentors' ) ) );
	exit;
}
add_action( 'template_redirect', 'clfa_handle_mentor_interest', 5 );

/* ============================================================
   [clf_alumni_mentors] — find-a-mentor view
   ============================================================ */
function clfa_mentors_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}
	$area   = sanitize_text_field( wp_unslash( $_GET['area'] ?? '' ) );
	$industry = sanitize_text_field( wp_unslash( $_GET['industry'] ?? '' ) );
	$year   = sanitize_text_field( wp_unslash( $_GET['year'] ?? '' ) );
	$single = isset( $_GET['mentor'] ) ? (int) $_GET['mentor'] : 0;

	$mentors = get_users( array( 'role' => 'clf_alumni', 'number' => 2000, 'orderby' => 'display_name', 'meta_key' => 'clfa_mentor', 'meta_value' => 1 ) );
	$mentors = array_filter( $mentors, function ( $u ) {
		return ! get_user_meta( $u->ID, 'clfa_disabled', true );
	} );

	ob_start(); ?>
	<div class="clfa-wrap">
	  <?php if ( $single ) :
			$m = get_userdata( $single );
			if ( ! $m || ! clfa_is_mentor( $single ) || ! clfa_is_member( $single ) ) {
				echo '<p>' . esc_html__( 'Mentor not found.', 'clf-alumni' ) . '</p></div>';
				return ob_get_clean();
			}
			$areas = (array) get_user_meta( $single, 'clfa_mentor_areas', true ); ?>
	    <p class="clfa-kicker"><?php esc_html_e( 'Mentorship', 'clf-alumni' ); ?></p>
	    <div class="clfa-single">
	      <div class="clfa-singlephoto"><?php echo clfa_member_photo( $single ); // phpcs:ignore ?></div>
	      <div>
	        <h2 class="clfa-title"><?php echo esc_html( $m->display_name ); ?></h2>
	        <p class="clfa-muted"><?php echo esc_html( trim( get_user_meta( $single, 'clfa_profession', true ) . ' — ' . get_user_meta( $single, 'clfa_company', true ), ' —' ) ); ?>
	          <?php $cy = get_user_meta( $single, 'clfa_class_year', true ); echo $cy ? esc_html( ' · Class of ' . $cy ) : ''; ?></p>
	        <?php if ( $areas ) : ?><p class="clfa-badges"><?php foreach ( $areas as $a ) : ?><span class="clfa-badge"><?php echo esc_html( $a ); ?></span><?php endforeach; ?></p><?php endif; ?>
	        <?php $note = get_user_meta( $single, 'clfa_mentor_note', true ); if ( $note ) : ?>
	          <p class="clfa-mentornote">&ldquo;<?php echo esc_html( $note ); ?>&rdquo;</p>
	        <?php endif; ?>
	      </div>
	    </div>
	    <?php if ( isset( $_GET['interest'] ) ) : ?>
	      <?php if ( 'sent' === $_GET['interest'] ) : ?><p class="clfa-success"><?php esc_html_e( 'Your request is on its way — they\'ll get an email with your details and can reply directly.', 'clf-alumni' ); ?></p>
	      <?php elseif ( 'already' === $_GET['interest'] ) : ?><p class="clfa-muted"><?php esc_html_e( 'You already reached out to this mentor recently — give them a little time to reply.', 'clf-alumni' ); ?></p>
	      <?php else : ?><p class="clfa-error"><?php esc_html_e( 'We couldn\'t send the email. Please try again or contact CLF.', 'clf-alumni' ); ?></p><?php endif; ?>
	    <?php endif; ?>
	    <?php if ( get_current_user_id() !== $single ) : ?>
	      <form method="post" class="clfa-form clfa-interestform">
	        <?php wp_nonce_field( 'clfa_mentor_interest', 'clfa_interest_nonce' ); ?>
	        <input type="hidden" name="mentor_id" value="<?php echo (int) $single; ?>">
	        <div class="clfa-section"><?php esc_html_e( 'Express interest', 'clf-alumni' ); ?></div>
	        <label class="clfa-field"><span><?php esc_html_e( 'A short note about what you\'re looking for (optional but recommended)', 'clf-alumni' ); ?></span>
	          <textarea name="message" rows="4" maxlength="1000"></textarea></label>
	        <button type="submit" class="clfa-btn"><?php esc_html_e( 'Send mentorship request', 'clf-alumni' ); ?></button>
	      </form>
	    <?php endif; ?>
	    <p style="margin-top:26px;"><a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-mentors' ) ); ?>">&larr; <?php esc_html_e( 'All mentors', 'clf-alumni' ); ?></a></p>
	  <?php else :
			// Filters
			$filtered = array_filter( $mentors, function ( $u ) use ( $area, $industry, $year ) {
				if ( $area && ! in_array( $area, (array) get_user_meta( $u->ID, 'clfa_mentor_areas', true ), true ) ) {
					return false;
				}
				if ( $industry && get_user_meta( $u->ID, 'clfa_industry', true ) !== $industry ) {
					return false;
				}
				if ( $year && get_user_meta( $u->ID, 'clfa_class_year', true ) !== $year ) {
					return false;
				}
				return true;
			} );
			$years = array_unique( array_filter( array_map( fn( $u ) => get_user_meta( $u->ID, 'clfa_class_year', true ), $mentors ) ) );
			rsort( $years ); ?>
	    <p class="clfa-kicker"><?php esc_html_e( 'Alumni Network — mentorship', 'clf-alumni' ); ?></p>
	    <h2 class="clfa-title"><?php esc_html_e( 'Find a', 'clf-alumni' ); ?> <em><?php esc_html_e( 'mentor.', 'clf-alumni' ); ?></em></h2>
	    <p class="clfa-muted"><?php esc_html_e( 'Every mentor here is a CLF alum who raised their hand. Reach out — CLF makes the introduction, you take it from there. Want to mentor? Opt in on your profile.', 'clf-alumni' ); ?></p>

	    <form method="get" class="clfa-filters">
	      <select name="area"><option value=""><?php esc_html_e( 'All areas of help', 'clf-alumni' ); ?></option>
	        <?php foreach ( clfa_expertise_areas() as $a ) : ?><option value="<?php echo esc_attr( $a ); ?>" <?php selected( $area, $a ); ?>><?php echo esc_html( $a ); ?></option><?php endforeach; ?></select>
	      <select name="industry"><option value=""><?php esc_html_e( 'All industries', 'clf-alumni' ); ?></option>
	        <?php foreach ( clfa_industries() as $i ) : ?><option value="<?php echo esc_attr( $i ); ?>" <?php selected( $industry, $i ); ?>><?php echo esc_html( $i ); ?></option><?php endforeach; ?></select>
	      <select name="year"><option value=""><?php esc_html_e( 'All class years', 'clf-alumni' ); ?></option>
	        <?php foreach ( $years as $y ) : ?><option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, $y ); ?>><?php echo esc_html( $y ); ?></option><?php endforeach; ?></select>
	      <button type="submit" class="clfa-btn clfa-btn-small"><?php esc_html_e( 'Filter', 'clf-alumni' ); ?></button>
	    </form>

	    <?php if ( ! $filtered ) : ?>
	      <p class="clfa-muted" style="margin-top:30px;"><?php esc_html_e( 'No mentors match those filters yet.', 'clf-alumni' ); ?></p>
	    <?php else : ?>
	      <div class="clfa-grid">
	        <?php foreach ( $filtered as $u ) :
					$areas = (array) get_user_meta( $u->ID, 'clfa_mentor_areas', true ); ?>
	          <a class="clfa-cardlink" href="<?php echo esc_url( add_query_arg( 'mentor', $u->ID, clfa_page_url( 'alumni-mentors' ) ) ); ?>">
	            <div class="clfa-card">
	              <?php echo clfa_member_photo( $u->ID ); // phpcs:ignore ?>
	              <h3><?php echo esc_html( $u->display_name ); ?></h3>
	              <p class="clfa-cardmeta"><?php echo esc_html( trim( get_user_meta( $u->ID, 'clfa_profession', true ) . ' — ' . get_user_meta( $u->ID, 'clfa_company', true ), ' —' ) ); ?></p>
	              <?php if ( $areas ) : ?><p class="clfa-badges"><?php foreach ( array_slice( $areas, 0, 3 ) as $a ) : ?><span class="clfa-badge"><?php echo esc_html( $a ); ?></span><?php endforeach; ?>
	                <?php if ( count( $areas ) > 3 ) : ?><span class="clfa-badge">+<?php echo (int) ( count( $areas ) - 3 ); ?></span><?php endif; ?></p><?php endif; ?>
	            </div>
	          </a>
	        <?php endforeach; ?>
	      </div>
	    <?php endif; ?>
	  <?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_mentors', 'clfa_mentors_shortcode' );

/* ============================================================
   Admin — mentor roster (CLF Alumni → Mentors)
   ============================================================ */
function clfa_mentor_admin_menu() {
	add_submenu_page( 'clf-alumni', __( 'Mentor Roster', 'clf-alumni' ), __( 'Mentor Roster', 'clf-alumni' ), 'manage_options', 'clfa-mentors', 'clfa_mentor_admin_page' );
}
add_action( 'admin_menu', 'clfa_mentor_admin_menu', 16 );

function clfa_mentor_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Remove a mentor from the roster (turns their opt-in off)
	if ( isset( $_GET['clfa_unmentor'] ) && check_admin_referer( 'clfa_unmentor_' . (int) $_GET['clfa_unmentor'] ) ) {
		update_user_meta( (int) $_GET['clfa_unmentor'], 'clfa_mentor', 0 );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Mentor removed from the roster.', 'clf-alumni' ) . '</p></div>';
	}
	$mentors = get_users( array( 'role' => 'clf_alumni', 'number' => 2000, 'orderby' => 'display_name', 'meta_key' => 'clfa_mentor', 'meta_value' => 1 ) );
	echo '<div class="wrap"><h1>' . esc_html__( 'Mentor Roster', 'clf-alumni' ) . '</h1>';
	echo '<p>' . esc_html( sprintf( __( '%d alumni have opted in as mentors. Members opt in/out themselves on their profile; you can also remove someone here.', 'clf-alumni' ), count( $mentors ) ) ) . '</p>';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Class', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Areas', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Capacity', 'clf-alumni' ) . '</th><th>' . esc_html__( 'Note', 'clf-alumni' ) . '</th><th></th></tr></thead><tbody>';
	if ( ! $mentors ) {
		echo '<tr><td colspan="6">' . esc_html__( 'No mentors yet.', 'clf-alumni' ) . '</td></tr>';
	}
	foreach ( $mentors as $u ) {
		echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong><br><small>' . esc_html( $u->user_email ) . '</small></td>';
		echo '<td>' . esc_html( get_user_meta( $u->ID, 'clfa_class_year', true ) ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', (array) get_user_meta( $u->ID, 'clfa_mentor_areas', true ) ) ) . '</td>';
		echo '<td>' . esc_html( get_user_meta( $u->ID, 'clfa_mentor_capacity', true ) ) . '</td>';
		echo '<td>' . esc_html( get_user_meta( $u->ID, 'clfa_mentor_note', true ) ) . '</td>';
		echo '<td><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=clfa-mentors&clfa_unmentor=' . $u->ID ), 'clfa_unmentor_' . $u->ID ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Remove this member from the mentor roster?', 'clf-alumni' ) ) . '\')">' . esc_html__( 'Remove', 'clf-alumni' ) . '</a></td></tr>';
	}
	echo '</tbody></table></div>';
}
