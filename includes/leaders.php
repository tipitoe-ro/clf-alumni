<?php
defined( 'ABSPATH' ) || exit;

/* Leadership is an admin-managed assignment, never a role or capability.
 * Each class is a separate meta row so class filters use exact matches.
 */
function clfa_leader_years( $user_id ) {
	return array_values( array_unique( get_user_meta( $user_id, 'clfa_leader_class', false ) ) );
}

function clfa_parse_leader_years( $value ) {
	$years = array_filter( array_map( 'trim', explode( ',', $value ) ) );
	foreach ( $years as $year ) {
		if ( ! preg_match( '/^[0-9]{4}$/D', $year ) ) {
			return new WP_Error( 'leader_year', __( 'Classes led must be four-digit years, separated by commas.', 'clf-alumni' ) );
		}
	}
	return array_values( array_unique( $years ) );
}

function clfa_leader_query( $year = '' ) {
	return array( array(
		'key' => 'clfa_leader_class',
		'compare' => $year ? '=' : 'EXISTS',
		'value' => $year,
	) );
}

function clfa_leader_profile_fields( $user ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_nonce_field( 'clfa_leaders_' . $user->ID, 'clfa_leaders_nonce' );
	?>
	<h2><?php esc_html_e( 'CLF class leadership', 'clf-alumni' ); ?></h2>
	<table class="form-table"><tr>
		<th><label for="clfa_leader_classes"><?php esc_html_e( 'Classes led', 'clf-alumni' ); ?></label></th>
		<td><input class="regular-text" id="clfa_leader_classes" name="clfa_leader_classes" value="<?php echo esc_attr( implode( ', ', clfa_leader_years( $user->ID ) ) ); ?>" placeholder="2026, 2027">
		<p class="description"><?php esc_html_e( 'Enter class years separated by commas. Leave blank to remove the Class leader tag. Independent of their own class year and student/alumni status; does not change access or permissions. Leader-only contacts may keep their existing role.', 'clf-alumni' ); ?></p></td>
	</tr></table>
	<?php
}
add_action( 'show_user_profile', 'clfa_leader_profile_fields' );
add_action( 'edit_user_profile', 'clfa_leader_profile_fields' );

function clfa_validate_leader_profile( $errors, $update, $user ) {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['clfa_leader_classes'] ) ) {
		return;
	}
	$years = clfa_parse_leader_years( sanitize_text_field( wp_unslash( $_POST['clfa_leader_classes'] ) ) );
	if ( is_wp_error( $years ) ) {
		$errors->add( 'leader_year', $years->get_error_message() );
	}
}
add_action( 'user_profile_update_errors', 'clfa_validate_leader_profile', 10, 3 );

function clfa_save_leader_profile( $user_id ) {
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id )
		|| ! isset( $_POST['clfa_leaders_nonce'], $_POST['clfa_leader_classes'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clfa_leaders_nonce'] ) ), 'clfa_leaders_' . $user_id ) ) {
		return;
	}
	$years = clfa_parse_leader_years( sanitize_text_field( wp_unslash( $_POST['clfa_leader_classes'] ) ) );
	if ( is_wp_error( $years ) ) {
		return;
	}
	$previous = clfa_leader_years( $user_id );
	foreach ( array_diff( $previous, $years ) as $year ) {
		delete_user_meta( $user_id, 'clfa_leader_class', $year );
	}
	foreach ( array_diff( $years, $previous ) as $year ) {
		add_user_meta( $user_id, 'clfa_leader_class', $year );
	}
}
add_action( 'personal_options_update', 'clfa_save_leader_profile' );
add_action( 'edit_user_profile_update', 'clfa_save_leader_profile' );
