<?php
/**
 * AffiWave webhook signature: `X-AffiWave-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, t + "." + raw body)>`.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

final class Signature {

	/** Replay window in seconds (AffiWave signs every delivery attempt with a fresh `t`). */
	public const TOLERANCE = 300;

	public static function verify( string $header, string $raw_body, string $secret, int $now ): bool {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$parts = array();
		foreach ( explode( ',', $header ) as $pair ) {
			$kv = explode( '=', trim( $pair ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}
		$timestamp = $parts['t'] ?? '';
		$signature = $parts['v1'] ?? '';
		if ( '' === $signature || 1 !== preg_match( '/^\d+$/', $timestamp ) || abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		return hash_equals( self::sign( $timestamp, $raw_body, $secret ), $signature );
	}

	public static function sign( string $timestamp, string $raw_body, string $secret ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
	}

	public static function header( int $timestamp, string $raw_body, string $secret ): string {
		return 't=' . $timestamp . ',v1=' . self::sign( (string) $timestamp, $raw_body, $secret );
	}
}
