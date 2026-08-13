<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Email sender settings — CLF Alumni → Email Settings
   Delivery (SMTP) is handled site-wide by the Gravity SMTP
   plugin; here we only control the From name/address on
   alumni emails and offer a test send.
   ============================================================ */
function clfa_email_settings() {
	return wp_parse_args( get_option( 'clfa_email', array() ), array(
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
		$new = array(
			'from_email' => sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) ),
			'from_name'  => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
		);
		update_option( 'clfa_email', $new, false );
		$notice = __( 'Email settings saved.', 'clf-alumni' );
		if ( isset( $_POST['send_test'] ) && $_POST['send_test'] ) {
			$to = wp_get_current_user()->user_email;
			$ok = clfa_send_branded( $to, __( 'CLF test email', 'clf-alumni' ), '<p>' . esc_html__( 'This is a test email from the CLF Alumni plugin. If you can read this, delivery works.', 'clf-alumni' ) . '</p>' );
			$notice .= ' ' . ( $ok ? sprintf( __( 'Test email sent to %s.', 'clf-alumni' ), $to ) : __( 'Test email FAILED — check your Gravity SMTP configuration.', 'clf-alumni' ) );
		}
	}
	$s = clfa_email_settings();
	?>
	<div class="wrap">
	  <h1><?php esc_html_e( 'Alumni Email Settings', 'clf-alumni' ); ?></h1>
	  <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
	  <p><?php esc_html_e( 'Email delivery is handled by the Gravity SMTP plugin (Gravity SMTP → Settings). Below you can set the sender name and address used on alumni emails, and send yourself a test.', 'clf-alumni' ); ?></p>
	  <form method="post">
	    <?php wp_nonce_field( 'clfa_email_save', 'clfa_email_nonce' ); ?>
	    <table class="form-table">
	      <tr><th><?php esc_html_e( 'From email', 'clf-alumni' ); ?></th><td><input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="info@charlotteforum.org"><p class="description"><?php esc_html_e( 'Leave blank to use the sender configured in Gravity SMTP.', 'clf-alumni' ); ?></p></td></tr>
	      <tr><th><?php esc_html_e( 'From name', 'clf-alumni' ); ?></th><td><input type="text" name="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
	      <tr><th></th><td><label><input type="checkbox" name="send_test" value="1"> <?php esc_html_e( 'Send a test email to me after saving', 'clf-alumni' ); ?></label></td></tr>
	    </table>
	    <p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'clf-alumni' ); ?></button></p>
	  </form>
	</div>
	<?php
}

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
	return wp_mail( $to, $subject, $html, $headers );
}
