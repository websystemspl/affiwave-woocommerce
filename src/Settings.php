<?php
/**
 * Plugin settings: stored in the `affiwave_wc_settings` option, each one overridable by a wp-config.php constant
 * (handy for secrets and for per-environment values). A constant always wins and locks the field in wp-admin.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'affiwave_wc_settings';

	/** Setting key => wp-config.php constant that overrides it. */
	public const CONSTANTS = array(
		'api_url'        => 'AFFIWAVE_API_URL',
		'api_key'        => 'AFFIWAVE_API_KEY',
		'webhook_secret' => 'AFFIWAVE_WEBHOOK_SECRET',
		'program_id'     => 'AFFIWAVE_PROGRAM_ID',
		'order_prefix'   => 'AFFIWAVE_ORDER_PREFIX',
		'cookie_days'    => 'AFFIWAVE_COOKIE_DAYS',
	);

	public const DEFAULTS = array(
		'api_url'        => 'https://affiwave.com',
		'api_key'        => '',
		'webhook_secret' => '',
		'program_id'     => '',
		'order_prefix'   => '',
		'cookie_days'    => '30',
	);

	/** @var array<string, string>|null */
	private ?array $stored = null;

	public function get( string $key ): string {
		$constant = self::CONSTANTS[ $key ] ?? null;
		if ( null !== $constant && defined( $constant ) ) {
			return trim( (string) constant( $constant ) );
		}
		if ( null === $this->stored ) {
			$stored       = get_option( self::OPTION, array() );
			$this->stored = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		}

		return trim( $this->stored[ $key ] ?? ( self::DEFAULTS[ $key ] ?? '' ) );
	}

	public function is_locked( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/** Reporting is on only with both the API address and the API key. */
	public function is_enabled(): bool {
		return '' !== $this->api_url() && '' !== $this->get( 'api_key' );
	}

	public function api_url(): string {
		return rtrim( $this->get( 'api_url' ), '/' );
	}

	public function program_id(): ?int {
		$id = (int) $this->get( 'program_id' );

		return $id > 0 ? $id : null;
	}

	public function cookie_days(): int {
		$days = (int) $this->get( 'cookie_days' );

		return $days > 0 ? min( $days, 365 ) : 30;
	}

	/**
	 * Prefix of `external_order_id`. AffiWave de-duplicates conversions per merchant account, so two shops reporting
	 * to one account must not both send "123". Default: the shop host, e.g. "shop.example.com-123".
	 */
	public function order_prefix(): string {
		$prefix = $this->get( 'order_prefix' );
		if ( '' === $prefix ) {
			$host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$prefix = ( is_string( $host ) && '' !== $host ? $host : 'wc' ) . '-';
		}

		return $prefix;
	}

	public function save( array $values ): void {
		$clean = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$clean[ $key ] = isset( $values[ $key ] ) ? trim( sanitize_text_field( (string) $values[ $key ] ) ) : $default;
		}
		$clean['api_url'] = esc_url_raw( $clean['api_url'] );
		update_option( self::OPTION, $clean, false );
		$this->stored = null;
	}

	public function reload(): void {
		$this->stored = null;
	}
}
