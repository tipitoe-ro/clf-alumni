<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'clf-alumni', 'Mailchimp', 'Mailchimp', 'manage_options', 'clfa-mailchimp', 'clfa_mc_admin_page' );
}, 21 ); // After the parent menu (10) and Email Settings (20): correct route and menu order.

function clfa_mc_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = '';
	$error = '';
	if ( isset( $_POST['clfa_mc_nonce'] ) && check_admin_referer( 'clfa_mc_admin', 'clfa_mc_nonce' ) ) {
		$action = sanitize_key( $_POST['mc_action'] ?? '' );
		if ( 'settings' === $action ) {
			$old = clfa_mc_settings();
			$audience = sanitize_text_field( wp_unslash( $_POST['audience'] ?? '' ) );
			$auto = ! empty( $_POST['auto_enroll'] );
			if ( $audience && ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $audience ) ) {
				$error = 'Enter the Mailchimp audience ID, not its name or URL.';
			} elseif ( $auto && empty( $_POST['auto_consent'] ) ) {
				$error = 'Confirm existing consent before enabling automatic enrollment.';
			} elseif ( $old['audience'] && $audience !== $old['audience'] && get_users( array(
				'fields' => 'ID', 'number' => 1, 'meta_key' => 'clfa_mc_identity', 'meta_compare' => 'EXISTS',
			) ) ) {
				$error = 'This audience has synced contacts. Audience changes require a migration so existing subscriptions and opt-outs are not lost.';
			}
			if ( ! $error && ! defined( 'CLFA_MAILCHIMP_API_KEY' ) && ! empty( $_POST['api_key'] ) ) {
				$result = clfa_mc_store_key( trim( wp_unslash( $_POST['api_key'] ) ) );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				}
			}
			if ( ! $error && ! empty( $_POST['enabled'] ) && ( ! $audience || ! clfa_mc_key() ) ) {
				$error = 'Save an API key and audience ID before enabling the integration.';
			}
			if ( ! $error ) {
				update_option( 'clfa_mailchimp', array(
					'enabled' => ! empty( $_POST['enabled'] ), 'audience' => $audience,
					'double_optin' => ! empty( $_POST['double_optin'] ), 'auto_enroll' => $auto,
				), false );
				$notice = 'Settings saved. No existing users were enrolled.';
			}
} elseif ( 'rotate_webhook' === $action ) {
update_option( 'clfa_mc_webhook_token', bin2hex( random_bytes( 32 ) ), false );
$notice = 'Webhook URL rotated. Replace the callback URL in Mailchimp now; the old URL no longer works.';
} elseif ( 'test' === $action ) {
			$s = clfa_mc_settings();
			$result = $s['audience'] ? clfa_mc_request( 'GET', 'lists/' . rawurlencode( $s['audience'] ) ) : new WP_Error( 'audience', 'Save an audience ID first.' );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$notice = 'Connection works. Audience: ' . sanitize_text_field( $result['name'] ?? $s['audience'] ) . '. No contacts were changed.';
			}
		} elseif ( in_array( $action, array( 'enroll', 'sync', 'unsubscribe' ), true ) ) {
			$ids = array_unique( array_map( 'absint', (array) ( $_POST['mc_users'] ?? array() ) ) );
			if ( ! $ids || count( $ids ) > 50 ) {
				$error = 'Select between 1 and 50 contacts.';
			} elseif ( 'enroll' === $action && empty( $_POST['enroll_consent'] ) ) {
				$error = 'Confirm that the selected contacts consented to newsletters and announcements.';
			} elseif ( ! clfa_mc_ready() ) {
				$error = 'Connect and enable Mailchimp before enrolling or syncing contacts.';
			} else {
				$count = 0;
				foreach ( $ids as $id ) {
					$user = get_userdata( $id );
					if ( ! $user || ( ! in_array( 'clf_alumni', $user->roles, true ) && ! clfa_leader_years( $id ) ) ) {
						continue;
					}
					if ( 'enroll' === $action ) {
						if ( get_user_meta( $id, 'clfa_disabled', true ) ) {
							continue;
						}
						clfa_mc_consent( $id, true, 'admin_explicit_enrollment' );
					} elseif ( 'unsubscribe' === $action ) {
						clfa_mc_consent( $id, false, 'admin_unsubscribe' );
						clfa_mc_queue( $id );
					} else {
						clfa_mc_queue( $id );
					}
					$count++;
				}
				$notice = $count . ' contact(s) queued. Sync selected never grants new consent. Processing runs in batches through WP-Cron; refresh to see results.';
			}
		}
	}
	$s = clfa_mc_settings();
	$search = sanitize_text_field( wp_unslash( $_GET['mc_search'] ?? '' ) );
	$year = sanitize_text_field( wp_unslash( $_GET['mc_year'] ?? '' ) );
	$leaders = ! empty( $_GET['mc_leaders'] );
	$page = max( 1, absint( $_GET['mc_page'] ?? 1 ) );
	$args = array( 'number' => 50, 'offset' => ( $page - 1 ) * 50, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => true );
	if ( $leaders ) {
		$args['meta_query'] = clfa_leader_query( $year );
	} else {
		$args['role'] = 'clf_alumni';
		if ( $year ) {
			$args['meta_query'] = array( array( 'key' => 'clfa_class_year', 'value' => $year ) );
		}
	}
	if ( $search ) {
		$args['search'] = '*' . $search . '*';
		$args['search_columns'] = array( 'display_name', 'user_email' );
	}
	$query = new WP_User_Query( $args );
	?>
	<div class="wrap">
	<h1>CLF — Mailchimp</h1>
	<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
	<?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
	<p>Mailchimp sends announcements and the monthly newsletter. Event invitations and RSVP emails are unchanged.</p>
	<form method="post">
		<?php wp_nonce_field( 'clfa_mc_admin', 'clfa_mc_nonce' ); ?>
		<input type="hidden" name="mc_action" value="settings">
		<table class="form-table">
		<tr><th><label for="mc-key">Mailchimp API key</label></th><td>
			<input type="password" id="mc-key" name="api_key" autocomplete="new-password" class="regular-text" value="" <?php disabled( defined( 'CLFA_MAILCHIMP_API_KEY' ) ); ?>>
			<p class="description"><?php echo clfa_mc_key() ? 'A key is configured. Leave blank to keep it; it is never displayed.' : 'Not connected yet.'; ?> Store the key here on your WordPress server, not in chat. A CLFA_MAILCHIMP_API_KEY constant in wp-config.php takes priority.</p>
		</td></tr>
		<tr><th><label for="mc-audience">Audience ID</label></th><td><input id="mc-audience" name="audience" class="regular-text" value="<?php echo esc_attr( $s['audience'] ); ?>"><p class="description">Mailchimp → Audience → Settings → Audience name and defaults. Keep the standard FNAME and LNAME merge fields.</p></td></tr>
		<tr><th>Connection</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Enable profile opt-in and syncing</label><p class="description">Turning this off pauses all syncing, including opt-outs. It does not remove existing Mailchimp subscriptions.</p></td></tr>
		<tr><th>New subscribers</th><td><label><input type="checkbox" name="double_optin" value="1" <?php checked( $s['double_optin'] ); ?>> Require email confirmation (recommended)</label><p class="description">Mailchimp sends its confirmation email for new contacts. Existing subscribers keep their status. Unsubscribed or cleaned contacts are never automatically resubscribed.</p></td></tr>
		<tr><th>Automatic enrollment</th><td>
			<label><input type="checkbox" name="auto_enroll" value="1" <?php checked( $s['auto_enroll'] ); ?>> Enroll new CLF members created through Add Member or Import CSV</label><br>
			<label><input type="checkbox" name="auto_consent" value="1"> I confirm these new members have consented to CLF announcements and newsletters.</label>
			<p class="description">Does not enroll existing members. Leave off if consent should be collected on profiles instead.</p>
		</td></tr>
		</table>
		<p><button class="button button-primary">Save settings</button></p>
	</form>
	<form method="post"><?php wp_nonce_field( 'clfa_mc_admin', 'clfa_mc_nonce' ); ?><button class="button" name="mc_action" value="test">Test saved connection</button></form>
<hr><h2>Immediate subscription updates</h2>
<p>In Mailchimp, add an audience webhook for subscribes, unsubscribes and cleaned addresses, from subscribers, administrators and the API. Copy this private callback URL. Use HTTPS and do not share it or include it in logs.</p>
<p><label for="mc-webhook">Webhook callback URL</label><br>
<input id="mc-webhook" class="large-text" readonly value="<?php echo esc_attr( add_query_arg( 'key', clfa_mc_webhook_token(), rest_url( 'clf-alumni/v1/mailchimp' ) ) ); ?>"></p>
<p>Updates are verified against Mailchimp before changing profiles. Confirmations never override a website opt-out. See MAILCHIMP.md for setup and verification.</p>
<form method="post"><?php wp_nonce_field( 'clfa_mc_admin', 'clfa_mc_nonce' ); ?><button class="button" name="mc_action" value="rotate_webhook" onclick="return confirm('Invalidate the old webhook URL? You must update Mailchimp with the new URL immediately.')">Rotate webhook URL</button></form>
<h3>Webhook health</h3>
<?php
$last_accepted = (int) get_option( 'clfa_mc_webhook_last_accepted', 0 );
$verification = get_option( 'clfa_mc_webhook_verification', array() );
$failures = max( 0, (int) ( $verification['failures'] ?? 0 ) );
$last_failure = (int) ( $verification['last_failure'] ?? 0 );
?>
<table class="widefat striped">
<tbody>
<tr><th scope="row">Last accepted delivery (UTC)</th><td><?php echo esc_html( $last_accepted ? gmdate( 'Y-m-d H:i:s', $last_accepted ) : 'None recorded yet' ); ?></td></tr>
<tr><th scope="row">Mailchimp API verification failures</th><td><?php echo esc_html( $failures ); ?> total since health tracking began</td></tr>
<tr><th scope="row">Last verification failure (UTC)</th><td><?php echo esc_html( $last_failure ? gmdate( 'Y-m-d H:i:s', $last_failure ) : 'None recorded yet' ); ?></td></tr>
</tbody>
</table>
<?php if ( ! clfa_mc_ready() ) : ?>
<p><strong>Updates are paused or unconfigured.</strong> Enable the integration with a saved API key and audience to receive subscription updates.</p>
<?php endif; ?>
<?php if ( $failures ) : ?>
<p><strong>Verification failures have occurred.</strong> Check the last failure time and use <strong>Test saved connection</strong>. A later accepted delivery does not prove all failed contacts recovered; check affected contacts and use <strong>Sync selected / retry</strong> after fixing the connection.</p>
<?php endif; ?>
<p class="description">Accepted means a valid POST returned success, including safely ignored events; it does not necessarily mean a profile changed. GET URL checks and rejected or retryable requests do not count. No recent delivery may simply mean no subscription changes. Tracking starts with this update and keeps its history when settings or the callback URL change.</p>
<p class="description">The failure total counts failed contact lookups through the Mailchimp API, including retries, not unique contacts. It excludes rejected callback keys, malformed requests, paused syncing and busy locks. Only timestamps and an aggregate count are stored for health tracking, never callback keys, emails or payloads. If expected updates are missing, check Mailchimp webhook settings and hosting/firewall access; see MAILCHIMP.md for troubleshooting.</p>
	<hr><h2>Contacts and sync results</h2>
<p>Member consent is stored separately from Mailchimp's subscription status. A configured webhook reflects Mailchimp unsubscribes on the next profile load after delivery; routine sync also respects them. Remote confirmations do not override a website opt-out. Use Mailchimp's signup form and explicitly opt in on the website to restore both preferences.</p>
	<form method="get">
		<input type="hidden" name="page" value="clfa-mailchimp">
		<label>Name or email <input name="mc_search" value="<?php echo esc_attr( $search ); ?>"></label>
		<label>Class year <input name="mc_year" size="8" value="<?php echo esc_attr( $year ); ?>"></label>
		<label><input type="checkbox" name="mc_leaders" value="1" <?php checked( $leaders ); ?>> Leaders only (year means class led)</label>
		<button class="button">Filter</button>
	</form>
	<form method="post">
		<?php wp_nonce_field( 'clfa_mc_admin', 'clfa_mc_nonce' ); ?>
		<table class="widefat striped" style="margin-top:15px">
		<thead><tr><th><input type="checkbox" aria-label="Select all contacts on this page" onclick="this.form.querySelectorAll('input[name=&quot;mc_users[]&quot;]').forEach(el => el.checked = this.checked)"></th><th>Contact</th><th>Website consent</th><th>Last sync result</th></tr></thead><tbody>
		<?php foreach ( $query->get_results() as $user ) : ?>
		<tr><td><input type="checkbox" name="mc_users[]" value="<?php echo esc_attr( $user->ID ); ?>" aria-label="<?php echo esc_attr( 'Select ' . $user->display_name ); ?>"></td>
		<td><?php echo esc_html( $user->display_name ); ?><br><?php echo esc_html( $user->user_email ); ?></td>
		<td><?php echo get_user_meta( $user->ID, 'clfa_mc_consent', true ) ? 'Yes' : 'No'; ?><?php if ( get_user_meta( $user->ID, 'clfa_disabled', true ) ) { echo ' (account inactive)'; } ?></td>
		<td><?php echo esc_html( get_user_meta( $user->ID, 'clfa_mc_result', true ) ?: 'Not synced' ); ?><br><small><?php echo esc_html( get_user_meta( $user->ID, 'clfa_mc_checked', true ) ); ?></small></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $query->get_results() ) : ?><tr><td colspan="4">No contacts match these filters.</td></tr><?php endif; ?>
		</tbody></table>
		<p><label><input type="checkbox" name="enroll_consent" value="1"> The selected contacts have already consented to announcements and newsletters.</label></p>
		<p><button class="button button-primary" name="mc_action" value="enroll">Enroll selected</button>
		<button class="button" name="mc_action" value="sync">Sync selected / retry</button>
		<button class="button" name="mc_action" value="unsubscribe" onclick="return confirm('Unsubscribe the selected contacts from newsletters? Event emails are unaffected.')">Unsubscribe selected</button></p>
	</form>
	<p><?php echo esc_html( $query->get_total() . ' contacts · Page ' . $page ); ?>
	<?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'mc_page', $page - 1 ) ); ?>">Previous</a><?php endif; ?>
	<?php if ( $query->get_total() > $page * 50 ) : ?><a href="<?php echo esc_url( add_query_arg( 'mc_page', $page + 1 ) ); ?>">Next</a><?php endif; ?>
	</p>
	<p>Sync runs in batches of five, approximately every five minutes when WP-Cron runs. Configure server cron if the site has little traffic. No campaigns are sent by this plugin.</p>
	</div>
	<?php
}
