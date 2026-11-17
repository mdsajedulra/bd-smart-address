<?php
/**
 * REST endpoint: POST /wp-json/bdsa/v1/parse  { "address": "..." }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDSA_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route(
			'bdsa/v1',
			'/parse',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'parse' ),
				// Public on purpose: guests check out too. Protected by rate limits and a daily AI cap.
				'permission_callback' => '__return_true',
				'args'                => array(
					'address' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	private static function ip() {
		if ( class_exists( 'WC_Geolocation' ) ) {
			return (string) WC_Geolocation::get_ip_address();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Fixed-window counter per IP. Returns false when over the limit.
	 */
	private static function hit( $bucket, $limit, $ttl ) {
		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return true;
		}
		$key = 'bdsa_' . $bucket . '_' . md5( self::ip() );
		$d   = get_transient( $key );
		if ( ! is_array( $d ) || ! isset( $d['n'], $d['t'] ) || $d['t'] < time() - $ttl ) {
			$d = array(
				'n' => 0,
				't' => time(),
			);
		}
		if ( $d['n'] >= $limit ) {
			return false;
		}
		++$d['n'];
		set_transient( $key, $d, $ttl );
		return true;
	}

	private static function ai_allowed() {
		$cap = (int) BDSA_Plugin::get( 'ai_daily_cap' );
		if ( $cap > 0 ) {
			$key = 'bdsa_ai_day_' . gmdate( 'Ymd' );
			$n   = (int) get_transient( $key );
			if ( $n >= $cap ) {
				return false;
			}
		}
		if ( ! self::hit( 'a', BDSA_Plugin::get( 'ai_ip_limit' ), HOUR_IN_SECONDS ) ) {
			return false;
		}
		if ( $cap > 0 ) {
			set_transient( $key, $n + 1, 2 * DAY_IN_SECONDS );
		}
		return true;
	}

	public static function parse( WP_REST_Request $req ) {
		if ( ! BDSA_Plugin::get( 'enabled' ) ) {
			return new WP_Error( 'bdsa_disabled', 'Disabled', array( 'status' => 403 ) );
		}

		$address = wp_strip_all_tags( (string) $req->get_param( 'address' ) );
		$address = trim( preg_replace( '/\s+/u', ' ', $address ) );
		$address = mb_substr( $address, 0, 300 );
		if ( mb_strlen( $address ) < 6 ) {
			return new WP_Error( 'bdsa_short', 'Address too short', array( 'status' => 400 ) );
		}
		if ( ! self::hit( 'r', BDSA_Plugin::get( 'ip_limit' ), HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'bdsa_rate', 'Too many requests', array( 'status' => 429 ) );
		}

		$ai_on = BDSA_AI::available();
		$ckey  = 'bdsa_c_' . md5( BDSA_Geo::norm( $address ) . '|' . ( $ai_on ? '1' : '0' ) . '|' . BDSA_Delivery::signature() . '|' . BDSA_VERSION );
		$hit   = get_transient( $ckey );
		if ( is_array( $hit ) ) {
			return rest_ensure_response( $hit );
		}

		$r       = BDSA_Geo::analyze( $address );
		$di      = $r['district'];
		$ti      = $r['thana'];
		$refined = $r['refined'];
		$source  = 'rules';

		if ( $ai_on && ( 'always' === BDSA_Plugin::get( 'ai_when' ) || 'high' !== $r['confidence'] ) && self::ai_allowed() ) {
			$ai = BDSA_AI::parse( $address, $di );
			if ( is_array( $ai ) ) {
				$source = 'ai';
				if ( null === $di && '' !== $ai['district'] ) {
					$x = BDSA_Geo::resolve_district( $ai['district'] );
					if ( null !== $x ) {
						$di = $x;
					}
				}
				if ( null !== $di && '' !== $ai['thana'] && ( 'high' !== $r['confidence'] || null === $ti ) ) {
					$x = BDSA_Geo::resolve_thana( $di, $ai['thana'] );
					if ( null !== $x ) {
						$ti = $x;
					}
				}
				if ( '' !== $ai['refined'] ) {
					$refined = $ai['refined'];
				}
			}
		}

		$resp = array(
			'ok'         => true,
			'district'   => null,
			'thana'      => null,
			'refined'    => $refined,
			'full'       => BDSA_Geo::compose( $refined, $di, $ti, $address ),
			'confidence' => $r['confidence'],
			'source'     => $source,
			'delivery'   => BDSA_Delivery::enabled() ? BDSA_Delivery::quote( $di, $ti ) : null,
		);
		if ( null !== $di && ( $d = BDSA_Geo::district( $di ) ) ) {
			$resp['district'] = array(
				'n' => $d['n'],
				'b' => $d['b'],
			);
			if ( null !== $ti && ( $u = BDSA_Geo::thana( $di, $ti ) ) ) {
				$resp['thana'] = array(
					'n' => $u[0],
					'b' => $u[1],
				);
			}
		}

		set_transient( $ckey, $resp, DAY_IN_SECONDS );
		return rest_ensure_response( $resp );
	}
}
