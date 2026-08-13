<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Admin menu
   ============================================================ */
function clfa_admin_menu() {
	add_menu_page( 'CLF Alumni', 'CLF Alumni', 'manage_options', 'clf-alumni', 'clfa_admin_members_page', 'dashicons-groups', 26 );
	add_submenu_page( 'clf-alumni', __( 'Members', 'clf-alumni' ), __( 'Members', 'clf-alumni' ), 'manage_options', 'clf-alumni', 'clfa_admin_members_page' );
	add_submenu_page( 'clf-alumni', __( 'Add Member', 'clf-alumni' ), __( 'Add Member', 'clf-alumni' ), 'manage_options', 'clfa-add', 'clfa_admin_add_page' );
	add_submenu_page( 'clf-alumni', __( 'Import CSV', 'clf-alumni' ), __( 'Import CSV', 'clf-alumni' ), 'manage_options', 'clfa-import', 'clfa_admin_import_page' );
}
add_action( 'admin_menu', 'clfa_admin_menu' );

/* ============================================================
   Create one member (shared by Add + Import)
   Returns user ID, 'exists', or WP_Error.
   ============================================================ */
function clfa_create_member( $email, $first, $last, $spouse = '', $class_year = '', $send_invite = true ) {
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'bad_email', sprintf( __( 'Invalid email: %s', 'clf-alumni' ), esc_html( $email ) ) );
	}
	if ( email_exists( $email ) ) {
		return 'exists';
	}
	$user_id = wp_insert_user( array(
		'user_login'   => $email,
		'user_email'   => $email,
		'user_pass'    => wp_generate_password( 24 ),
		'first_name'   => sanitize_text_field( $first ),
		'last_name'    => sanitize_text_field( $last ),
		'display_name' => trim( sanitize_text_field( $first ) . ' ' . sanitize_text_field( $last ) ),
		'role'         => 'clf_alumni',
	) );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	if ( $spouse ) {
		update_user_meta( $user_id, 'clfa_spouse', sanitize_text_field( $spouse ) );
	}
	if ( $class_year ) {
		update_user_meta( $user_id, 'clfa_class_year', sanitize_text_field( $class_year ) );
	}
	if ( $send_invite ) {
		clfa_send_invite( $user_id );
	}
	return $user_id;
}

/* ============================================================
   Members list page (search, deactivate/reactivate, resend invite)
   ============================================================ */
function clfa_admin_members_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';
	if ( isset( $_GET['clfa_action'], $_GET['user'], $_GET['_wpnonce'] ) ) {
		$uid    = (int) $_GET['user'];
		$action = sanitize_key( $_GET['clfa_action'] );
		if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'clfa_member_' . $action . '_' . $uid ) && get_userdata( $uid ) ) {
			if ( 'disable' === $action ) {
				update_user_meta( $uid, 'clfa_disabled', 1 );
				$notice = __( 'Member deactivated.', 'clf-alumni' );
			} elseif ( 'enable' === $action ) {
				delete_user_meta( $uid, 'clfa_disabled' );
				$notice = __( 'Member reactivated.', 'clf-alumni' );
			} elseif ( 'invite' === $action ) {
				$notice = clfa_send_invite( $uid ) ? __( 'Invite email sent.', 'clf-alumni' ) : __( 'Could not send the invite email — check the site\'s email setup.', 'clf-alumni' );
			}
		}
	}

	$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$args    = array( 'role' => 'clf_alumni', 'number' => 1000, 'orderby' => 'display_name' );
	if ( $search ) {
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = array( 'display_name', 'user_email' );
	}
	$members = get_users( $args );
	?>
	<div class="wrap">
	  <h1><?php esc_html_e( 'CLF Alumni — Members', 'clf-alumni' ); ?>
	    <a href="<?php echo esc_url( admin_url( 'admin.php?page=clfa-add' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Member', 'clf-alumni' ); ?></a>
	    <a href="<?php echo esc_url( admin_url( 'admin.php?page=clfa-import' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Import CSV', 'clf-alumni' ); ?></a>
	  </h1>
	  <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

	  <form method="get"><input type="hidden" name="page" value="clf-alumni">
	    <p class="search-box"><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search members…', 'clf-alumni' ); ?>">
	    <button class="button"><?php esc_html_e( 'Search', 'clf-alumni' ); ?></button></p>
	  </form>

	  <table class="widefat striped">
	    <thead><tr>
	      <th><?php esc_html_e( 'Name', 'clf-alumni' ); ?></th>
	      <th><?php esc_html_e( 'Email', 'clf-alumni' ); ?></th>
	      <th><?php esc_html_e( 'Class', 'clf-alumni' ); ?></th>
	      <th><?php esc_html_e( 'Status', 'clf-alumni' ); ?></th>
	      <th><?php esc_html_e( 'Actions', 'clf-alumni' ); ?></th>
	    </tr></thead>
	    <tbody>
	    <?php if ( empty( $members ) ) : ?>
	      <tr><td colspan="5"><?php esc_html_e( 'No members yet. Add one or import your alumni CSV.', 'clf-alumni' ); ?></td></tr>
	    <?php endif; ?>
	    <?php foreach ( $members as $m ) :
			$disabled = get_user_meta( $m->ID, 'clfa_disabled', true );
			$base     = admin_url( 'admin.php?page=clf-alumni&user=' . $m->ID ); ?>
	      <tr>
	        <td><strong><?php echo esc_html( $m->display_name ); ?></strong></td>
	        <td><?php echo esc_html( $m->user_email ); ?></td>
	        <td><?php echo esc_html( get_user_meta( $m->ID, 'clfa_class_year', true ) ); ?></td>
	        <td><?php echo $disabled ? '<span style="color:#a94d3b;">' . esc_html__( 'Inactive', 'clf-alumni' ) . '</span>' : esc_html__( 'Active', 'clf-alumni' ); ?></td>
	        <td>
	          <?php if ( $disabled ) : ?>
	            <a href="<?php echo esc_url( wp_nonce_url( $base . '&clfa_action=enable', 'clfa_member_enable_' . $m->ID ) ); ?>"><?php esc_html_e( 'Reactivate', 'clf-alumni' ); ?></a>
	          <?php else : ?>
	            <a href="<?php echo esc_url( wp_nonce_url( $base . '&clfa_action=disable', 'clfa_member_disable_' . $m->ID ) ); ?>"><?php esc_html_e( 'Deactivate', 'clf-alumni' ); ?></a>
	          <?php endif; ?>
	          |
	          <a href="<?php echo esc_url( wp_nonce_url( $base . '&clfa_action=invite', 'clfa_member_invite_' . $m->ID ) ); ?>"><?php esc_html_e( 'Send invite / reset link', 'clf-alumni' ); ?></a>
	          |
	          <a href="<?php echo esc_url( get_edit_user_link( $m->ID ) ); ?>"><?php esc_html_e( 'Edit user', 'clf-alumni' ); ?></a>
	        </td>
	      </tr>
	    <?php endforeach; ?>
	    </tbody>
	  </table>
	  <p class="description" style="margin-top:12px;"><?php echo esc_html( sprintf( __( '%d members total. Deactivated members cannot sign in and are hidden from the directory.', 'clf-alumni' ), count( $members ) ) ); ?></p>
	</div>
	<?php
}

/* ============================================================
   Add single member
   ============================================================ */
function clfa_admin_add_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = '';
	$error  = '';
	if ( isset( $_POST['clfa_add_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clfa_add_nonce'] ), 'clfa_add_member' ) ) {
		$result = clfa_create_member(
			wp_unslash( $_POST['email'] ?? '' ),
			wp_unslash( $_POST['first_name'] ?? '' ),
			wp_unslash( $_POST['last_name'] ?? '' ),
			wp_unslash( $_POST['spouse'] ?? '' ),
			wp_unslash( $_POST['class_year'] ?? '' ),
			! empty( $_POST['send_invite'] )
		);
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
		} elseif ( 'exists' === $result ) {
			$error = __( 'A user with that email already exists.', 'clf-alumni' );
		} else {
			$notice = __( 'Member created.', 'clf-alumni' ) . ( ! empty( $_POST['send_invite'] ) ? ' ' . __( 'Invite email sent.', 'clf-alumni' ) : '' );
		}
	}
	?>
	<div class="wrap">
	  <h1><?php esc_html_e( 'Add Alumni Member', 'clf-alumni' ); ?></h1>
	  <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
	  <?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
	  <form method="post" style="max-width:480px;">
	    <?php wp_nonce_field( 'clfa_add_member', 'clfa_add_nonce' ); ?>
	    <table class="form-table">
	      <tr><th><label><?php esc_html_e( 'Email', 'clf-alumni' ); ?> *</label></th><td><input type="email" name="email" class="regular-text" required></td></tr>
	      <tr><th><label><?php esc_html_e( 'First name', 'clf-alumni' ); ?> *</label></th><td><input type="text" name="first_name" class="regular-text" required></td></tr>
	      <tr><th><label><?php esc_html_e( 'Last name', 'clf-alumni' ); ?></label></th><td><input type="text" name="last_name" class="regular-text"></td></tr>
	      <tr><th><label><?php esc_html_e( 'Spouse', 'clf-alumni' ); ?></label></th><td><input type="text" name="spouse" class="regular-text"></td></tr>
	      <tr><th><label><?php esc_html_e( 'CLF class year', 'clf-alumni' ); ?></label></th><td><input type="text" name="class_year" class="regular-text" placeholder="e.g. 2019"></td></tr>
	      <tr><th></th><td><label><input type="checkbox" name="send_invite" checked> <?php esc_html_e( 'Send the welcome/invite email now', 'clf-alumni' ); ?></label></td></tr>
	    </table>
	    <p><button class="button button-primary"><?php esc_html_e( 'Create member', 'clf-alumni' ); ?></button></p>
	  </form>
	</div>
	<?php
}

/* ============================================================
   CSV import
   ============================================================ */
function clfa_admin_import_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$report = null;
	if ( isset( $_POST['clfa_import_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['clfa_import_nonce'] ), 'clfa_import' ) && ! empty( $_FILES['clfa_csv']['tmp_name'] ) ) {
		$report = array( 'created' => 0, 'skipped' => 0, 'errors' => array() );
		$send   = ! empty( $_POST['send_invites'] );
		$fh     = fopen( $_FILES['clfa_csv']['tmp_name'], 'r' );
		if ( $fh ) {
			$header = array_map( function ( $h ) {
				return strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', $h ) ) );
			}, (array) fgetcsv( $fh ) );
			$idx = array_flip( $header );
			if ( ! isset( $idx['email'] ) ) {
				$report['errors'][] = __( 'The CSV needs an "email" column. Expected columns: email, first_name, last_name, spouse, class_year.', 'clf-alumni' );
			} else {
				while ( ( $row = fgetcsv( $fh ) ) !== false ) {
					if ( count( array_filter( $row ) ) === 0 ) {
						continue;
					}
					$get    = function ( $col ) use ( $row, $idx ) {
						return isset( $idx[ $col ], $row[ $idx[ $col ] ] ) ? trim( $row[ $idx[ $col ] ] ) : '';
					};
					$result = clfa_create_member( $get( 'email' ), $get( 'first_name' ), $get( 'last_name' ), $get( 'spouse' ), $get( 'class_year' ), $send );
					if ( is_wp_error( $result ) ) {
						$report['errors'][] = $result->get_error_message();
					} elseif ( 'exists' === $result ) {
						$report['skipped']++;
					} else {
						$report['created']++;
					}
				}
			}
			fclose( $fh );
		}
	}
	?>
	<div class="wrap">
	  <h1><?php esc_html_e( 'Import Alumni from CSV', 'clf-alumni' ); ?></h1>
	  <?php if ( $report ) : ?>
	    <div class="notice notice-success"><p>
	      <?php echo esc_html( sprintf( __( 'Import finished: %1$d created, %2$d skipped (already exist).', 'clf-alumni' ), $report['created'], $report['skipped'] ) ); ?>
	    </p></div>
	    <?php if ( $report['errors'] ) : ?>
	      <div class="notice notice-warning"><p><strong><?php esc_html_e( 'Issues:', 'clf-alumni' ); ?></strong></p><ul style="list-style:disc;margin-left:2em;">
	        <?php foreach ( $report['errors'] as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?>
	      </ul></div>
	    <?php endif; ?>
	  <?php endif; ?>
	  <p><?php esc_html_e( 'Upload a CSV with a header row. Recognized columns:', 'clf-alumni' ); ?> <code>email</code> (<?php esc_html_e( 'required', 'clf-alumni' ); ?>), <code>first_name</code>, <code>last_name</code>, <code>spouse</code>, <code>class_year</code>.</p>
	  <p class="description"><?php esc_html_e( 'Each row creates one member account. Couples: add one row per person who should have their own login. Existing emails are skipped, so re-importing is safe.', 'clf-alumni' ); ?></p>
	  <form method="post" enctype="multipart/form-data">
	    <?php wp_nonce_field( 'clfa_import', 'clfa_import_nonce' ); ?>
	    <p><input type="file" name="clfa_csv" accept=".csv" required></p>
	    <p><label><input type="checkbox" name="send_invites"> <?php esc_html_e( 'Send welcome/invite emails immediately (you can also send them later, per member)', 'clf-alumni' ); ?></label></p>
	    <p><button class="button button-primary"><?php esc_html_e( 'Import', 'clf-alumni' ); ?></button></p>
	  </form>
	</div>
	<?php
}
