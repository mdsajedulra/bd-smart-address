<?php
/**
 * Keeps BOTH addresses on the order: the one the customer typed and the cleaned-up one the
 * system suggested (with district and thana). They show on the admin order screen and in the
 * new-order email to the shop owner. The customer's own billing address is never changed.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'BDSA_TESTING' ) ) {
	exit;
}

class BDSA_Order {

	const META_TYPED   = '_bdsa_typed_address';
	const META_REFINED = '_bdsa_refined_address';
	const META_ZONE    = '_bdsa_zone';
	const META_REVIEW  = '_bdsa_review';

	public static function init() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_box' ), 20, 1 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_box' ), 20, 4 );
	}

	private static function clean( $v, $max = 300 ) {
		$v = is_string( $v ) ? wp_unslash( $v ) : '';
		$v = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $v ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	/**
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Checkout data.
	 */
	public static function save( $order, $data ) {
		if ( ! BDSA_Plugin::get( 'enabled' ) || ! BDSA_Plugin::get( 'save_addresses' ) || ! is_object( $order ) ) {
			return;
		}
		// WooCommerce has already checked the checkout nonce before this action runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$typed   = isset( $_POST['bdsa_typed_address'] ) ? self::clean( $_POST['bdsa_typed_address'] ) : '';
		$refined = isset( $_POST['bdsa_refined_address'] ) ? self::clean( $_POST['bdsa_refined_address'] ) : '';
		// phpcs:enable

		$billing = self::clean( (string) $order->get_billing_address_1() );
		if ( '' === $typed ) {
			$typed = $billing;
		}

		// Browser did not send a cleaned-up address (script blocked, very fast checkout): build it here.
		if ( '' === $refined && '' !== $typed ) {
			$r       = BDSA_Geo::analyze( $typed );
			$refined = BDSA_Geo::compose( $r['refined'], $r['district'], $r['thana'], $typed );
		}

		// No thana chosen: the team should look at this address before it goes to the courier.
		if ( '' === trim( (string) $order->get_billing_city() ) ) {
			$order->update_meta_data( self::META_REVIEW, 'thana' );
			$order->add_order_note( 'ঠিকানা যাচাই করুন: কাস্টমার থানা/উপজেলা বাছেননি (জানি না)।' );
		}

		$order->update_meta_data( self::META_TYPED, $typed );
		if ( '' !== $refined ) {
			$order->update_meta_data( self::META_REFINED, $refined );
		}

		if ( BDSA_Delivery::enabled() ) {
			foreach ( $order->get_shipping_methods() as $item ) {
				$z = $item->get_meta( 'bdsa_zone' );
				if ( $z ) {
					$order->update_meta_data( self::META_ZONE, sanitize_key( $z ) );
					break;
				}
			}
		}
	}

	private static function zone_name( $zone ) {
		$map = array(
			'inside'  => BDSA_Plugin::get( 'label_inside' ),
			'suburb'  => BDSA_Plugin::get( 'label_suburb' ),
			'outside' => BDSA_Plugin::get( 'label_outside' ),
		);
		return isset( $map[ $zone ] ) ? (string) $map[ $zone ] : '';
	}

	public static function admin_box( $order ) {
		if ( ! is_object( $order ) ) {
			return;
		}
		$typed   = (string) $order->get_meta( self::META_TYPED );
		$refined = (string) $order->get_meta( self::META_REFINED );
		if ( '' === $typed && '' === $refined ) {
			return;
		}
		$review = (string) $order->get_meta( self::META_REVIEW );
		$zone = self::zone_name( (string) $order->get_meta( self::META_ZONE ) );
		echo '<div class="bdsa-order-addr" style="margin:14px 0 0;padding:10px 12px;border-left:4px solid #2e7d32;background:#f4faf5;">';
		if ( '' !== $review ) {
			echo '<p style="margin:0 0 8px;padding:6px 8px;background:#fff4e5;border-left:4px solid #e67e22;"><strong>' . esc_html__( 'Check the address: the customer did not choose a thana.', 'bd-smart-address' ) . '</strong></p>';
		}
		echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Address typed by the customer', 'bd-smart-address' ) . '</strong><br>' . esc_html( $typed ) . '</p>';
		echo '<p style="margin:0' . ( '' !== $zone ? ' 0 8px' : '' ) . ';"><strong>' . esc_html__( 'Cleaned-up address (system)', 'bd-smart-address' ) . '</strong><br>' . esc_html( $refined ) . '</p>';
		if ( '' !== $zone ) {
			echo '<p style="margin:0;"><strong>' . esc_html__( 'Delivery zone', 'bd-smart-address' ) . '</strong><br>' . esc_html( $zone ) . '</p>';
		}
		echo '</div>';
	}

	public static function email_box( $order, $sent_to_admin, $plain_text = false, $email = null ) {
		if ( ! $sent_to_admin || ! is_object( $order ) ) {
			return;
		}
		$typed   = (string) $order->get_meta( self::META_TYPED );
		$refined = (string) $order->get_meta( self::META_REFINED );
		if ( '' === $typed && '' === $refined ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . 'Address typed by the customer: ' . $typed . "\n" . 'Cleaned-up address (system): ' . $refined . "\n";
			return;
		}
		echo '<div style="margin:0 0 24px;padding:12px;border:1px solid #e5e5e5;">';
		if ( '' !== (string) $order->get_meta( self::META_REVIEW ) ) {
			echo '<p style="margin:0 0 8px;color:#b9560b;"><strong>' . esc_html__( 'Check the address: the customer did not choose a thana.', 'bd-smart-address' ) . '</strong></p>';
		}
		echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Address typed by the customer', 'bd-smart-address' ) . '</strong><br>' . esc_html( $typed ) . '</p>';
		echo '<p style="margin:0;"><strong>' . esc_html__( 'Cleaned-up address (system)', 'bd-smart-address' ) . '</strong><br>' . esc_html( $refined ) . '</p>';
		echo '</div>';
	}
}
