<?php
/**
 * Amount helpers. AffiWave expects a decimal string with at most two fraction digits (`^\d{1,12}(\.\d{1,2})?$`).
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

final class Money {

	/**
	 * Net amount of an order: total minus tax, never negative. Uses the order's own tax lines, so reverse charge,
	 * 0 % and mixed rates come out right without a configured VAT rate.
	 */
	public static function net( float|string $total, float|string $tax ): string {
		$cents = (int) round( (float) $total * 100 ) - (int) round( (float) $tax * 100 );

		return self::from_cents( max( 0, $cents ) );
	}

	public static function from_cents( int $cents ): string {
		return sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 );
	}

	public static function normalize( float|string $amount ): string {
		return self::from_cents( max( 0, (int) round( (float) $amount * 100 ) ) );
	}
}
