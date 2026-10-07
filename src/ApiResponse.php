<?php
/**
 * Result of an AffiWave API call. Status 0 = no HTTP response (disabled, network error, timeout).
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

final class ApiResponse {

	/** @param array<mixed>|null $data */
	public function __construct(
		public readonly int $status,
		public readonly ?array $data,
		public readonly ?string $error = null,
	) {
	}

	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/** Worth retrying later: no response, rate limit or a server error. A 4xx (bad token, bad key) is final. */
	public function retryable(): bool {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}

	public function summary(): string {
		if ( $this->ok() ) {
			return 'HTTP ' . $this->status;
		}
		$code = is_array( $this->data ) ? (string) ( $this->data['error'] ?? $this->data['code'] ?? '' ) : '';

		return trim( ( $this->status > 0 ? 'HTTP ' . $this->status : 'no response' ) . ' ' . $code . ( null !== $this->error && '' === $code ? ' ' . $this->error : '' ) );
	}
}
