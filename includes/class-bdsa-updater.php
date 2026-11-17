<?php
/**
 * Automatic updates from the public GitHub repository's Releases.
 *
 * Every release is tagged vX.Y.Z and carries bd-smart-address.zip (containing the
 * bd-smart-address/ folder). WordPress then shows "Update available" on the Plugins page
 * and, if the site has auto-updates switched on for this plugin, installs it by itself.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDSA_Updater {

	const SLUG      = 'bd-smart-address';
	const REPO      = 'mdsajedulra/bd-smart-address';
	const CACHE     = 'bdsa_update_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
		add_action( 'admin_post_bdsa_check_updates', array( __CLASS__, 'check_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BDSA_FILE ), array( __CLASS__, 'row_links' ) );
		self::seed_auto_update();
	}

	private static function repo() {
		$r = (string) apply_filters( 'bdsa_update_repo', self::REPO );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r ) ? $r : '';
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		return array(
			'Accept'               => $accept,
			'User-Agent'           => 'bd-smart-address-updater',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
	}

	/**
	 * Switch WordPress auto-updates on for this plugin once per site. It then shows as
	 * "Auto-updates enabled" on the Plugins page and the site owner can turn it off there.
	 */
	private static function seed_auto_update() {
		if ( get_option( 'bdsa_autoupdate_seeded' ) ) {
			return;
		}
		$list = (array) get_site_option( 'auto_update_plugins', array() );
		$file = plugin_basename( BDSA_FILE );
		if ( ! in_array( $file, $list, true ) ) {
			$list[] = $file;
			update_site_option( 'auto_update_plugins', array_values( array_unique( $list ) ) );
		}
		update_option( 'bdsa_autoupdate_seeded', 1 );
	}

	/** Latest release: array( version, tag, notes, published, asset_url, html_url ) or WP_Error. Cached. */
	public static function latest( $force = false ) {
		if ( ! self::repo() ) {
			return new WP_Error( 'bdsa_upd_repo', 'No update repository set.' );
		}
		if ( ! $force ) {
			$c = get_site_transient( self::CACHE );
			if ( is_array( $c ) ) {
				return $c;
			}
			$e = get_site_transient( self::CACHE . '_err' );
			if ( $e ) {
				return new WP_Error( 'bdsa_upd_cached', (string) $e );
			}
		}
		delete_site_transient( self::CACHE . '_err' );
		$res = wp_remote_get( 'https://api.github.com/repos/' . self::repo() . '/releases/latest', array( 'timeout' => 15, 'headers' => self::headers() ) );
		if ( is_wp_error( $res ) ) {
			set_site_transient( self::CACHE . '_err', $res->get_error_message(), HOUR_IN_SECONDS );
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = 404 === $code ? 'No release found yet on GitHub.' : ( 403 === $code ? 'GitHub is limiting requests right now.' : 'GitHub HTTP ' . $code );
			set_site_transient( self::CACHE . '_err', $msg, HOUR_IN_SECONDS );
			return new WP_Error( 'bdsa_upd_http', $msg );
		}
		$asset = '';
		foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $a ) {
			if ( isset( $a['name'], $a['url'] ) && preg_match( '/\.zip$/i', $a['name'] ) ) {
				$asset = $a['url'];
				if ( 'bd-smart-address.zip' === $a['name'] ) {
					break;
				}
			}
		}
		$tag  = isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '';
		$data = array(
			'version'   => ltrim( $tag, 'vV' ),
			'tag'       => $tag,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			'asset_url' => $asset,
			'html_url'  => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
		);
		set_site_transient( self::CACHE, $data, self::CACHE_TTL );
		return $data;
	}

	/** Tell WordPress about a newer release. */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$rel = self::latest();
		if ( is_wp_error( $rel ) || empty( $rel['asset_url'] ) || ! $rel['version'] ) {
			return $transient;
		}
		$file = plugin_basename( BDSA_FILE );
		$item = (object) array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => $file,
			'new_version'  => $rel['version'],
			'url'          => $rel['html_url'],
			'package'      => $rel['asset_url'],
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);
		if ( version_compare( $rel['version'], BDSA_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
			unset( $transient->no_update[ $file ] );
		} else {
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	/** "View details" popup on the Plugins page. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$rel = self::latest();
		if ( is_wp_error( $rel ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'BD Smart Address for WooCommerce',
			'slug'          => self::SLUG,
			'version'       => $rel['version'],
			'author'        => 'Sajedul',
			'homepage'      => $rel['html_url'],
			'last_updated'  => $rel['published'],
			'requires_php'  => '7.4',
			'download_link' => $rel['asset_url'],
			'sections'      => array(
				'changelog' => $rel['notes'] ? wpautop( esc_html( $rel['notes'] ) ) : 'No release notes.',
			),
		);
	}

	/** Release assets sit behind a redirect to a signed URL; follow it so the zip arrives intact. */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! is_string( $package ) || ! self::repo() || 0 !== strpos( $package, 'https://api.github.com/repos/' . self::repo() . '/releases/assets/' ) ) {
			return $reply;
		}
		$res = wp_remote_get( $package, array( 'timeout' => 30, 'redirection' => 0, 'headers' => self::headers( 'application/octet-stream' ) ) );
		if ( is_wp_error( $res ) ) {
			set_site_transient( self::CACHE . '_err', $res->get_error_message(), HOUR_IN_SECONDS );
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$loc = wp_remote_retrieve_header( $res, 'location' );
			if ( ! $loc ) {
				return new WP_Error( 'bdsa_upd_dl', 'GitHub did not return a download location.' );
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			return download_url( $loc, 300 );
		}
		if ( 200 === $code ) {
			$tmp = wp_tempnam( 'bd-smart-address.zip' );
			file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore
			return $tmp;
		}
		return new WP_Error( 'bdsa_upd_dl', sprintf( 'Download failed (GitHub HTTP %d).', $code ) );
	}

	/** Make sure the unpacked folder is named bd-smart-address, whatever the zip calls it. */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( empty( $extra['plugin'] ) || plugin_basename( BDSA_FILE ) !== $extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $want || ! $wp_filesystem ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $want, true ) ? $want : $source;
	}

	public static function after_update( $upgrader, $opts ) {
		if ( isset( $opts['type'] ) && 'plugin' === $opts['type'] ) {
			delete_site_transient( self::CACHE );
			delete_site_transient( self::CACHE . '_err' );
		}
	}

	/** Plugins page: "Check for updates" link. */
	public static function row_links( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdsa_check_updates' ), 'bdsa_check_updates' ) ) . '">Check for updates</a>';
		}
		return $links;
	}

	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed' );
		}
		check_admin_referer( 'bdsa_check_updates' );
		delete_site_transient( self::CACHE );
		delete_site_transient( self::CACHE . '_err' );
		self::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
