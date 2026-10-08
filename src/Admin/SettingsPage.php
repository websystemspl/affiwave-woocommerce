<?php
/**
 * WooCommerce → AffiWave: settings, webhook URL to copy into AffiWave and a connection test.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce\Admin;

use AffiWave\WooCommerce\ApiClient;
use AffiWave\WooCommerce\CouponSync;
use AffiWave\WooCommerce\IntegrationKey;
use AffiWave\WooCommerce\Settings;
use AffiWave\WooCommerce\WebhookController;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	private const SLUG   = 'affiwave';
	private const SECRET = array( 'api_key', 'webhook_secret' );

	public function __construct( private Settings $settings, private CouponSync $coupons ) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_affiwave_wc_save', array( $this, 'save' ) );
		add_action( 'admin_post_affiwave_wc_test', array( $this, 'test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AFFIWAVE_WC_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', 'AffiWave', 'AffiWave', 'manage_woocommerce', self::SLUG, array( $this, 'render' ) );
	}

	/** @param array<string> $links */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'affiwave-woocommerce' ) . '</a>' );

		return $links;
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'affiwave-woocommerce' ) );
		}
		check_admin_referer( 'affiwave_wc_save' );

		$input  = isset( $_POST['affiwave'] ) && is_array( $_POST['affiwave'] ) ? wp_unslash( $_POST['affiwave'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Settings::save().
		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		// The integration key from AffiWave fills address, API key, webhook secret and program ID in one go.
		$integration_key = trim( (string) ( $input['integration_key'] ?? '' ) );
		unset( $input['integration_key'] );
		if ( '' !== $integration_key ) {
			$decoded = IntegrationKey::decode( $integration_key );
			if ( null === $decoded ) {
				wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'invalid_key' => '1' ), admin_url( 'admin.php' ) ) );
				exit;
			}
			$input = array_merge( $input, $decoded );
		}

		foreach ( self::SECRET as $key ) {
			// An empty secret field means "keep the stored value"; the stored value is never printed back.
			if ( '' === trim( (string) ( $input[ $key ] ?? '' ) ) ) {
				$input[ $key ] = $stored[ $key ] ?? '';
			}
		}
		$this->settings->save( $input );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'affiwave-woocommerce' ) );
		}
		check_admin_referer( 'affiwave_wc_test' );

		$response = ( new ApiClient( $this->settings ) )->get_coupon_codes( gmdate( DATE_ATOM ), 1 );
		set_transient( 'affiwave_wc_test_' . get_current_user_id(), $response->ok() ? 'ok' : $response->summary(), 60 );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tested' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$fields = array(
			'api_url'        => array( __( 'AffiWave address', 'affiwave-woocommerce' ), 'url', __( 'Default: https://affiwave.com', 'affiwave-woocommerce' ) ),
			'api_key'        => array( __( 'API key', 'affiwave-woocommerce' ), 'password', __( 'AffiWave → Settings → API keys. Scopes: conversions:write and reports:read.', 'affiwave-woocommerce' ) ),
			'webhook_secret' => array( __( 'Webhook secret', 'affiwave-woocommerce' ), 'password', __( 'Shown once when you add the webhook below in AffiWave → Webhooks (events coupon.created and coupon.updated).', 'affiwave-woocommerce' ) ),
			'program_id'     => array( __( 'Program ID', 'affiwave-woocommerce' ), 'number', __( 'Only coupons of this program are created in the shop. Empty = all programs of the account.', 'affiwave-woocommerce' ) ),
			'order_prefix'   => array( __( 'Order ID prefix', 'affiwave-woocommerce' ), 'text', __( 'Sent as external_order_id = prefix + order number. Must be unique per shop within one AffiWave account. Empty = shop host.', 'affiwave-woocommerce' ) ),
			'cookie_days'    => array( __( 'Click cookie lifetime (days)', 'affiwave-woocommerce' ), 'number', __( 'Keep it equal to the program cookie duration in AffiWave.', 'affiwave-woocommerce' ) ),
		);
		$test = get_transient( 'affiwave_wc_test_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1>AffiWave</h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'affiwave-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['invalid_key'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This is not a valid integration key. Copy it again from AffiWave → Integrations → WordPress → Configure.', 'affiwave-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['tested'] ) && false !== $test ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice <?php echo 'ok' === $test ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p>
					<?php
					echo 'ok' === $test
						? esc_html__( 'Connection works: the API key is valid and can read coupons.', 'affiwave-woocommerce' )
						/* translators: %s: error, e.g. "HTTP 401 invalid_api_key" */
						: esc_html( sprintf( __( 'Connection failed: %s', 'affiwave-woocommerce' ), $test ) );
					?>
				</p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Partner links of your AffiWave program must use the attribution parameter aw_click. Every paid order of a referred customer — including subscription renewals — is reported as a conversion (net amount, order currency).', 'affiwave-woocommerce' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="affiwave_wc_save">
				<?php wp_nonce_field( 'affiwave_wc_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="affiwave-integration_key"><?php esc_html_e( 'Integration key', 'affiwave-woocommerce' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="3" id="affiwave-integration_key" name="affiwave[integration_key]" autocomplete="off" spellcheck="false"
								placeholder="awi1_…"></textarea>
							<p class="description"><?php esc_html_e( 'Generate it in AffiWave → Integrations → WordPress → Configure and paste it here. It fills in the address, API key, webhook secret and program ID below — and creates the coupon webhook for this shop on the AffiWave side.', 'affiwave-woocommerce' ); ?></p>
						</td>
					</tr>
					<?php foreach ( $fields as $key => [ $label, $type, $help ] ) : ?>
						<?php
						$locked = $this->settings->is_locked( $key );
						$secret = in_array( $key, self::SECRET, true );
						$value  = $secret ? '' : $this->settings->get( $key );
						?>
						<tr>
							<th scope="row"><label for="affiwave-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input class="regular-text" id="affiwave-<?php echo esc_attr( $key ); ?>" name="affiwave[<?php echo esc_attr( $key ); ?>]"
									type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" autocomplete="off"
									<?php disabled( $locked ); ?>
									<?php if ( $secret && '' !== $this->settings->get( $key ) ) : ?>placeholder="<?php esc_attr_e( 'stored — leave empty to keep', 'affiwave-woocommerce' ); ?>"<?php endif; ?>>
								<p class="description">
									<?php echo esc_html( $help ); ?>
									<?php if ( $locked ) : ?>
										<br><strong><?php echo esc_html( sprintf( /* translators: %s: constant name */ __( 'Set in wp-config.php (%s).', 'affiwave-woocommerce' ), Settings::CONSTANTS[ $key ] ) ); ?></strong>
									<?php endif; ?>
								</p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'affiwave-woocommerce' ); ?></th>
						<td><code><?php echo esc_html( WebhookController::url() ); ?></code>
							<p class="description"><?php esc_html_e( 'With an integration key AffiWave already sends coupons here. Setting it up by hand: add it in AffiWave → Webhooks with the events coupon.created and coupon.updated.', 'affiwave-woocommerce' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last coupon sync', 'affiwave-woocommerce' ); ?></th>
						<td><?php echo esc_html( (string) get_option( CouponSync::SYNCED_AT, __( 'never', 'affiwave-woocommerce' ) ) ); ?></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="affiwave_wc_test">
				<?php wp_nonce_field( 'affiwave_wc_test' ); ?>
				<?php submit_button( __( 'Test connection', 'affiwave-woocommerce' ), 'secondary', 'submit', false, $this->settings->is_enabled() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}
}
