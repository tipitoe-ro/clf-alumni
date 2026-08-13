<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   SMTP settings (Google Workspace) — CLF Alumni → Email Settings
   ============================================================ */
function clfa_email_settings() {
	return wp_parse_args( get_option( 'clfa_email', array() ), array(
		'enabled'    => 0,
		'host'       => 'smtp.gmail.com',
		'port'       => 587,
		'encryption' => 'tls',
		'username'   => '',
		'password'   => '',
		'from_email' => '',
		'from_name'  => 'Charlotte Leadership Forum',
	) );
}

function clfa_email_settings_menu() {
	add_submenu_page( 'clf-alumni', __( 'Email Settings', 'clf-alumni' ), __( 'Email Settings', 'clf-alumni' ), 'manage_options', 'clfa-email', 'clfa_email_settings_page' );
}
add_action( 'admin_menu', 'clfa_email_settings_menu', 20 );

function clfa_email_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = '';
	if ( isset( $_POST['clfa_email_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clfa_email_nonce'] ), 'clfa_email_save' ) ) {
		$old = clfa_email_settings();
		$new = array(
			'enabled'    => empty( $_POST['enabled'] ) ? 0 : 1,
			'host'       => sanitize_text_field( wp_unslash( $_POST['host'] ?? 'smtp.gmail.com' ) ),
			'port'       => (int) ( $_POST['port'] ?? 587 ),
			'encryption' => in_array( $_POST['encryption'] ?? 'tls', array( 'tls', 'ssl', 'none' ), true ) ? sanitize_key( $_POST['encryption'] ) : 'tls',
			'username'   => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
			'password'   => '' !== ( $_POST['password'] ?? '' ) ? trim( (string) wp_unslash( $_POST['password'] ) ) : $old['password'],
			'from_email' => sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) ),
			'from_name'  => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
		);
		update_option( 'clfa_email', $new, false );
		$notice = __( 'Email settings saved.', 'clf-alumni' );
		if ( isset( $_POST['send_test'] ) && $_POST['send_test'] ) {
			$to = wp_get_current_user()->user_email;
			$ok = clfa_send_branded( $to, __( 'CLF test email', 'clf-alumni' ), '<p>' . esc_html__( 'This is a test email from the CLF Alumni plugin. If you can read this, delivery works.', 'clf-alumni' ) . '</p>' );
			$notice .= ' ' . ( $ok ? sprintf( __( 'Test email sent to %s.', 'clf-alumni' ), $to ) : __( 'Test email FAILED — check the settings.', 'clf-alumni' ) );
		}
	}
	$s = clfa_email_settings();
	?>
	<div class="wrap">
	  <h1><?php esc_html_e( 'Alumni Email Settings (Google Workspace SMTP)', 'clf-alumni' ); ?></h1>
	  <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
	  <p><?php esc_html_e( 'Send invitations and reminders through CLF\'s Google Workspace account so they come from a real CLF address and reach inboxes.', 'clf-alumni' ); ?></p>
	  <ol>
	    <li><?php esc_html_e( 'In the Google account (e.g. info@charlotteforum.org): turn on 2-Step Verification, then create an App Password (Google Account → Security → App passwords).', 'clf-alumni' ); ?></li>
	    <li><?php esc_html_e( 'Enter that address as Username/From and the 16-character app password below.', 'clf-alumni' ); ?></li>
	  </ol>
	  <form method="post">
	    <?php wp_nonce_field( 'clfa_email_save', 'clfa_email_nonce' ); ?>
	    <table class="form-table">
	      <tr><th><?php esc_html_e( 'Use SMTP for alumni emails', 'clf-alumni' ); ?></th><td><label><input type="checkbox" name="enabled" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Enabled (otherwise the server\'s default mail is used)', 'clf-alumni' ); ?></label></td></tr>
	      <tr><th><?php esc_html_e( 'SMTP host', 'clf-alumni' ); ?></th><td><input type="text" name="host" class="regular-text" value="<?php echo esc_attr( $s['host'] ); ?>"></td></tr>
	      <tr><th><?php esc_html_e( 'Port', 'clf-alumni' ); ?></th><td><input type="number" name="port" value="<?php echo esc_attr( $s['port'] ); ?>"> <span class="description">587 (TLS) / 465 (SSL)</span></td></tr>
	      <tr><th><?php esc_html_e( 'Encryption', 'clf-alumni' ); ?></th><td><select name="encryption">
	        <option value="tls" <?php selected( $s['encryption'], 'tls' ); ?>>TLS</option>
	        <option value="ssl" <?php selected( $s['encryption'], 'ssl' ); ?>>SSL</option>
	        <option value="none" <?php selected( $s['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'clf-alumni' ); ?></option>
	      </select></td></tr>
	      <tr><th><?php esc_html_e( 'Username (Workspace email)', 'clf-alumni' ); ?></th><td><input type="text" name="username" class="regular-text" value="<?php echo esc_attr( $s['username'] ); ?>" placeholder="info@charlotteforum.org" autocomplete="off"></td></tr>
	      <tr><th><?php esc_html_e( 'App password', 'clf-alumni' ); ?></th><td>
	        <?php if ( defined( 'CLFA_SMTP_PASSWORD' ) ) : ?>
	          <em><?php esc_html_e( 'Set via CLFA_SMTP_PASSWORD in wp-config.php (recommended) — the field below is ignored.', 'clf-alumni' ); ?></em><br>
	        <?php endif; ?>
	        <input type="password" name="password" class="regular-text" value="" placeholder="<?php echo $s['password'] ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'clf-alumni' ) : ''; ?>" autocomplete="new-password">
	        <p class="description"><?php esc_html_e( 'Most secure option: add define( \'CLFA_SMTP_PASSWORD\', \'your app password\' ); to wp-config.php instead of saving it here.', 'clf-alumni' ); ?></p>
	      </td></tr>
	      <tr><th><?php esc_html_e( 'From email', 'clf-alumni' ); ?></th><td><input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="info@charlotteforum.org"></td></tr>
	      <tr><th><?php esc_html_e( 'From name', 'clf-alumni' ); ?></th><td><input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
	      <tr><th></th><td><label><input type="checkbox" name="send_test" value="1"> <?php esc_html_e( 'Send a test email to me after saving', 'clf-alumni' ); ?></label></td></tr>
	    </table>
	    <p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'clf-alumni' ); ?></button></p>
	  </form>
	</div>
	<?php
}

/* ---- Apply SMTP config only for emails sent by this plugin ---- */
function clfa_phpmailer_init( $phpmailer ) {
	if ( ! apply_filters( 'clfa_sending', false ) ) {
		return;
	}
	$s = clfa_email_settings();
	// Prefer a wp-config.php constant so the password isn't stored in the DB:
	// define( 'CLFA_SMTP_PASSWORD', '...' );
	$password = defined( 'CLFA_SMTP_PASSWORD' ) ? CLFA_SMTP_PASSWORD : $s['password'];
	if ( ! $s['enabled'] || ! $s['username'] || ! $password ) {
		return;
	}
	$phpmailer->isSMTP();
	$phpmailer->Host       = $s['host'];
	$phpmailer->Port       = $s['port'];
	$phpmailer->SMTPAuth   = true;
	$phpmailer->Username   = $s['username'];
	$phpmailer->Password   = $password;
	$phpmailer->SMTPSecure = 'none' === $s['encryption'] ? '' : $s['encryption'];
	if ( $s['from_email'] ) {
		$phpmailer->setFrom( $s['from_email'], $s['from_name'] ?: 'CLF', false );
	}
}
add_action( 'phpmailer_init', 'clfa_phpmailer_init' );

/* ============================================================
   Branded HTML email — Bold Conviction shell around $body_html
   ============================================================ */
function clfa_send_branded( $to, $subject, $body_html, $cta_label = '', $cta_url = '', $footer_extra = '' ) {
	$s    = clfa_email_settings();
	$cta  = ( $cta_label && $cta_url )
		? '<p style="margin:30px 0;"><a href="' . esc_url( $cta_url ) . '" style="background:#a94d3b;color:#ffffff;padding:15px 24px;text-decoration:none;font-size:13px;letter-spacing:.08em;text-transform:uppercase;font-weight:bold;">' . esc_html( $cta_label ) . '</a></p>'
		: '';
	$html =
		'<div style="background:#eee9df;padding:40px 16px;font-family:Georgia,\'Times New Roman\',serif;color:#172638;">' .
		'<div style="max-width:600px;margin:0 auto;">' .
		'<div style="background:#172638;color:#eee9df;padding:44px 40px;">' .
		'<p style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#d4a492;margin:0 0 22px;">Charlotte Leadership Forum — Alumni</p>' .
		$body_html .
		$cta .
		'</div>' .
		'<p style="font-size:11px;color:#8a877e;text-align:center;margin-top:18px;">' . ( $footer_extra ? $footer_extra . '<br>' : '' ) . esc_html( 'Charlotte Leadership Forum · You\'re receiving this as a member of the CLF Alumni Network.' ) . '</p>' .
		'</div></div>';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( $s['from_email'] ) {
		$headers[] = 'From: ' . ( $s['from_name'] ?: 'CLF' ) . ' <' . $s['from_email'] . '>';
	}
	$flag = function () { return true; };
	add_filter( 'clfa_sending', $flag );
	$ok = wp_mail( $to, $subject, $html, $headers );
	remove_filter( 'clfa_sending', $flag );
	return $ok;
}
