<?php
/**
 * Calque settings screen.
 *
 * @package    Calque
 * @subpackage Calque/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings       = $view['settings'];
$connected      = $view['connected'];
$refresh_url    = wp_nonce_url( admin_url( 'admin.php?page=calque&calque_refresh=1' ), 'calque_refresh_calendars' );
$admin_error    = '';

if ( isset( $_GET['calque_error'] ) ) {
	$stored = get_transient( 'calque_admin_error_' . get_current_user_id() );
	delete_transient( 'calque_admin_error_' . get_current_user_id() );
	if ( is_string( $stored ) ) {
		$admin_error = $stored;
	}
}
?>
<div class="wrap calque-wrap">
	<h1><?php esc_html_e( 'Calque', 'calque' ); ?></h1>
	<p class="calque-lead"><?php esc_html_e( 'Mirror BookingPress appointments onto one Google Calendar.', 'calque' ); ?></p>

	<?php if ( ! $view['bookingpress'] ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: BookingPress website URL. */
						__( 'Calque requires BookingPress Appointment Booking. <a href="%s">Install it from the BookingPress website</a>.', 'calque' ),
						esc_url( CALQUE_BOOKINGPRESS_URL )
					),
					array(
						'a' => array(
							'href' => array(),
						),
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['calque_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'calque' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['calque_connected'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google Calendar is connected. Choose a calendar and save.', 'calque' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['calque_disconnected'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Disconnected from Google Calendar.', 'calque' ); ?></p></div>
	<?php endif; ?>

	<?php if ( '' !== $admin_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $admin_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['calque_sent'] ) ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				$sent    = absint( $_GET['calque_sent'] );
				$skipped = isset( $_GET['calque_skipped'] ) ? absint( $_GET['calque_skipped'] ) : 0;
				$failed  = isset( $_GET['calque_failed'] ) ? absint( $_GET['calque_failed'] ) : 0;

				if ( 1 === $sent ) {
					esc_html_e( '1 appointment was sent to Google Calendar.', 'calque' );
				} else {
					echo esc_html( sprintf( __( '%d appointments were sent to Google Calendar.', 'calque' ), $sent ) );
				}
				echo ' ';
				if ( 1 === $skipped ) {
					esc_html_e( '1 already linked booking was left untouched.', 'calque' );
				} else {
					echo esc_html( sprintf( __( '%d already linked bookings were left untouched.', 'calque' ), $skipped ) );
				}
				if ( $failed > 0 ) {
					echo ' ';
					if ( 1 === $failed ) {
						esc_html_e( '1 appointment could not be sent.', 'calque' );
					} else {
						echo esc_html( sprintf( __( '%d appointments could not be sent.', 'calque' ), $failed ) );
					}
				}
				?>
			</p>
			<?php if ( ! empty( $view['sync_errors'] ) ) : ?>
				<ul>
					<?php foreach ( $view['sync_errors'] as $sync_error ) : ?>
						<li><?php echo esc_html( $sync_error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="calque-card">
		<input type="hidden" name="action" value="calque_save_settings" />
		<?php wp_nonce_field( 'calque_save_settings' ); ?>

		<h2><?php esc_html_e( 'Google connection', 'calque' ); ?></h2>
		<p>
			<span class="calque-status <?php echo $connected ? 'is-on' : 'is-off'; ?>">
				<?php echo $connected ? esc_html__( 'Connected', 'calque' ) : esc_html__( 'Not connected', 'calque' ); ?>
			</span>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="calque-client-id"><?php esc_html_e( 'Client ID', 'calque' ); ?></label></th>
				<td><input id="calque-client-id" name="client_id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['client_id'] ); ?>" autocomplete="off" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="calque-client-secret"><?php esc_html_e( 'Client secret', 'calque' ); ?></label></th>
				<td>
					<input id="calque-client-secret" name="client_secret" type="password" class="regular-text" value="" autocomplete="new-password" />
					<?php if ( $view['has_secret'] ) : ?>
						<p class="description"><?php esc_html_e( 'A secret is saved. Leave this blank to keep it.', 'calque' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="calque-redirect"><?php esc_html_e( 'Redirect URI', 'calque' ); ?></label></th>
				<td>
					<input id="calque-redirect" type="text" class="large-text calque-uri" readonly value="<?php echo esc_attr( $view['redirect_uri'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Add this URI to the Google Cloud OAuth client.', 'calque' ); ?></p>
				</td>
			</tr>
			<?php if ( $connected ) : ?>
				<tr>
					<th scope="row"><label for="calque-calendar"><?php esc_html_e( 'Calendar', 'calque' ); ?></label></th>
					<td>
						<select id="calque-calendar" name="calendar_id">
							<option value=""><?php esc_html_e( 'Select a calendar', 'calque' ); ?></option>
							<?php foreach ( $view['calendars'] as $calendar_id => $calendar_name ) : ?>
								<option value="<?php echo esc_attr( $calendar_id ); ?>" <?php selected( $settings['calendar_id'], $calendar_id ); ?>><?php echo esc_html( $calendar_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p><a href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh calendar list', 'calque' ); ?></a></p>
						<?php if ( '' !== $view['calendar_error'] ) : ?>
							<p class="description calque-error"><?php echo esc_html( $view['calendar_error'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<?php submit_button( __( 'Save settings', 'calque' ) ); ?>
	</form>

	<div class="calque-card">
		<h2><?php esc_html_e( 'Account', 'calque' ); ?></h2>
		<?php if ( $connected ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="calque_disconnect" />
				<?php wp_nonce_field( 'calque_disconnect' ); ?>
				<?php submit_button( __( 'Disconnect', 'calque' ), 'delete', 'submit', false ); ?>
			</form>
		<?php elseif ( '' !== $view['auth_url'] ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( $view['auth_url'] ); ?>"><?php esc_html_e( 'Connect Google Calendar', 'calque' ); ?></a></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Save a Client ID and Client secret, then connect the calendar.', 'calque' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="calque-card">
		<h2><?php esc_html_e( 'Existing appointments', 'calque' ); ?></h2>
		<p><?php esc_html_e( 'Send appointments that are not on Google Calendar yet. Bookings already linked are skipped, including ones that were later cancelled.', 'calque' ); ?></p>
		<?php if ( '' !== $view['last_error'] && empty( $_GET['calque_sent'] ) ) : ?>
			<p class="calque-error"><?php echo esc_html( $view['last_error'] ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="calque_sync_existing" />
			<?php wp_nonce_field( 'calque_sync_existing' ); ?>
			<?php
			submit_button(
				__( 'Sync existing appointments', 'calque' ),
				'secondary',
				'submit',
				false,
				$view['ready'] ? array() : array( 'disabled' => 'disabled' )
			);
			?>
		</form>
		<?php if ( ! $view['ready'] ) : ?>
			<p class="description"><?php esc_html_e( 'Connect a calendar and keep BookingPress active to enable this.', 'calque' ); ?></p>
		<?php endif; ?>
	</div>
</div>
