<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Save handler for the member profile form
   ============================================================ */
function clfa_handle_profile_save() {
	if ( ! isset( $_POST['clfa_profile_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['clfa_profile_nonce'] ), 'clfa_save_profile' ) ) {
		return;
	}
	if ( ! clfa_is_member() ) {
		return;
	}
	$user_id = get_current_user_id();

	// Administrators may edit a member's profile on their behalf.
	if ( current_user_can( 'manage_options' ) && ! empty( $_POST['clfa_target_user'] ) ) {
		$target = get_userdata( (int) $_POST['clfa_target_user'] );
		if ( $target && in_array( 'clf_alumni', (array) $target->roles, true ) ) {
			$user_id = $target->ID;
		}
	}

	// Names live on the WP user record
	if ( isset( $_POST['clfa_first_name'] ) ) {
		$first = sanitize_text_field( wp_unslash( $_POST['clfa_first_name'] ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['clfa_last_name'] ?? '' ) );
		wp_update_user( array(
			'ID'           => $user_id,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ),
		) );
	}

	foreach ( clfa_profile_fields() as $key => $field ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = wp_unslash( $_POST[ $key ] );
		switch ( $field['type'] ) {
			case 'textarea':
				$val = sanitize_textarea_field( $raw );
				break;
			case 'url':
				$val = esc_url_raw( $raw );
				break;
			default:
				$val = sanitize_text_field( $raw );
		}
		update_user_meta( $user_id, $key, $val );
	}

	// Visibility preferences (unchecked boxes don't POST)
	update_user_meta( $user_id, 'clfa_show_email', isset( $_POST['clfa_show_email'] ) ? 1 : 0 );
	update_user_meta( $user_id, 'clfa_show_phone', isset( $_POST['clfa_show_phone'] ) ? 1 : 0 );

	// Photo upload — stored in a private folder (NOT the public Media Library)
	// and served only to signed-in members via the clfa_photo endpoint.
	if ( ! empty( $_FILES['clfa_photo']['name'] ) ) {
		clfa_store_private_photo( $user_id );
	}

	// Extra profile sections (mentorship opt-in, digest preference, …)
	do_action( 'clfa_profile_extra_save', $user_id );

	$redirect = add_query_arg( 'saved', '1', clfa_page_url( 'alumni-profile' ) );
	if ( $user_id !== get_current_user_id() ) {
		$redirect = add_query_arg( 'member', $user_id, $redirect );
	}
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'template_redirect', 'clfa_handle_profile_save', 5 );

/* ============================================================
   [clf_alumni_profile] — member-facing profile editor
   ============================================================ */
function clfa_profile_shortcode() {
	if ( ! clfa_is_member() ) {
		return '';
	}
	$user_id = get_current_user_id();

	// Administrators can open any member's profile for editing via ?member=ID.
	$editing_other = false;
	if ( current_user_can( 'manage_options' ) && isset( $_GET['member'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$target = get_userdata( (int) $_GET['member'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $target && in_array( 'clf_alumni', (array) $target->roles, true ) ) {
			$user_id       = $target->ID;
			$editing_other = true;
		}
	}
	$user = get_userdata( $user_id );

	ob_start();
	echo clfa_portal_nav( 'profile' ); // phpcs:ignore
	echo clfa_portal_hero( // phpcs:ignore
		esc_html__( 'Your profile', 'clf-alumni' ) . ' <span>· ' . esc_html__( 'Members only', 'clf-alumni' ) . '</span>',
		esc_html__( 'This is', 'clf-alumni' ) . ' <em>' . esc_html__( 'you.', 'clf-alumni' ) . '</em>',
		__( 'Visible only to signed-in CLF alumni — never to the public. Use the checkboxes to choose what fellow alumni can see.', 'clf-alumni' )
	); ?>
	<div class="clfa-wrap clfa-profile-edit" style="padding-top:44px;">
	  <?php if ( isset( $_GET['saved'] ) ) : ?>
	    <p class="clfa-success"><?php esc_html_e( 'Profile saved.', 'clf-alumni' ); ?></p>
	  <?php endif; ?>
	  <?php if ( $editing_other ) : ?>
	    <div class="clfa-notice-warn"><strong><?php esc_html_e( 'Admin mode', 'clf-alumni' ); ?></strong><?php echo esc_html( sprintf( __( 'You are editing the profile of %s on their behalf — not your own.', 'clf-alumni' ), $user->display_name ) ); ?></div>
	  <?php elseif ( current_user_can( 'manage_options' ) && ! in_array( 'clf_alumni', (array) wp_get_current_user()->roles, true ) ) : ?>
	    <div class="clfa-notice-warn"><strong><?php esc_html_e( 'Heads up', 'clf-alumni' ); ?></strong><?php esc_html_e( 'You are signed in as an administrator. This form edits the admin account\'s profile, which does not appear in the member directory or mentor roster. To edit a member\'s profile, open it from the directory and use the "Edit this profile" link.', 'clf-alumni' ); ?></div>
	  <?php endif; ?>

	  <form method="post" enctype="multipart/form-data" class="clfa-form">
	    <?php wp_nonce_field( 'clfa_save_profile', 'clfa_profile_nonce' ); ?>
	    <?php if ( $editing_other ) : ?><input type="hidden" name="clfa_target_user" value="<?php echo esc_attr( $user_id ); ?>"><?php endif; ?>

	    <div class="clfa-section"><?php esc_html_e( 'Photo', 'clf-alumni' ); ?></div>
	    <div class="clfa-photorow">
	      <?php echo clfa_member_photo( $user_id, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	      <label class="clfa-field"><span><?php esc_html_e( 'Upload a photo (JPG/PNG, ideally square)', 'clf-alumni' ); ?></span>
	        <input type="file" name="clfa_photo" accept=".jpg,.jpeg,.png,.webp"></label>
	    </div>

	    <div class="clfa-section"><?php esc_html_e( 'Basics', 'clf-alumni' ); ?></div>
	    <div class="clfa-row">
	      <label class="clfa-field"><span><?php esc_html_e( 'First name', 'clf-alumni' ); ?></span>
	        <input type="text" name="clfa_first_name" value="<?php echo esc_attr( $user->first_name ); ?>" required></label>
	      <label class="clfa-field"><span><?php esc_html_e( 'Last name', 'clf-alumni' ); ?></span>
	        <input type="text" name="clfa_last_name" value="<?php echo esc_attr( $user->last_name ); ?>"></label>
	    </div>

	    <?php foreach ( clfa_profile_fields() as $key => $field ) :
			$val = get_user_meta( $user_id, $key, true ); ?>
	      <label class="clfa-field"><span><?php echo esc_html( $field['label'] ); ?></span>
	        <?php if ( 'textarea' === $field['type'] ) : ?>
	          <textarea name="<?php echo esc_attr( $key ); ?>" rows="5"><?php echo esc_textarea( $val ); ?></textarea>
	        <?php elseif ( 'select' === $field['type'] ) : ?>
	          <select name="<?php echo esc_attr( $key ); ?>">
	            <option value=""><?php esc_html_e( '— Select —', 'clf-alumni' ); ?></option>
	            <?php foreach ( $field['options'] as $opt ) : ?>
	              <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $val, $opt ); ?>><?php echo esc_html( $opt ); ?></option>
	            <?php endforeach; ?>
	          </select>
	        <?php else : ?>
	          <input type="<?php echo 'url' === $field['type'] ? 'url' : 'text'; ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>">
	        <?php endif; ?>
	      </label>
	    <?php endforeach; ?>

	    <?php do_action( 'clfa_profile_extra_fields', $user_id ); ?>

	    <div class="clfa-section"><?php esc_html_e( 'Email preferences', 'clf-alumni' ); ?></div>
	    <label class="clfa-check"><input type="checkbox" name="clfa_weekly_digest" <?php checked( get_user_meta( $user_id, 'clfa_weekly_digest', true ) ); ?>> <?php esc_html_e( 'Email me a weekly digest of new opportunities on the board', 'clf-alumni' ); ?></label>

	    <div class="clfa-section"><?php esc_html_e( 'What other alumni can see', 'clf-alumni' ); ?></div>
	    <label class="clfa-check"><input type="checkbox" name="clfa_show_email" <?php checked( get_user_meta( $user_id, 'clfa_show_email', true ) ); ?>> <?php esc_html_e( 'Show my email address to other members', 'clf-alumni' ); ?></label>
	    <label class="clfa-check"><input type="checkbox" name="clfa_show_phone" <?php checked( get_user_meta( $user_id, 'clfa_show_phone', true ) ); ?>> <?php esc_html_e( 'Show my phone number to other members', 'clf-alumni' ); ?></label>
	    <p class="clfa-muted clfa-small"><?php esc_html_e( 'Everything else on your profile (name, class year, bio, work, links) is visible to signed-in members only.', 'clf-alumni' ); ?></p>

	    <div class="clfa-actions">
	      <button type="submit" class="clfa-btn"><?php esc_html_e( 'Save profile', 'clf-alumni' ); ?></button>
	      <a class="clfa-textlink" href="<?php echo esc_url( clfa_page_url( 'alumni-directory' ) ); ?>"><?php esc_html_e( 'Back to directory', 'clf-alumni' ); ?></a>
	    </div>
	  </form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_profile', 'clfa_profile_shortcode' );
