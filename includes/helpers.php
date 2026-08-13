<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Membership checks
   ============================================================ */
function clfa_is_member( $user_id = 0 ) {
	$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	if ( user_can( $user, 'manage_options' ) ) {
		return true; // site admins always have access
	}
	if ( ! in_array( 'clf_alumni', (array) $user->roles, true ) ) {
		return false;
	}
	return ! get_user_meta( $user->ID, 'clfa_disabled', true );
}

/* ============================================================
   Profile field definitions (single source of truth)
   ============================================================ */
function clfa_profile_fields() {
	return array(
		'clfa_spouse'     => array( 'label' => __( 'Spouse', 'clf-alumni' ),                'type' => 'text' ),
		'clfa_class_year' => array( 'label' => __( 'CLF class year', 'clf-alumni' ),        'type' => 'text' ),
		'clfa_profession' => array( 'label' => __( 'Profession / role', 'clf-alumni' ),     'type' => 'text' ),
		'clfa_company'    => array( 'label' => __( 'Company / organization', 'clf-alumni' ),'type' => 'text' ),
		'clfa_industry'   => array( 'label' => __( 'Industry', 'clf-alumni' ),              'type' => 'select', 'options' => clfa_industries() ),
		'clfa_bio'        => array( 'label' => __( 'About you (bio)', 'clf-alumni' ),       'type' => 'textarea' ),
		'clfa_phone'      => array( 'label' => __( 'Phone', 'clf-alumni' ),                 'type' => 'text', 'private_toggle' => 'clfa_show_phone' ),
		'clfa_linkedin'   => array( 'label' => __( 'LinkedIn URL', 'clf-alumni' ),          'type' => 'url' ),
		'clfa_website'    => array( 'label' => __( 'Website', 'clf-alumni' ),               'type' => 'url' ),
	);
}

function clfa_industries() {
	$defaults = array( 'Banking & Finance', 'Real Estate', 'Healthcare', 'Law', 'Technology', 'Construction & Engineering', 'Education', 'Ministry & Nonprofit', 'Marketing & Media', 'Manufacturing', 'Consulting', 'Entrepreneurship', 'Other' );
	return apply_filters( 'clfa_industries', $defaults );
}

/* ============================================================
   Page URLs
   ============================================================ */
function clfa_page_url( $slug ) {
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page->ID ) : home_url( '/' . $slug . '/' );
}

/* ============================================================
   Private photo storage — files live outside the Media Library
   in uploads/clf-alumni-private/ (blocked from direct access)
   and are streamed only to signed-in members.
   ============================================================ */
function clfa_private_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'clf-alumni-private';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// Block direct web access (Apache). The photos endpoint is the only door.
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php // silence\n" ); // phpcs:ignore
	}
	return $dir;
}

function clfa_store_private_photo( $user_id ) {
	if ( empty( $_FILES['clfa_photo']['tmp_name'] ) ) {
		return false;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$check = wp_check_filetype_and_ext(
		$_FILES['clfa_photo']['tmp_name'],
		sanitize_file_name( $_FILES['clfa_photo']['name'] ),
		array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' )
	);
	if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
		return false;
	}

	$dir      = clfa_private_dir();
	$filename = 'member-' . $user_id . '-' . wp_generate_password( 12, false ) . '.' . $check['ext'];
	$target   = $dir . '/' . $filename;
	if ( ! @move_uploaded_file( $_FILES['clfa_photo']['tmp_name'], $target ) ) { // phpcs:ignore
		return false;
	}

	// Downscale large images to keep the directory fast.
	$editor = wp_get_image_editor( $target );
	if ( ! is_wp_error( $editor ) ) {
		$editor->resize( 800, 800, false );
		$editor->save( $target );
	}

	// Remove the previous photo file.
	$old = get_user_meta( $user_id, 'clfa_photo_file', true );
	if ( $old && basename( $old ) === $old && file_exists( $dir . '/' . $old ) ) {
		@unlink( $dir . '/' . $old ); // phpcs:ignore
	}
	// Clean up any legacy Media Library photo from earlier versions.
	$legacy = (int) get_user_meta( $user_id, 'clfa_photo_id', true );
	if ( $legacy ) {
		wp_delete_attachment( $legacy, true );
		delete_user_meta( $user_id, 'clfa_photo_id' );
	}

	update_user_meta( $user_id, 'clfa_photo_file', $filename );
	return true;
}

/* Authenticated photo endpoint: /?clfa_photo=<user_id> */
function clfa_serve_private_photo() {
	if ( ! isset( $_GET['clfa_photo'] ) ) {
		return;
	}
	if ( ! clfa_is_member() ) {
		status_header( 403 );
		exit;
	}
	$member_id = (int) $_GET['clfa_photo'];
	$file      = get_user_meta( $member_id, 'clfa_photo_file', true );
	if ( ! $file || basename( $file ) !== $file ) {
		status_header( 404 );
		exit;
	}
	$path = clfa_private_dir() . '/' . $file;
	if ( ! file_exists( $path ) ) {
		status_header( 404 );
		exit;
	}
	$type = wp_check_filetype( $path );
	nocache_headers();
	header( 'Content-Type: ' . ( $type['type'] ?: 'image/jpeg' ) );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'X-Robots-Tag: noindex' );
	readfile( $path ); // phpcs:ignore
	exit;
}
add_action( 'init', 'clfa_serve_private_photo' );

/* ============================================================
   Member photo markup (falls back to initials block)
   ============================================================ */
function clfa_member_photo( $user_id, $size = 'medium' ) {
	$file = get_user_meta( $user_id, 'clfa_photo_file', true );
	if ( $file ) {
		$src = add_query_arg( 'clfa_photo', (int) $user_id, home_url( '/' ) );
		return '<img class="clfa-photo" src="' . esc_url( $src ) . '" alt="" loading="lazy">';
	}
	$user     = get_userdata( $user_id );
	$initials = '';
	if ( $user ) {
		$initials = strtoupper( mb_substr( $user->first_name ?: $user->display_name, 0, 1 ) . mb_substr( $user->last_name, 0, 1 ) );
	}
	return '<span class="clfa-photo clfa-initials">' . esc_html( $initials ?: '—' ) . '</span>';
}

/* ============================================================
   Branded invite email (password setup link)
   ============================================================ */
function clfa_send_invite( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}
	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return false;
	}
	$url  = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
	$name = $user->first_name ?: $user->display_name;

	$subject = __( 'Welcome to the CLF Alumni Network', 'clf-alumni' );
	$body    =
		'<div style="background:#eee9df;padding:40px 20px;font-family:Georgia,serif;color:#172638;">' .
		'<div style="max-width:560px;margin:0 auto;background:#172638;color:#eee9df;padding:40px;">' .
		'<p style="font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:#d4a492;margin:0 0 20px;">Charlotte Leadership Forum</p>' .
		'<h1 style="font-size:30px;margin:0 0 18px;font-weight:600;">' . esc_html( sprintf( __( 'Welcome, %s.', 'clf-alumni' ), $name ) ) . '</h1>' .
		'<p style="line-height:1.7;color:#c6c8c3;">' . esc_html__( 'You now have access to the CLF Alumni Network — a private space for CLF alumni to reconnect, network, and stay involved. Set your password to get started, then complete your profile so classmates old and new can find you.', 'clf-alumni' ) . '</p>' .
		'<p style="margin:28px 0;"><a href="' . esc_url( $url ) . '" style="background:#a94d3b;color:#ffffff;padding:14px 22px;text-decoration:none;font-size:13px;letter-spacing:.08em;text-transform:uppercase;">' . esc_html__( 'Set your password', 'clf-alumni' ) . '</a></p>' .
		'<p style="font-size:12px;color:#8a929b;">' . esc_html__( 'After setting your password, sign in and visit your profile from the Alumni Network page.', 'clf-alumni' ) . '</p>' .
		'</div></div>';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	return wp_mail( $user->user_email, $subject, $body, $headers );
}
