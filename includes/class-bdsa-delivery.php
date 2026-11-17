<?php
/**
 * Automatic delivery charge by district / thana.
 *
 * One WooCommerce shipping rate is built from the customer's address:
 *   Dhaka city           -> fee_inside   (default 60)
 *   Sub-urban Dhaka      -> fee_suburb   (default 100)  Savar, Keraniganj, Dhamrai, Nawabganj, Dohar,
 *                                                       Gazipur and Narayanganj city areas (editable)
 *   everywhere else      -> fee_outside  (default 120)
 * By default the charge is locked: the customer sees one charge and cannot change it (a WooCommerce
 * free-shipping rule, e.g. "free over 1000", still applies when it is met).
 * Because it is a normal shipping rate, it shows in the order review, the order,
 * invoices and emails exactly like any other shipping charge.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'BDSA_TESTING' ) ) {
	exit;
}

class BDSA_Delivery {

	const METHOD_ID = 'bdsa_delivery';

	public static function init() {
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'rates' ), 100, 2 );
		add_filter( 'woocommerce_customer_default_location_array', array( __CLASS__, 'default_location' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'price_note' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'top_note' ), 20 );
	}

	/**
	 * Plain-language price list, shown BEFORE the customer picks an address so the charge never comes as a surprise.
	 */
	private static function price_list() {
		$z = array( 'inside', 'suburb', 'outside' );
		$o = array();
		foreach ( $z as $k ) {
			$f = self::fee( $k );
			$o[] = '<span class="bdsa-pl-i">' . esc_html( self::label( $k ) ) . ': <strong>' . ( $f > 0 ? wp_kses_post( wc_price( $f ) ) : esc_html__( 'Free', 'woocommerce' ) ) . '</strong></span>';
		}
		return implode( ' <span class="bdsa-pl-s">|</span> ', $o );
	}

	public static function top_note() {
		if ( ! self::enabled() || ! BDSA_Plugin::get( 'delivery_note' ) ) {
			return;
		}
		echo '<div class="bdsa-topnote">🚚 <b>ডেলিভারি চার্জ:</b> ' . self::price_list() . ' <small>— ঠিকানা দিলে আপনার চার্জ নিচে নিজে থেকে বসে যাবে।</small></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Our own always-visible "Delivery charge" row in the order review (right above the total).
	 * Themes / checkout builders often hide or re-style the native shipping row, which leaves
	 * Subtotal -> Total with an unexplained jump. This row cannot be hidden by them.
	 */
	public static function price_note() {
		if ( ! self::enabled() || ! BDSA_Plugin::get( 'lock_delivery' ) || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return;
		}
		$pkg  = WC()->shipping() ? WC()->shipping()->get_packages() : array();
		$dest = ( $pkg && isset( $pkg[0]['destination'] ) ) ? $pkg[0]['destination'] : array();
		$c    = self::classify( $dest );
		if ( null === $c['di'] ) {
			echo '<tr class="bdsa-fee-row bdsa-fee-pending"><th>ডেলিভারি চার্জ</th><td>ঠিকানা দিলে ঠিক হবে<div class="bdsa-fee-list">' . self::price_list() . '</div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		$fee = (float) WC()->cart->get_shipping_total();
		echo '<tr class="bdsa-fee-row"><th>ডেলিভারি চার্জ</th><td>' . ( $fee > 0 ? wp_kses_post( wc_price( $fee ) ) : 'ফ্রি' ) . '<div class="bdsa-fee-zone">' . esc_html( self::label( $c['zone'] ) ) . '</div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function enabled() {
		return (bool) BDSA_Plugin::get( 'enabled' ) && (bool) BDSA_Plugin::get( 'delivery_enabled' );
	}

	/**
	 * Changes whenever a setting that affects the charge changes (used to bust the REST cache).
	 */
	public static function signature() {
		return md5(
			wp_json_encode(
				array(
					(int) self::enabled(),
					(float) BDSA_Plugin::get( 'fee_inside' ),
					(float) BDSA_Plugin::get( 'fee_suburb' ),
					(float) BDSA_Plugin::get( 'fee_outside' ),
					(string) BDSA_Plugin::get( 'label_inside' ),
					(string) BDSA_Plugin::get( 'label_suburb' ),
					(string) BDSA_Plugin::get( 'label_outside' ),
					(string) BDSA_Plugin::get( 'extra_inside' ),
					(string) BDSA_Plugin::get( 'suburb_extra' ),
					(string) BDSA_Plugin::get( 'dhaka_unknown' ),
				)
			)
		);
	}


	/* ---------- Zones and prices ---------- */

	private static function extra_inside() {
		return array_filter( array_map( 'trim', explode( ',', (string) BDSA_Plugin::get( 'extra_inside' ) ) ) );
	}

	/**
	 * "District" or "District:Thana" entries from the sub-urban list.
	 *
	 * @return array[] Each { d: normalised district, t: normalised thana or '' }.
	 */
	private static function suburb_list() {
		$out = array();
		foreach ( explode( ',', (string) BDSA_Plugin::get( 'suburb_extra' ) ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ':', $item, 2 ) );
			$out[] = array(
				'd' => str_replace( ' ', '', BDSA_Geo::norm( $parts[0] ) ),
				't' => isset( $parts[1] ) ? str_replace( ' ', '', BDSA_Geo::norm( $parts[1] ) ) : '',
			);
		}
		return $out;
	}

	private static function is_suburb_listed( $di, $ti ) {
		$d = BDSA_Geo::district( $di );
		if ( ! $d ) {
			return false;
		}
		$dn = str_replace( ' ', '', BDSA_Geo::norm( $d['n'] ) );
		$tn = '';
		if ( null !== $ti && ( $u = BDSA_Geo::thana( $di, $ti ) ) ) {
			$tn = str_replace( ' ', '', BDSA_Geo::norm( $u[0] ) );
		}
		foreach ( self::suburb_list() as $e ) {
			if ( $e['d'] !== $dn ) {
				continue;
			}
			if ( '' === $e['t'] || ( '' !== $tn && $e['t'] === $tn ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int|null $di District index.
	 * @param int|null $ti Thana index.
	 * @return string inside | suburb | outside
	 */
	public static function zone( $di, $ti ) {
		if ( null === $di ) {
			return 'outside'; // nothing known yet: the highest fee, never under-charge.
		}
		if ( BDSA_Geo::dhaka_index() === $di ) {
			if ( null === $ti ) {
				$u = (string) BDSA_Plugin::get( 'dhaka_unknown' );
				return in_array( $u, array( 'suburb', 'outside' ), true ) ? $u : 'inside';
			}
			if ( BDSA_Geo::is_dhaka_city( $di, $ti, self::extra_inside() ) ) {
				return 'inside';
			}
			return 'suburb'; // Savar, Keraniganj, Dhamrai, Nawabganj, Dohar.
		}
		return self::is_suburb_listed( $di, $ti ) ? 'suburb' : 'outside';
	}

	public static function fee( $zone ) {
		$key = 'inside' === $zone ? 'fee_inside' : ( 'suburb' === $zone ? 'fee_suburb' : 'fee_outside' );
		return max( 0.0, (float) BDSA_Plugin::get( $key ) );
	}

	public static function label( $zone ) {
		$key = 'inside' === $zone ? 'label_inside' : ( 'suburb' === $zone ? 'label_suburb' : 'label_outside' );
		return (string) BDSA_Plugin::get( $key );
	}

	/**
	 * For the checkout address box. Null until a district is known.
	 *
	 * @return array|null { zone, fee, label }
	 */
	public static function quote( $di, $ti ) {
		if ( null === $di ) {
			return null;
		}
		$zone = self::zone( $di, $ti );
		return array(
			'zone'  => $zone,
			'fee'   => self::fee( $zone ),
			'label' => self::label( $zone ),
		);
	}

	/**
	 * Where does the customer live? Uses the district / thana fields and falls back
	 * to reading the address text, so the charge is right even if a field is empty.
	 * A district the customer chose always beats what the address text says.
	 *
	 * @param array $dest WooCommerce package destination.
	 * @return array { zone, di, ti }
	 */
	public static function classify( $dest ) {
		$dest    = is_array( $dest ) ? $dest : array();
		$country = isset( $dest['country'] ) ? (string) $dest['country'] : '';
		if ( '' !== $country && 'BD' !== $country ) {
			return array(
				'zone' => 'outside',
				'di'   => null,
				'ti'   => null,
			);
		}

		$state = isset( $dest['state'] ) ? trim( (string) $dest['state'] ) : '';
		$city  = isset( $dest['city'] ) ? trim( (string) $dest['city'] ) : '';
		$addr  = '';
		if ( ! empty( $dest['address_1'] ) ) {
			$addr = trim( (string) $dest['address_1'] );
		} elseif ( ! empty( $dest['address'] ) ) {
			$addr = trim( (string) $dest['address'] );
		}

		$di = null;
		$ti = null;
		if ( '' !== $state ) {
			$name = $state;
			if ( function_exists( 'WC' ) && WC()->countries ) {
				$states = WC()->countries->get_states( 'BD' );
				if ( is_array( $states ) && isset( $states[ $state ] ) ) {
					$name = $states[ $state ];
				}
			}
			$di = BDSA_Geo::district_from_wc_state( $name );
		}
		if ( null !== $di && '' !== $city ) {
			$ti = BDSA_Geo::resolve_thana( $di, $city );
		}
		if ( '' !== $addr && ( null === $di || null === $ti ) ) {
			$r = BDSA_Geo::analyze( $addr );
			if ( null === $di ) {
				$di = $r['district'];
				$ti = $r['thana'];
			} elseif ( $r['district'] === $di ) {
				$ti = $r['thana'];
			}
		}

		return array(
			'zone' => self::zone( $di, $ti ),
			'di'   => $di,
			'ti'   => $ti,
		);
	}

	/* ---------- WooCommerce hooks ---------- */

	/**
	 * Replace the normal shipping options with the automatic delivery charge.
	 *
	 * @param array $rates   WC_Shipping_Rate[] keyed by rate id.
	 * @param array $package WooCommerce shipping package.
	 */
	public static function rates( $rates, $package ) {
		if ( ! self::enabled() || ! is_array( $rates ) ) {
			return $rates;
		}
		$dest    = ( is_array( $package ) && isset( $package['destination'] ) && is_array( $package['destination'] ) ) ? $package['destination'] : array();
		$country = isset( $dest['country'] ) ? (string) $dest['country'] : '';
		if ( '' !== $country && 'BD' !== $country ) {
			return $rates; // not a Bangladesh delivery: leave WooCommerce alone.
		}

		$lock = (bool) BDSA_Plugin::get( 'lock_delivery' );
		$free = null;
		$keep = array();
		foreach ( $rates as $id => $rate ) {
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_method_id' ) ) {
				continue;
			}
			$mid = $rate->get_method_id();
			if ( 'free_shipping' === $mid && null === $free ) {
				$free = array( $id, $rate );
			}
			if ( ! $lock && in_array( $mid, array( 'free_shipping', 'local_pickup', 'pickup_location' ), true ) ) {
				$keep[ $id ] = $rate;
			}
		}

		$c = self::classify( $dest );

		// Locked: the customer sees ONE charge, picked from the delivery place. It cannot be changed.
		// A free-shipping rule set up in WooCommerce (for example "free over 1000") still wins when it applies.
		if ( $lock && null !== $free && BDSA_Plugin::get( 'respect_free' ) ) {
			$free[1]->add_meta_data( 'bdsa_zone', $c['zone'] );
			return array( $free[0] => $free[1] );
		}

		$lbl  = self::label( $c['zone'] );
		if ( null === $c['di'] && BDSA_Plugin::get( 'delivery_note' ) ) {
			$lbl = 'ডেলিভারি (ঠিকানা দিলে ঠিক হবে)';
		}
		$rate = new WC_Shipping_Rate( self::METHOD_ID, $lbl, self::fee( $c['zone'] ), array(), self::METHOD_ID );
		$rate->add_meta_data( 'bdsa_zone', $c['zone'] );
		return array( self::METHOD_ID => $rate ) + $keep;
	}

	/**
	 * WooCommerce pre-selects the shop's base district for guests (usually Dhaka), which would
	 * show the cheap fee before the customer has said where they live. Start with no district.
	 */
	public static function default_location( $location ) {
		if ( self::enabled() && is_array( $location ) && isset( $location['country'] ) && 'BD' === $location['country'] ) {
			$location['state'] = '';
		}
		return $location;
	}
}
