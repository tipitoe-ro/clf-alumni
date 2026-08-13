<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Members-only guard for alumni pages
   ============================================================ */
function clfa_guard_members_area() {
	if ( ! is_page( array( 'alumni', 'alumni-profile' ) ) ) {
		return;
	}
	if ( clfa_is_member() ) {
		return;
	}
	$login = clfa_page_url( 'alumni-login' );
	$login = add_query_arg( 'redirect_to', rawurlencode( get_permalink() ), $login );
	wp_safe_redirect( $login );
	exit;
}
add_action( 'template_redirect', 'clfa_guard_members_area' );

/* Disabled members can't sign in at all */
function clfa_block_disabled( $user ) {
	if ( $user instanceof WP_User && in_array( 'clf_alumni', (array) $user->roles, true ) && get_user_meta( $user->ID, 'clfa_disabled', true ) ) {
		return new WP_Error( 'clfa_disabled', __( 'Your alumni account is currently inactive. Please contact CLF.', 'clf-alumni' ) );
	}
	return $user;
}
add_filter( 'authenticate', 'clfa_block_disabled', 99 );

/* Keep alumni out of wp-admin and hide the admin bar for them */
function clfa_redirect_alumni_from_admin() {
	if ( is_admin() && ! wp_doing_ajax() && ! current_user_can( 'edit_posts' ) && clfa_is_member() ) {
		wp_safe_redirect( clfa_page_url( 'alumni' ) );
		exit;
	}
}
add_action( 'admin_init', 'clfa_redirect_alumni_from_admin' );

function clfa_hide_admin_bar( $show ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	return $show;
}
add_filter( 'show_admin_bar', 'clfa_hide_admin_bar' );

/* After login, send alumni to the directory */
function clfa_login_redirect( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User && in_array( 'clf_alumni', (array) $user->roles, true ) ) {
		if ( ! empty( $requested ) && strpos( $requested, home_url() ) === 0 ) {
			return $requested;
		}
		return clfa_page_url( 'alumni' );
	}
	return $redirect_to;
}
add_filter( 'login_redirect', 'clfa_login_redirect', 10, 3 );

/* ============================================================
   Themed login page — [clf_alumni_login]
   ============================================================ */
function clfa_login_shortcode() {
	if ( clfa_is_member() ) {
		return '<div class="clfa-wrap clfa-login"><div class="clfa-card"><p class="clfa-kicker">' . esc_html__( 'Alumni Network', 'clf-alumni' ) . '</p><h2>' . esc_html__( 'You are signed in.', 'clf-alumni' ) . '</h2><p class="clfa-muted"><a class="clfa-btn" href="' . esc_url( clfa_page_url( 'alumni' ) ) . '">' . esc_html__( 'Go to the directory', 'clf-alumni' ) . '</a></p></div></div>';
	}

	$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : clfa_page_url( 'alumni' );
	if ( strpos( $redirect, home_url() ) !== 0 ) {
		$redirect = clfa_page_url( 'alumni' );
	}

	ob_start(); ?>
	<div class="clfa-wrap clfa-login">
	  <div class="clfa-card">
	    <p class="clfa-kicker"><?php esc_html_e( 'Charlotte Leadership Forum', 'clf-alumni' ); ?></p>
	    <h2><?php esc_html_e( 'Alumni sign in', 'clf-alumni' ); ?></h2>
	    <p class="clfa-muted"><?php esc_html_e( 'The Alumni Network is a private space for CLF alumni. Sign in with the account CLF created for you.', 'clf-alumni' ); ?></p>
	    <?php if ( isset( $_GET['login'] ) && 'failed' === $_GET['login'] ) : ?>
	      <p class="clfa-error"><?php esc_html_e( 'That email/password combination didn\'t work. Please try again or reset your password.', 'clf-alumni' ); ?></p>
	    <?php endif; ?>
	    <?php
	    wp_login_form( array(
			'redirect'       => $redirect,
			'label_username' => __( 'Email or username', 'clf-alumni' ),
			'label_password' => __( 'Password', 'clf-alumni' ),
			'label_remember' => __( 'Keep me signed in', 'clf-alumni' ),
			'label_log_in'   => __( 'Sign in', 'clf-alumni' ),
	    ) );
	    ?>
	    <p class="clfa-muted clfa-small"><a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>"><?php esc_html_e( 'Forgot your password?', 'clf-alumni' ); ?></a></p>
	  </div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'clf_alumni_login', 'clfa_login_shortcode' );

/* Send failed logins back to the themed page instead of wp-login.php */
function clfa_login_failed_redirect( $username ) {
	$ref = wp_get_referer();
	if ( $ref && strpos( $ref, 'alumni-login' ) !== false ) {
		wp_safe_redirect( add_query_arg( 'login', 'failed', clfa_page_url( 'alumni-login' ) ) );
		exit;
	}
}
add_action( 'wp_login_failed', 'clfa_login_failed_redirect' );
