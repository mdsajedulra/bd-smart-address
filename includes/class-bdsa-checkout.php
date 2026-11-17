<?php
/**
 * Checkout integration: fields, Bangla labels, assets, validation.
 * Works with the classic WooCommerce checkout (including CartFlows checkout steps).
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'BDSA_TESTING' ) ) {
	exit;
}

class BDSA_Checkout {

	private static $loaded = null;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ), 9999 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'locale' ), 20 );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'locale_labels' ), 30 );
		add_filter( 'woocommerce_states', array( __CLASS__, 'bn_states' ), 30 );
		add_filter( 'gettext', array( __CLASS__, 'gettext' ), 20, 3 );
		add_filter( 'gettext_with_context', array( __CLASS__, 'gettext_ctx' ), 20, 4 );
	}

	/**
	 * True when the shop sells to Bangladesh only.
	 */
	public static function bd_only() {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return false;
		}
		$allowed = WC()->countries->get_allowed_countries();
		return (bool) apply_filters( 'bdsa_bd_only', 1 === count( $allowed ) && isset( $allowed['BD'] ) );
	}

	/**
	 * Local-customer mode for one address group. Checkout builders (CartFlows) often hide the
	 * country field; then customers are local and the shop's base country decides.
	 */
	private static function bd_mode( $group_fields, $g ) {
		if ( self::bd_only() ) {
			return true;
		}
		if ( ! isset( $group_fields[ $g . '_country' ] ) ) {
			return 0 === strpos( (string) get_option( 'woocommerce_default_country' ), 'BD' );
		}
		return false;
	}

	private static function should_load() {
		if ( null !== self::$loaded ) {
			return self::$loaded;
		}
		if ( ! BDSA_Plugin::get( 'enabled' ) ) {
			return false;
		}
		$load = false;
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$load = true;
		} elseif ( is_singular( 'cartflows_step' ) ) {
			$load = true;
		} elseif ( is_singular() ) {
			$post = get_post();
			if ( $post && ( has_shortcode( $post->post_content, 'woocommerce_checkout' ) || has_shortcode( $post->post_content, 'cartflows_checkout' ) ) ) {
				$load = true;
			}
		}
		self::$loaded = (bool) apply_filters( 'bdsa_should_load', $load );
		return self::$loaded;
	}

	public static function body_class( $classes ) {
		if ( did_action( 'wp' ) && self::should_load() ) {
			$classes[] = 'bdsa-on';
			if ( BDSA_Plugin::get( 'delivery_enabled' ) && BDSA_Plugin::get( 'lock_delivery' ) ) {
				$classes[] = 'bdsa-lock';
			}
		}
		return $classes;
	}

	/**
	 * Bangladesh: WooCommerce marks the city (our thana) as required. When the thana is optional, say so here too.
	 */
	public static function locale( $locales ) {
		if ( BDSA_Plugin::get( 'enabled' ) && BDSA_Plugin::get( 'thana_optional' ) && is_array( $locales ) ) {
			if ( ! isset( $locales['BD'] ) || ! is_array( $locales['BD'] ) ) {
				$locales['BD'] = array();
			}
			$locales['BD']['city'] = ( isset( $locales['BD']['city'] ) && is_array( $locales['BD']['city'] ) ? $locales['BD']['city'] : array() ) + array();
			$locales['BD']['city']['required'] = false;
		}
		return $locales;
	}

	/**
	 * WooCommerce's address script re-labels and re-orders the address fields from the country locale
	 * right after the page loads. Mirror our final labels, placeholders and order there so it does not undo them.
	 */
	public static function locale_labels( $locales ) {
		static $busy = false;
		if ( $busy || is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'WC' ) || ! WC()->checkout() ) {
			return $locales;
		}
		$busy = true;
		$f    = WC()->checkout()->get_checkout_fields( 'billing' );
		$busy = false;
		foreach ( array( 'first_name', 'phone', 'address_1', 'state', 'city' ) as $k ) {
			if ( empty( $f[ 'billing_' . $k ] ) ) {
				continue;
			}
			// Same label/placeholder tweaks the checkout form applies when it draws the field.
			$field = apply_filters( 'woocommerce_form_field_args', $f[ 'billing_' . $k ], 'billing_' . $k, null );
			foreach ( array( 'label', 'placeholder', 'priority' ) as $p ) {
				if ( isset( $field[ $p ] ) ) {
					$locales['BD'][ $k ][ $p ] = $field[ $p ];
				}
			}
		}
		return $locales;
	}

	public static function strings() {
		$bn = BDSA_Plugin::bn();
		$en = array(
			'loading'    => 'Checking address…',
			'title'      => 'Cleaned-up address',
			'use'        => 'Use this address',
			'district'   => 'District',
			'thana'      => 'Thana',
			'thana_full' => 'Thana / Upazila',
			'auto'       => 'Filled in automatically. Change it if it is wrong.',
			'select'     => 'Select thana / upazila',
			'unknown'    => 'I do not know / not sure',
			'close'      => 'Close',
			'delivery'   => 'Delivery charge',
			'address_ph' => 'House / flat, road, area — write the full address',
		);
		$bd = array(
			'loading'    => 'ঠিকানা যাচাই করা হচ্ছে…',
			'title'      => 'পরিমার্জিত ঠিকানা',
			'use'        => 'এই ঠিকানা ব্যবহার করুন',
			'district'   => 'জেলা',
			'thana'      => 'থানা',
			'thana_full' => 'থানা / উপজেলা',
			'auto'       => 'স্বয়ংক্রিয়ভাবে বসানো হয়েছে। ভুল হলে নিজে বদলে নিন।',
			'select'     => 'থানা / উপজেলা নির্বাচন করুন',
			'unknown'    => 'জানি না / নিশ্চিত নই',
			'close'      => 'বন্ধ করুন',
			'delivery'   => 'ডেলিভারি চার্জ',
			'address_ph' => 'বাড়ি/ফ্ল্যাট, রোড, এলাকা — পুরো ঠিকানা লিখুন',
		);
		return apply_filters( 'bdsa_strings', $bn ? $bd : $en );
	}

	public static function assets() {
		if ( ! self::should_load() ) {
			return;
		}
		wp_enqueue_style( 'bdsa', BDSA_URL . 'assets/bdsa.css', array(), BDSA_VERSION );
		if ( BDSA_Plugin::get( 'style' ) ) {
			wp_enqueue_style( 'bdsa-theme', BDSA_URL . 'assets/bdsa-theme.css', array( 'bdsa' ), BDSA_VERSION );
			$accent = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( (string) BDSA_Plugin::get( 'accent' ) ) : null;
			if ( $accent ) {
				wp_add_inline_style( 'bdsa-theme', 'body.bdsa-on{--bdsa-accent:' . $accent . ';}' );
			}
		}
		wp_register_script( 'bdsa', BDSA_URL . 'assets/bdsa.js', array( 'jquery' ), BDSA_VERSION, true );
		wp_localize_script(
			'bdsa',
			'BDSA',
			array(
				'rest'          => esc_url_raw( rest_url( 'bdsa/v1/parse' ) ),
				'minChars'      => (int) BDSA_Plugin::get( 'min_chars' ),
				'debounce'      => (int) BDSA_Plugin::get( 'debounce' ),
				'showBox'       => (bool) BDSA_Plugin::get( 'show_box' ),
				// Only has an effect when the server turned the Thana field into a <select>.
				'thanaDropdown' => (bool) BDSA_Plugin::get( 'thana_dropdown' ),
				'geo'           => BDSA_Geo::frontend_map(),
				'i18n'          => self::strings(),
				'bn'            => BDSA_Plugin::bn(),
			)
		);
		wp_enqueue_script( 'bdsa' );
	}

	/* ---------- Fields ---------- */

	public static function fields( $fields ) {
		if ( ! BDSA_Plugin::get( 'enabled' ) || ! is_array( $fields ) ) {
			return $fields;
		}
		$s    = self::strings();
		$lean = (bool) BDSA_Plugin::get( 'lean_checkout' );

		foreach ( array( 'billing', 'shipping' ) as $g ) {
			if ( empty( $fields[ $g ] ) || ! is_array( $fields[ $g ] ) || ! isset( $fields[ $g ][ $g . '_address_1' ] ) ) {
				continue;
			}
			$bd = self::bd_mode( $fields[ $g ], $g );

			if ( $lean ) {
				foreach ( array( 'company', 'postcode', 'address_2', 'last_name' ) as $k ) {
					if ( in_array( self::field_cfg( $k, 'mode' ), array( 'required', 'optional' ), true ) ) {
						continue; // the owner asked for this field in the dashboard.
					}
					unset( $fields[ $g ][ $g . '_' . $k ] );
				}
			}

			// Builders such as CartFlows can switch District / Thana off. Bring them back.
			if ( 'billing' === $g && BDSA_Plugin::get( 'ensure_fields' ) && $bd ) {
				if ( ! isset( $fields[ $g ]['billing_state'] ) ) {
					$fields[ $g ]['billing_state'] = array(
						'type'          => 'state',
						'label'         => $s['district'],
						'required'      => true,
						'class'         => array( 'form-row-wide', 'address-field', 'update_totals_on_change' ),
						'validate'      => array( 'state' ),
						'autocomplete'  => 'address-level1',
						'priority'      => 80,
						'country_field' => 'billing_country',
						'country'       => 'BD',
					);
				}
				if ( ! isset( $fields[ $g ]['billing_city'] ) ) {
					$fields[ $g ]['billing_city'] = array(
						'label'        => $s['thana_full'],
						'required'     => ! BDSA_Plugin::get( 'thana_optional' ),
						'class'        => array( 'form-row-wide', 'address-field', 'update_totals_on_change' ),
						'autocomplete' => 'address-level2',
						'priority'     => 90,
					);
				}
			}

			if ( isset( $fields[ $g ][ $g . '_state' ] ) ) {
				$fields[ $g ][ $g . '_state' ]['label']    = $s['district'];
				$fields[ $g ][ $g . '_state' ]['required'] = true;
				$cls                                       = (array) ( isset( $fields[ $g ][ $g . '_state' ]['class'] ) ? $fields[ $g ][ $g . '_state' ]['class'] : array() );
				$fields[ $g ][ $g . '_state' ]['class']    = array_values( array_unique( array_merge( $cls, array( 'update_totals_on_change' ) ) ) );
			}

			if ( $bd && BDSA_Plugin::get( 'thana_dropdown' ) && isset( $fields[ $g ][ $g . '_city' ] ) ) {
				$cls = (array) ( isset( $fields[ $g ][ $g . '_city' ]['class'] ) ? $fields[ $g ][ $g . '_city' ]['class'] : array() );
				$fields[ $g ][ $g . '_city' ]['type']         = 'select';
				$fields[ $g ][ $g . '_city' ]['label']        = $s['thana_full'];
				$fields[ $g ][ $g . '_city' ]['required']     = ! BDSA_Plugin::get( 'thana_optional' );
				$fields[ $g ][ $g . '_city' ]['options']      = array( '' => BDSA_Plugin::get( 'thana_optional' ) ? $s['unknown'] : $s['select'] ) + BDSA_Geo::thana_options( BDSA_Plugin::bn() );
				$fields[ $g ][ $g . '_city' ]['placeholder']  = $s['select'];
				$fields[ $g ][ $g . '_city' ]['autocomplete'] = 'off';
				$fields[ $g ][ $g . '_city' ]['class']        = array_values( array_unique( array_merge( $cls, array( 'update_totals_on_change' ) ) ) );
			} elseif ( isset( $fields[ $g ][ $g . '_city' ] ) && $bd ) {
				$fields[ $g ][ $g . '_city' ]['label'] = $s['thana_full'];
			}

			if ( isset( $fields[ $g ][ $g . '_address_1' ] ) && empty( $fields[ $g ][ $g . '_address_1' ]['placeholder'] ) ) {
				$fields[ $g ][ $g . '_address_1' ]['placeholder'] = $s['address_ph'];
			}
			if ( 'billing' === $g && BDSA_Plugin::get( 'phone_required' ) && isset( $fields[ $g ]['billing_phone'] ) ) {
				$fields[ $g ]['billing_phone']['required'] = true;
				if ( isset( $fields[ $g ]['billing_phone']['class'] ) ) {
					$fields[ $g ]['billing_phone']['class'] = array_values( array_diff( (array) $fields[ $g ]['billing_phone']['class'], array( 'optional' ) ) );
				}
			}
			// Some builders (CartFlows) switch the email field off. Bring it back, optional.
			if ( 'billing' === $g && BDSA_Plugin::get( 'show_email' ) && ! isset( $fields[ $g ]['billing_email'] ) ) {
				$fields[ $g ]['billing_email'] = array(
					'label'        => __( 'Email address', 'woocommerce' ),
					'required'     => false,
					'type'         => 'email',
					'class'        => array( 'form-row-wide' ),
					'validate'     => array( 'email' ),
					'autocomplete' => 'email username',
					'placeholder'  => 'name@example.com',
					'priority'     => 30,
				);
			}
			if ( 'billing' === $g && BDSA_Plugin::get( 'email_optional' ) && isset( $fields[ $g ]['billing_email'] ) ) {
				$fields[ $g ]['billing_email']['required'] = false;
			}
			if ( isset( $fields[ $g ][ $g . '_phone' ] ) ) {
				if ( empty( $fields[ $g ][ $g . '_phone' ]['placeholder'] ) ) {
					$fields[ $g ][ $g . '_phone' ]['placeholder'] = '01XXXXXXXXX';
				}
				$attrs = isset( $fields[ $g ][ $g . '_phone' ]['custom_attributes'] ) ? (array) $fields[ $g ][ $g . '_phone' ]['custom_attributes'] : array();
				$fields[ $g ][ $g . '_phone' ]['custom_attributes'] = $attrs + array( 'inputmode' => 'tel' );
			}

			// One clear order: name, phone, email, address, district, thana. Everything else keeps its place after them.
			if ( BDSA_Plugin::get( 'field_order' ) || $lean ) {
				$order = array(
					$g . '_first_name' => 10,
					$g . '_phone'      => 20,
					$g . '_email'      => 30,
					$g . '_country'    => 35,
					$g . '_address_1'  => 40,
					$g . '_state'      => 50,
					$g . '_city'       => 60,
				);
				foreach ( $order as $key => $prio ) {
					if ( isset( $fields[ $g ][ $key ] ) ) {
						$fields[ $g ][ $key ]['priority'] = $prio;
					}
				}
				foreach ( array( 'last_name', 'company', 'address_2', 'postcode' ) as $k ) {
					if ( isset( $fields[ $g ][ $g . '_' . $k ] ) ) {
						$fields[ $g ][ $g . '_' . $k ]['priority'] = 70 + (int) array_search( $k, array( 'last_name', 'company', 'address_2', 'postcode' ), true );
					}
				}
			}
		}
		return self::apply_field_config( $fields );
	}

	/** Field keys the owner can set up in the dashboard (key => English name). */
	public static function field_keys() {
		return array(
			'first_name'     => 'Name',
			'last_name'      => 'Last name',
			'phone'          => 'Phone',
			'email'          => 'Email',
			'address_1'      => 'Address',
			'address_2'      => 'Address line 2',
			'state'          => 'District',
			'city'           => 'Thana / Upazila',
			'postcode'       => 'Postcode',
			'company'        => 'Company',
			'country'        => 'Country',
			'order_comments' => 'Order notes',
		);
	}

	private static function field_cfg( $key, $what ) {
		$all = BDSA_Plugin::get( 'fields' );
		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) || ! isset( $all[ $key ][ $what ] ) ) {
			return '';
		}
		return $all[ $key ][ $what ];
	}

	/**
	 * The owner's own checkout field setup (Settings > BD Smart Address > Checkout fields):
	 * show / hide, required / optional, label and order. Runs last, so it wins over everything else.
	 */
	private static function apply_field_config( $fields ) {
		$all = BDSA_Plugin::get( 'fields' );
		if ( ! is_array( $all ) || ! $all ) {
			return $fields;
		}
		foreach ( self::field_keys() as $key => $name ) {
			$mode  = (string) self::field_cfg( $key, 'mode' );
			$label = trim( (string) self::field_cfg( $key, 'label' ) );
			$order = (int) self::field_cfg( $key, 'order' );
			if ( '' === $mode && '' === $label && ! $order ) {
				continue;
			}
			$targets = array();
			if ( 'order_comments' === $key ) {
				$targets[] = array( 'order', 'order_comments' );
			} else {
				foreach ( array( 'billing', 'shipping' ) as $g ) {
					$targets[] = array( $g, $g . '_' . $key );
				}
			}
			foreach ( $targets as $t ) {
				list( $g, $id ) = $t;
				if ( ! isset( $fields[ $g ][ $id ] ) ) {
					continue;
				}
				if ( 'hidden' === $mode ) {
					unset( $fields[ $g ][ $id ] );
					continue;
				}
				if ( 'required' === $mode || 'optional' === $mode ) {
					$fields[ $g ][ $id ]['required'] = ( 'required' === $mode );
					if ( isset( $fields[ $g ][ $id ]['class'] ) ) {
						$fields[ $g ][ $id ]['class'] = array_values( array_diff( (array) $fields[ $g ][ $id ]['class'], array( 'optional' ) ) );
					}
				}
				if ( '' !== $label ) {
					$fields[ $g ][ $id ]['label'] = $label;
				}
				if ( $order > 0 ) {
					$fields[ $g ][ $id ]['priority'] = $order;
				}
			}
		}
		return $fields;
	}

	/**
	 * Show Bangla district names in the checkout district list (values / codes stay the same).
	 */
	public static function bn_states( $states ) {
		if ( ! self::should_translate() || empty( $states['BD'] ) || ! is_array( $states['BD'] ) ) {
			return $states;
		}
		foreach ( $states['BD'] as $code => $name ) {
			$di = BDSA_Geo::district_from_wc_state( $name );
			if ( null === $di ) {
				continue;
			}
			$d = BDSA_Geo::district( $di );
			if ( is_array( $d ) && ! empty( $d['b'] ) ) {
				$states['BD'][ $code ] = $d['b'];
			}
		}
		return $states;
	}

	/* ---------- Bangla labels ---------- */

	private static function should_translate() {
		if ( ! BDSA_Plugin::get( 'enabled' ) || ! BDSA_Plugin::bn() ) {
			return false;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}
		if ( function_exists( 'get_locale' ) && 0 === strpos( (string) get_locale(), 'bn' ) ) {
			return false; // a real Bangla translation is already active.
		}
		return true;
	}

	private static function map() {
		return apply_filters(
			'bdsa_bangla_labels',
			array(
				'Billing details'  => 'আপনার তথ্য',
				'Your order'       => 'আপনার অর্ডার',
				'Product'          => 'পণ্য',
				'Subtotal'         => 'সাবটোটাল',
				'Shipping'         => 'ডেলিভারি চার্জ',
				'Total'            => 'সর্বমোট',
				'Order notes'      => 'অর্ডার নোট',
				'(optional)'       => '(ঐচ্ছিক)',
				'optional'         => 'ঐচ্ছিক',
				'Email address'    => 'ইমেইল',
				'Notes about your order, e.g. special notes for delivery.' => 'ডেলিভারি নিয়ে কোনো বিশেষ নির্দেশনা থাকলে লিখুন',
				'Place order'      => 'অর্ডার কনফার্ম করুন',
				'Place Order'      => 'অর্ডার কনফার্ম করুন',
				'Shipping to %s.'  => 'ডেলিভারি হবে: %s।',
				'%s is a required field.' => '%s পূরণ করা আবশ্যক।',
				'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.' => 'এই ঠিকানায় ডেলিভারি অপশন পাওয়া যায়নি। ঠিকানা ঠিকমতো দিয়েছেন কিনা দেখুন, অথবা আমাদের সাথে যোগাযোগ করুন।',
			)
		);
	}

	public static function gettext( $translation, $text, $domain ) {
		if ( ! in_array( $domain, array( 'woocommerce', 'cartflows', 'cartflows-pro' ), true ) || ! self::should_translate() ) {
			return $translation;
		}
		$m = self::map();
		return isset( $m[ $text ] ) ? $m[ $text ] : $translation;
	}

	public static function gettext_ctx( $translation, $text, $context, $domain ) {
		if ( 'woocommerce' === $domain && 'checkout-validation' === $context && ( 'Billing %s' === $text || 'Shipping %s' === $text ) && self::should_translate() ) {
			return '%s'; // "Billing জেলা পূরণ..." reads badly; the field name alone is clear.
		}
		return $translation;
	}

	/* ---------- Validation ---------- */

	private static function ascii_digits( $s ) {
		return strtr(
			(string) $s,
			array(
				'০' => '0',
				'১' => '1',
				'২' => '2',
				'৩' => '3',
				'৪' => '4',
				'৫' => '5',
				'৬' => '6',
				'৭' => '7',
				'৮' => '8',
				'৯' => '9',
			)
		);
	}

	/**
	 * Optional: require a valid Bangladeshi mobile number (01XXXXXXXXX).
	 */
	public static function validate( $data, $errors ) {
		if ( ! BDSA_Plugin::get( 'enabled' ) || ! BDSA_Plugin::get( 'validate_phone' ) || empty( $data['billing_phone'] ) ) {
			return;
		}
		$country = isset( $data['billing_country'] ) ? $data['billing_country'] : 'BD';
		if ( 'BD' !== $country ) {
			return;
		}
		$p = preg_replace( '/[\s\-().]+/', '', self::ascii_digits( $data['billing_phone'] ) );
		$p = preg_replace( '/^(?:\+?88)/', '', $p );
		if ( ! preg_match( '/^01[3-9][0-9]{8}$/', $p ) ) {
			$errors->add( 'bdsa_phone', BDSA_Plugin::t( 'Please enter a valid mobile number (for example 017XXXXXXXX).', 'সঠিক মোবাইল নম্বর দিন (যেমন: 017XXXXXXXX)।' ) );
		}
	}
}
