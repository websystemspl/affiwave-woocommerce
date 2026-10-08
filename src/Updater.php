<?php
/**
 * Updates from GitHub Releases. The plugin header "Update URI: https://github.com/websystemspl/affiwave-woocommerce"
 * makes WordPress (5.8+) skip wordpress.org and ask the `update_plugins_github.com` filter instead; this class answers
 * it with the latest release, so Dashboard → Updates, the plugin list, auto-updates and `wp plugin update` all work.
 *
 * The latest release is cached for 6 hours (1 hour after an error) — the anonymous GitHub API allows 60 requests per
 * hour per IP. "Check again" in Dashboard → Updates skips the cache.
 *
 * Remove this class and the Update URI header before publishing in the wordpress.org directory (it updates plugins itself).
 *
 * @package AffiWave\WooCommerce
 */

namespace AffiWave\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const REPOSITORY = 'websystemspl/affiwave-woocommerce';
	public const SLUG       = 'affiwave-woocommerce';
	private const CACHE     = 'affiwave_wc_latest_release';

	public function register(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'keep_directory' ), 10, 4 );
	}

	/**
	 * @param array|false          $update
	 * @param array<string, mixed> $plugin_data
	 * @return array|false
	 */
	public function update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( AFFIWAVE_WC_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = $this->latest();
		if ( null === $release ) {
			return $update;
		}

		// WordPress itself compares `version` with the installed one and shows an update only when it is newer.
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => (string) ( $plugin_data['RequiresWP'] ?? '' ),
			'requires_php' => (string) ( $plugin_data['RequiresPHP'] ?? '' ),
		);
	}

	/**
	 * "View details" in the plugin list / update screen.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = $this->latest();
		if ( null === $release ) {
			return $result;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin = get_plugin_data( AFFIWAVE_WC_FILE, false, false );

		return (object) array(
			'name'          => $plugin['Name'],
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://affiwave.com">AffiWave</a>',
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'download_link' => $release['package'],
			'requires'      => $plugin['RequiresWP'],
			'requires_php'  => $plugin['RequiresPHP'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => esc_html( $plugin['Description'] ),
				'changelog'   => '' !== trim( $release['notes'] )
					? Release::notes_html( $release['notes'] )
					: '<p><a href="' . esc_url( $release['url'] ) . '">' . esc_html( $release['url'] ) . '</a></p>',
			),
		);
	}

	/**
	 * The zip always contains `affiwave-woocommerce/`. A copy installed under another directory name (e.g. a git clone)
	 * is updated in place instead of ending up next to it.
	 *
	 * @param string|\WP_Error $source
	 * @param array            $hook_extra
	 * @return string|\WP_Error
	 */
	public function keep_directory( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( is_wp_error( $source ) || plugin_basename( AFFIWAVE_WC_FILE ) !== ( $hook_extra['plugin'] ?? '' ) ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( plugin_basename( AFFIWAVE_WC_FILE ) ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) || ! $wp_filesystem ) {
			return $source;
		}

		return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}

	/** @return array{version: string, package: string, url: string, published_at: string, notes: string}|null */
	private function latest(): ?array {
		$force  = is_admin() && isset( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: "Check again" in Dashboard → Updates.
		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached['release'];
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AffiWave-for-WooCommerce/' . AFFIWAVE_WC_VERSION,
				),
			)
		);
		$body    = 200 === wp_remote_retrieve_response_code( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		$release = is_array( $body ) ? Release::from_github( $body ) : null;

		set_site_transient( self::CACHE, array( 'release' => $release ), null === $release ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );

		return $release;
	}
}
