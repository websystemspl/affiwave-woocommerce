<?php
/**
 * A GitHub release (GET /repos/{repo}/releases/latest) turned into what the WordPress updater needs.
 * WordPress-independent, so it is unit tested.
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Release {

	/** Name of the installable zip attached to every release (bin/build-zip.sh). */
	public const ASSET = 'affiwave-woocommerce.zip';

	/**
	 * @param array<string, mixed> $release GitHub API release object.
	 * @return array{version: string, package: string, url: string, published_at: string, notes: string}|null
	 *         null for drafts, pre-releases, a tag that is not a version or a release without the zip.
	 */
	public static function from_github( array $release ): ?array {
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && self::ASSET === ( $asset['name'] ?? '' ) && str_starts_with( (string) ( $asset['browser_download_url'] ?? '' ), 'https://' ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			return null;
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'url'          => (string) ( $release['html_url'] ?? '' ),
			'published_at' => (string) ( $release['published_at'] ?? '' ),
			'notes'        => (string) ( $release['body'] ?? '' ),
		);
	}

	/** Is the release newer than the installed version? */
	public static function is_newer( array $release, string $installed ): bool {
		return version_compare( $release['version'], $installed, '>' );
	}

	/**
	 * Release notes (GitHub Markdown) as simple, escaped HTML for "View details": headings, bullet lists, **bold**,
	 * `code` and links. Anything else stays plain text.
	 */
	public static function notes_html( string $markdown ): string {
		$inline = static function ( string $text ): string {
			$text = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
			$text = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

			return (string) preg_replace( '#(https://[^\s<)]+)#', '<a href="$1">$1</a>', $text );
		};

		$html = '';
		$list = false;
		foreach ( preg_split( '/\R/', trim( $markdown ) ) ?: array() as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . $inline( $m[1] ) . '</li>';
				$list  = true;
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . $inline( $m[1] ) . '</h4>';
			} elseif ( '' !== $line ) {
				$html .= '<p>' . $inline( $line ) . '</p>';
			}
		}

		return $html . ( $list ? '</ul>' : '' );
	}
}
