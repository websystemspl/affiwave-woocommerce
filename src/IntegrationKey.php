<?php
/**
 * Integration key generated in AffiWave → Integrations → WordPress → Configure: one string that carries the AffiWave
 * address, the API key, the webhook secret and the program ID, so the shop owner pastes one value instead of four.
 *
 * Format: "awi1_" + base64url(JSON {"u": url, "k": api key, "s": webhook secret, "p": program id}).
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class IntegrationKey {

	public const PREFIX = 'awi1_';

	/**
	 * Decodes the key into setting values (api_url, api_key, webhook_secret, program_id) or returns null when the key
	 * is malformed. Whitespace and line breaks from copying are ignored.
	 *
	 * @return array{api_url: string, api_key: string, webhook_secret: string, program_id: string}|null
	 */
	public static function decode( string $key ): ?array {
		$key = (string) preg_replace( '/\s+/', '', $key );
		if ( ! str_starts_with( $key, self::PREFIX ) ) {
			return null;
		}
		$json = base64_decode( strtr( substr( $key, strlen( self::PREFIX ) ), '-_', '+/' ), true );
		$data = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}

		$url     = is_string( $data['u'] ?? null ) ? rtrim( $data['u'], '/' ) : '';
		$api_key = is_string( $data['k'] ?? null ) ? $data['k'] : '';
		$secret  = is_string( $data['s'] ?? null ) ? $data['s'] : '';
		$program = is_int( $data['p'] ?? null ) && $data['p'] > 0 ? (string) $data['p'] : '';
		if ( ! preg_match( '#^https?://[^\s/]+#', $url ) || '' === $api_key ) {
			return null;
		}

		return array(
			'api_url'        => $url,
			'api_key'        => $api_key,
			'webhook_secret' => $secret,
			'program_id'     => $program,
		);
	}

	/** Builds a key the same way AffiWave does (tests, dev tools). */
	public static function encode( string $url, string $api_key, string $webhook_secret, ?int $program_id ): string {
		$json = (string) json_encode(
			array(
				'u' => $url,
				'k' => $api_key,
				's' => $webhook_secret,
				'p' => $program_id,
			),
			JSON_UNESCAPED_SLASHES
		);

		return self::PREFIX . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}
}
