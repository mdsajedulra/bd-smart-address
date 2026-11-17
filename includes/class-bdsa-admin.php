<?php
/**
 * Settings page: Settings > BD Smart Address.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'BDSA_TESTING' ) ) {
	exit;
}

class BDSA_Admin {

	/**
	 * Lists OpenRouter models for the picker (public endpoint, no key needed). Cached 6 hours.
	 */
	public static function ajax_models() {
		check_ajax_referer( 'bdsa_models', 'n' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$list = get_transient( 'bdsa_or_models' );
		if ( ! is_array( $list ) ) {
			$resp = wp_remote_get(
				'https://openrouter.ai/api/v1/models',
				array(
					'timeout' => 20,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				wp_send_json_error( is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $resp ) );
			}
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			$list = array();
			if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
				foreach ( $body['data'] as $m ) {
					if ( empty( $m['id'] ) || ! is_string( $m['id'] ) ) {
						continue;
					}
					$out_mod = isset( $m['architecture']['output_modalities'] ) ? (array) $m['architecture']['output_modalities'] : array( 'text' );
					if ( ! in_array( 'text', $out_mod, true ) ) {
						continue;
					}
					$free = isset( $m['pricing']['prompt'], $m['pricing']['completion'] ) && 0.0 === (float) $m['pricing']['prompt'] && 0.0 === (float) $m['pricing']['completion'];
					$list[] = array(
						'id'   => $m['id'],
						'name' => isset( $m['name'] ) ? (string) $m['name'] : $m['id'],
						'ctx'  => isset( $m['context_length'] ) ? (int) $m['context_length'] : 0,
						'free' => $free ? 1 : 0,
					);
				}
				usort(
					$list,
					static function ( $a, $b ) {
						return array( $b['free'], $a['id'] ) <=> array( $a['free'], $b['id'] );
					}
				);
			}
			if ( $list ) {
				set_transient( 'bdsa_or_models', $list, 6 * HOUR_IN_SECONDS );
			}
		}
		wp_send_json_success( $list );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_bdsa_models', array( __CLASS__, 'ajax_models' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BDSA_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=bd-smart-address' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function menu() {
		add_options_page( 'BD Smart Address', 'BD Smart Address', 'manage_woocommerce', 'bd-smart-address', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'bdsa_group',
			BDSA_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => BDSA_Plugin::defaults(),
			)
		);
	}

	public static function sanitize( $in ) {
		$old = get_option( BDSA_Plugin::OPTION, array() );
		$old = is_array( $old ) ? $old : array();
		$d   = BDSA_Plugin::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		foreach ( array( 'enabled', 'show_box', 'thana_dropdown', 'ensure_fields', 'field_order', 'lean_checkout', 'validate_phone', 'style', 'delivery_enabled', 'lock_delivery', 'respect_free', 'save_addresses', 'phone_required', 'email_optional', 'show_email', 'thana_optional', 'ai_enabled' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}

		$ints = array(
			'min_chars'    => array( 6, 60 ),
			'debounce'     => array( 300, 5000 ),
			'ai_daily_cap' => array( 0, 100000 ),
			'ip_limit'     => array( 0, 10000 ),
			'ai_ip_limit'  => array( 0, 1000 ),
		);
		foreach ( $ints as $k => $range ) {
			$v         = isset( $in[ $k ] ) ? (int) $in[ $k ] : (int) $d[ $k ];
			$out[ $k ] = max( $range[0], min( $range[1], $v ) );
		}

		foreach ( array( 'fee_inside', 'fee_suburb', 'fee_outside' ) as $k ) {
			$v         = isset( $in[ $k ] ) && '' !== trim( (string) $in[ $k ] ) ? (float) $in[ $k ] : (float) $d[ $k ];
			$out[ $k ] = max( 0, min( 100000, $v ) );
		}
		foreach ( array( 'label_inside', 'label_suburb', 'label_outside' ) as $k ) {
			$v = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
			// Empty = use the default text of the chosen language.
			$out[ $k ] = in_array( $v, BDSA_Delivery::legacy_labels(), true ) ? '' : $v;
		}
		$out['language'] = ( isset( $in['language'] ) && 'bn' === $in['language'] ) ? 'bn' : 'en';

		$out['fields'] = array();
		$fin           = ( isset( $in['fields'] ) && is_array( $in['fields'] ) ) ? $in['fields'] : array();
		foreach ( array_keys( BDSA_Checkout::field_keys() ) as $fk ) {
			$row = ( isset( $fin[ $fk ] ) && is_array( $fin[ $fk ] ) ) ? $fin[ $fk ] : array();
			$m   = isset( $row['mode'] ) ? (string) $row['mode'] : '';
			$out['fields'][ $fk ] = array(
				'mode'  => in_array( $m, array( 'required', 'optional', 'hidden' ), true ) ? $m : '',
				'label' => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'order' => isset( $row['order'] ) ? max( 0, min( 999, (int) $row['order'] ) ) : 0,
			);
		}
		$out['extra_inside']  = isset( $in['extra_inside'] ) ? sanitize_text_field( $in['extra_inside'] ) : '';
		$out['suburb_extra']  = isset( $in['suburb_extra'] ) ? sanitize_text_field( $in['suburb_extra'] ) : $d['suburb_extra'];
		$out['dhaka_unknown'] = ( isset( $in['dhaka_unknown'] ) && in_array( $in['dhaka_unknown'], array( 'suburb', 'outside' ), true ) ) ? $in['dhaka_unknown'] : 'inside';

		$accent        = isset( $in['accent'] ) && function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $in['accent'] ) : null;
		$out['accent'] = $accent ? $accent : $d['accent'];

		$out['ai_when'] = ( isset( $in['ai_when'] ) && 'always' === $in['ai_when'] ) ? 'always' : 'fallback';

		$out['ai_provider'] = 'openrouter'; // OpenRouter is the only provider in the UI (one key, any model).

		$model        = isset( $in['model'] ) ? sanitize_text_field( $in['model'] ) : '';
		$out['model'] = '' !== $model ? $model : $d['model'];
		$gmodel              = isset( $in['gemini_model'] ) ? sanitize_text_field( $in['gemini_model'] ) : '';
		$out['gemini_model'] = '' !== $gmodel ? $gmodel : $d['gemini_model'];

		$omodel                  = isset( $in['openrouter_model'] ) ? sanitize_text_field( $in['openrouter_model'] ) : '';
		$out['openrouter_model'] = '' !== $omodel ? $omodel : $d['openrouter_model'];
		$okey                    = isset( $in['openrouter_key'] ) ? trim( sanitize_text_field( $in['openrouter_key'] ) ) : '';
		if ( ! empty( $in['clear_openrouter_key'] ) ) {
			$okey = '';
		} elseif ( '' === $okey ) {
			$okey = isset( $old['openrouter_key'] ) ? (string) $old['openrouter_key'] : '';
		}
		$out['openrouter_key'] = $okey;

		$gkey = isset( $in['gemini_key'] ) ? trim( sanitize_text_field( $in['gemini_key'] ) ) : '';
		if ( ! empty( $in['clear_gemini_key'] ) ) {
			$gkey = '';
		} elseif ( '' === $gkey ) {
			$gkey = isset( $old['gemini_key'] ) ? (string) $old['gemini_key'] : '';
		}
		$out['gemini_key'] = $gkey;

		// The key is never printed back. Empty input keeps the saved key.
		$key = isset( $in['api_key'] ) ? trim( sanitize_text_field( $in['api_key'] ) ) : '';
		if ( ! empty( $in['clear_api_key'] ) ) {
			$key = '';
		} elseif ( '' === $key ) {
			$key = isset( $old['api_key'] ) ? (string) $old['api_key'] : '';
		}
		$out['api_key'] = $key;

		// WooCommerce caches shipping rates per session; make a changed fee take effect at once.
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}

		return $out;
	}

	private static function checkbox( $name, $label, $desc = '' ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( BDSA_Plugin::OPTION ),
			esc_attr( $name ),
			checked( (int) BDSA_Plugin::get( $name ), 1, false ),
			esc_html( $label )
		);
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	private static function number( $name, $min, $max, $desc = '', $step = 1 ) {
		printf(
			'<input type="number" class="small-text" name="%1$s[%2$s]" value="%3$s" min="%4$s" max="%5$s" step="%6$s">',
			esc_attr( BDSA_Plugin::OPTION ),
			esc_attr( $name ),
			esc_attr( BDSA_Plugin::get( $name ) ),
			esc_attr( $min ),
			esc_attr( $max ),
			esc_attr( $step )
		);
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	private static function text( $name, $width = 'regular-text', $desc = '' ) {
		printf(
			'<input type="text" class="%1$s" name="%2$s[%3$s]" value="%4$s">',
			esc_attr( $width ),
			esc_attr( BDSA_Plugin::OPTION ),
			esc_attr( $name ),
			esc_attr( BDSA_Plugin::get( $name ) )
		);
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$opt       = BDSA_Plugin::OPTION;
		$has_const = defined( 'BDSA_ANTHROPIC_KEY' ) && BDSA_ANTHROPIC_KEY;
		$has_key   = '' !== (string) BDSA_Plugin::get( 'api_key' );
		$has_oconst = defined( 'BDSA_OPENROUTER_KEY' ) && BDSA_OPENROUTER_KEY;
		$has_okey   = '' !== (string) BDSA_Plugin::get( 'openrouter_key' );
		$has_gconst = defined( 'BDSA_GEMINI_KEY' ) && BDSA_GEMINI_KEY;
		$has_gkey   = '' !== (string) BDSA_Plugin::get( 'gemini_key' );
		?>
		<div class="wrap">
			<h1>BD Smart Address</h1>
			<p>Type a full address at checkout &rarr; the plugin shows a cleaned-up address, fills District and Thana, and sets the delivery charge automatically.</p>

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning"><p>WooCommerce is not active. This plugin needs WooCommerce.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'bdsa_group' ); ?>

				<h2 class="title">Delivery charge (automatic)</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Enable</th><td><?php self::checkbox( 'delivery_enabled', 'Set the delivery charge from the customer\'s address', 'Replaces the flat-rate / shipping options with one automatic charge.' ); ?></td></tr>
					<tr><th scope="row">Lock the charge</th><td><?php self::checkbox( 'lock_delivery', 'The customer cannot choose or change the delivery charge', 'Only the one charge for the delivery place is shown, with no choice. Turn off to also keep WooCommerce local-pickup / free-shipping choices.' ); ?></td></tr>
					<tr><th scope="row">Free shipping rule</th><td><?php self::checkbox( 'respect_free', 'Still give free delivery when your WooCommerce free-shipping rule is met', 'For example "free over 1000" under WooCommerce > Settings > Shipping. Turn off to always charge by place, even on big orders.' ); ?></td></tr>
					<tr><th scope="row">1. Inside Dhaka city</th><td><?php self::number( 'fee_inside', 0, 100000, 'Taka. The Dhaka city thanas (Mirpur, Uttara, Gulshan, Dhanmondi, Motijheel ...).', '0.01' ); ?></td></tr>
					<tr><th scope="row">2. Sub-urban Dhaka city</th><td><?php self::number( 'fee_suburb', 0, 100000, 'Taka. Savar, Keraniganj, Dhamrai, Nawabganj, Dohar, plus the places listed below (Gazipur and Narayanganj city areas by default).', '0.01' ); ?></td></tr>
					<tr><th scope="row">3. Outside Dhaka</th><td><?php self::number( 'fee_outside', 0, 100000, 'Taka. Every other district. This fee is also shown until the customer\'s district is known.', '0.01' ); ?></td></tr>
					<tr><th scope="row">Names shown to customers</th><td>
						<?php self::text( 'label_inside' ); ?><br><br>
						<?php self::text( 'label_suburb' ); ?><br><br>
						<?php self::text( 'label_outside' ); ?>
						<p class="description">Leave empty to use the standard wording of the chosen language.</p>
					</td></tr>
					<tr><th scope="row">Sub-urban places</th><td><?php self::text( 'suburb_extra', 'large-text', 'Comma separated, written as District:Thana (English names), or just a District to include all of it. Example: Gazipur:Tongi, Narayanganj, Munshiganj:Sirajdikhan. Dhaka district non-city thanas are always sub-urban.' ); ?></td></tr>
					<tr><th scope="row">Also count as inside Dhaka city</th><td><?php self::text( 'extra_inside', 'large-text', 'Optional. English thana names of Dhaka district to charge the inside-city fee, comma separated. Example: Savar, Keraniganj' ); ?></td></tr>
					<tr><th scope="row">District = Dhaka, thana unknown</th><td>
						<select name="<?php echo esc_attr( $opt ); ?>[dhaka_unknown]">
							<option value="inside" <?php selected( BDSA_Plugin::get( 'dhaka_unknown' ), 'inside' ); ?>>Charge the inside-Dhaka fee</option>
							<option value="suburb" <?php selected( BDSA_Plugin::get( 'dhaka_unknown' ), 'suburb' ); ?>>Charge the sub-urban fee</option>
								<option value="outside" <?php selected( BDSA_Plugin::get( 'dhaka_unknown' ), 'outside' ); ?>>Charge the outside-Dhaka fee</option>
						</select>
						<p class="description">Used when the customer picked Dhaka but no thana could be found. Before any district is chosen the outside fee is shown.</p>
					</td></tr>
				</table>

				<h2 class="title">Checkout form</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Enable</th><td><?php self::checkbox( 'enabled', 'Turn the plugin on' ); ?></td></tr>
					<tr><th scope="row">Refined address box</th><td><?php self::checkbox( 'show_box', 'Show the refined address above the address field', 'District and Thana are filled even if this is off.' ); ?></td></tr>
					<tr><th scope="row">District &amp; Thana fields</th><td><?php self::checkbox( 'ensure_fields', 'Show them even if a checkout builder (CartFlows) turned them off' ); ?></td></tr>
					<tr><th scope="row">Thana dropdown</th><td><?php self::checkbox( 'thana_dropdown', 'Turn the City field into a Thana / Upazila dropdown', 'Applied for Bangladesh customers. The value saved on the order is the English thana name.' ); ?></td></tr>
					<tr><th scope="row">Thana optional</th><td><?php self::checkbox( 'thana_optional', 'Let the customer leave the thana empty ("I do not know")', 'The order is then marked "check the address" for your team. The delivery charge does not depend on it inside Dhaka city.' ); ?></td></tr>
					<tr><th scope="row">Field order</th><td><?php self::checkbox( 'field_order', 'Name, phone, email, address, district, thana' ); ?></td></tr>
					<tr><th scope="row">Lean checkout</th><td><?php self::checkbox( 'lean_checkout', 'Remove last name, company, postcode and address line 2', 'Leave off if another plugin or your courier integration needs those fields.' ); ?></td></tr>
					<tr><th scope="row">Both addresses on the order</th><td><?php self::checkbox( 'save_addresses', 'Save the address the customer typed AND the cleaned-up address on every order', 'Both show on the order screen (admin) and in the new-order email to you.' ); ?></td></tr>
					<tr><th scope="row">Phone required</th><td><?php self::checkbox( 'phone_required', 'Make the phone number a required field' ); ?></td></tr>
					<tr><th scope="row">Show email field</th><td><?php self::checkbox( 'show_email', 'Show an email field even if the checkout builder turned it off', 'Always optional when "Email optional" is ticked.' ); ?></td></tr>
					<tr><th scope="row">Email optional</th><td><?php self::checkbox( 'email_optional', 'Make the email field optional', 'Customers can leave it empty.' ); ?></td></tr>
					<tr><th scope="row">Mobile number check</th><td><?php self::checkbox( 'validate_phone', 'Require a valid Bangladeshi mobile number (01XXXXXXXXX)', 'Accepts +88 / 88 prefix and Bangla digits.' ); ?></td></tr>
					<tr><th scope="row">Language</th><td>
						<select name="<?php echo esc_attr( $opt ); ?>[language]">
							<option value="en" <?php selected( BDSA_Plugin::get( 'language' ), 'en' ); ?>>English</option>
							<option value="bn" <?php selected( BDSA_Plugin::get( 'language' ), 'bn' ); ?>>বাংলা (Bangla)</option>
						</select>
						<p class="description">Language of the checkout words this plugin adds: District / Thana names, the refined address box, the delivery charge line, errors and (for Bangla) Subtotal, Total, Place order. Skipped when the site language is already Bangla. A button text you typed yourself in CartFlows is never changed.</p>
					</td></tr>
					<tr><th scope="row">Design</th><td>
						<?php self::checkbox( 'style', 'Apply the clean mobile-first checkout design' ); ?>
						<p><label>Accent colour <input type="text" name="<?php echo esc_attr( $opt ); ?>[accent]" value="<?php echo esc_attr( BDSA_Plugin::get( 'accent' ) ); ?>" class="small-text" placeholder="#C8102E"></label></p>
					</td></tr>
					<tr><th scope="row">Start checking after</th><td><?php self::number( 'min_chars', 6, 60, 'Characters typed in the address field before the first check.' ); ?></td></tr>
					<tr><th scope="row">Wait after typing stops</th><td><?php self::number( 'debounce', 300, 5000, 'Milliseconds. 900 works well.' ); ?></td></tr>
				</table>

					<h2 class="title">Checkout fields</h2>
				<p>You decide which fields the checkout shows. <b>Default</b> leaves the field as the plugin / your checkout builder sets it. <b>Hidden</b> removes it. Leave the label empty to keep the normal one. Order: a small number comes first (blank = automatic). If "Lean checkout" above is ticked, last name, company, postcode and address line 2 stay removed unless you set them to Required or Optional here.</p>
				<table class="widefat striped" style="max-width:820px">
					<thead><tr><th>Field</th><th>Show</th><th>Label (optional)</th><th>Order</th></tr></thead>
					<tbody>
					<?php
					$fcfg = BDSA_Plugin::get( 'fields' );
					$fcfg = is_array( $fcfg ) ? $fcfg : array();
					foreach ( BDSA_Checkout::field_keys() as $fk => $fname ) :
						$row = isset( $fcfg[ $fk ] ) && is_array( $fcfg[ $fk ] ) ? $fcfg[ $fk ] : array();
						$m   = isset( $row['mode'] ) ? $row['mode'] : '';
						$nm  = esc_attr( $opt ) . '[fields][' . esc_attr( $fk ) . ']';
						?>
						<tr>
							<td><b><?php echo esc_html( $fname ); ?></b></td>
							<td>
								<select name="<?php echo $nm; // phpcs:ignore ?>[mode]">
									<option value="" <?php selected( $m, '' ); ?>>Default</option>
									<option value="required" <?php selected( $m, 'required' ); ?>>Show — required</option>
									<option value="optional" <?php selected( $m, 'optional' ); ?>>Show — optional</option>
									<option value="hidden" <?php selected( $m, 'hidden' ); ?>>Hidden</option>
								</select>
							</td>
							<td><input type="text" class="regular-text" name="<?php echo $nm; // phpcs:ignore ?>[label]" value="<?php echo esc_attr( isset( $row['label'] ) ? $row['label'] : '' ); ?>"></td>
							<td><input type="number" class="small-text" min="0" max="999" name="<?php echo $nm; // phpcs:ignore ?>[order]" value="<?php echo esc_attr( ! empty( $row['order'] ) ? $row['order'] : '' ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">Tip: hiding District or Thana is allowed, but then the delivery charge is worked out from the typed address only.</p>

				<h2 class="title">AI (optional)</h2>
					<p>Without AI the plugin still works for free using the built-in district and thana list, so you can leave this off. AI is only used for addresses the built-in matcher is not sure about, and its answer is checked against the built-in list.</p>
					<table class="form-table" role="presentation">
						<tr><th scope="row">Use AI</th><td><?php self::checkbox( 'ai_enabled', 'Use an AI model as a fallback' ); ?></td></tr>
						<tr>
							<th scope="row"><label for="bdsa_openrouter_key">OpenRouter API key</label></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[ai_provider]" value="openrouter">
								<?php if ( $has_oconst ) : ?>
									<p><code>BDSA_OPENROUTER_KEY</code> is defined in wp-config.php and is used instead of this field.</p>
								<?php else : ?>
									<input type="password" id="bdsa_openrouter_key" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[openrouter_key]" value="" autocomplete="new-password" placeholder="<?php echo $has_okey ? 'Saved (leave empty to keep)' : 'sk-or-...'; ?>">
									<?php if ( $has_okey ) : ?>
										<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[clear_openrouter_key]" value="1"> Remove saved key</label>
									<?php endif; ?>
									<p class="description">Free key at openrouter.ai/keys (no card needed for free models). Safer option: <code>define( 'BDSA_OPENROUTER_KEY', 'your-key' );</code> in wp-config.php.</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bdsa_openrouter_model">Model in use</label></th>
							<td>
								<input type="text" id="bdsa_openrouter_model" class="large-text" name="<?php echo esc_attr( $opt ); ?>[openrouter_model]" value="<?php echo esc_attr( BDSA_Plugin::get( 'openrouter_model' ) ); ?>">
								<p class="description"><strong>Now using:</strong> <span id="bdsa-using"></span><br>First model is tried first, the others (up to 3 in total, comma separated) are automatic backups. <code>openrouter/free</code> lets OpenRouter pick any free model that is up.</p>

								<p style="margin-top:14px"><button type="button" class="button" id="bdsa-load-models">Load all OpenRouter models</button>
								<label style="margin-left:10px"><input type="checkbox" id="bdsa-free-only" checked> Free models only</label>
								<span id="bdsa-models-status" style="margin-left:10px;color:#646970"></span></p>
								<div id="bdsa-models-box" style="display:none">
									<p><input type="search" id="bdsa-model-search" class="regular-text" placeholder="Search: llama, gemma, qwen, deepseek ..."></p>
									<select id="bdsa-model-list" size="10" style="width:100%;max-width:640px"></select>
									<p>
										<button type="button" class="button button-primary" id="bdsa-use-main">Use as main model</button>
										<button type="button" class="button" id="bdsa-use-backup">Add as backup</button>
										<button type="button" class="button" id="bdsa-use-auto">Reset to auto (openrouter/free)</button>
									</p>
									<p class="description">Click <strong>Save Changes</strong> at the bottom after choosing.</p>
								</div>
							</td>
						</tr>
					<tr>
						<th scope="row">When to call AI</th>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[ai_when]">
								<option value="fallback" <?php selected( BDSA_Plugin::get( 'ai_when' ), 'fallback' ); ?>>Only when the built-in matcher is unsure (cheaper)</option>
								<option value="always" <?php selected( BDSA_Plugin::get( 'ai_when' ), 'always' ); ?>>Every address (best refined text, higher cost)</option>
							</select>
						</td>
					</tr>
					<tr><th scope="row">Daily AI limit</th><td><?php self::number( 'ai_daily_cap', 0, 100000, 'Maximum AI requests per day for the whole site. 0 = no limit. Protects you from a surprise bill.' ); ?></td></tr>
					<tr><th scope="row">Per-visitor limits</th><td>
						<?php self::number( 'ip_limit', 0, 10000 ); ?> address checks per hour &nbsp;|&nbsp; <?php self::number( 'ai_ip_limit', 0, 1000 ); ?> AI calls per hour
						<p class="description">Per IP address. 0 = no limit.</p>
					</td></tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>Test an address</h2>
			<p>Uses the saved settings (save first if you just changed them).</p>
			<textarea id="bdsa-test-addr" rows="3" class="large-text" placeholder="mirpur 10 er kache road 5 house 12 dhaka"></textarea>
			<p><button type="button" class="button" id="bdsa-test-btn">Check</button></p>
			<pre id="bdsa-test-out" style="background:#fff;border:1px solid #ccd0d4;padding:10px;min-height:40px;white-space:pre-wrap"></pre>
			<script>
			(function () {
				var load = document.getElementById('bdsa-load-models');
				if (!load) { return; }
				var inp = document.getElementById('bdsa_openrouter_model'), using = document.getElementById('bdsa-using'),
					box = document.getElementById('bdsa-models-box'), list = document.getElementById('bdsa-model-list'),
					search = document.getElementById('bdsa-model-search'), free = document.getElementById('bdsa-free-only'),
					st = document.getElementById('bdsa-models-status'), models = [];
				function show() { using.textContent = inp.value.replace(/\s+/g, '') || 'openrouter/free'; }
				function render() {
					var q = search.value.toLowerCase(), n = 0;
					list.innerHTML = '';
					models.forEach(function (m) {
						if (free.checked && !m.free) { return; }
						if (q && (m.id + ' ' + m.name).toLowerCase().indexOf(q) < 0) { return; }
						var o = document.createElement('option');
						o.value = m.id;
						o.textContent = m.id + '  —  ' + m.name + (m.ctx ? '  (' + Math.round(m.ctx / 1000) + 'k)' : '') + (m.free ? '  [free]' : '');
						list.appendChild(o); n++;
					});
					st.textContent = n + ' models';
				}
				function cur() { return inp.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean); }
				load.addEventListener('click', function () {
					st.textContent = 'Loading...';
					var fd = new FormData();
					fd.append('action', 'bdsa_models');
					fd.append('n', <?php echo wp_json_encode( wp_create_nonce( 'bdsa_models' ) ); ?>);
					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (j) {
							if (!j || !j.success) { st.textContent = 'Could not load: ' + (j && j.data ? j.data : 'error'); return; }
							models = j.data; box.style.display = ''; render();
						})
						.catch(function (e) { st.textContent = 'Error: ' + e.message; });
				});
				search.addEventListener('input', render);
				free.addEventListener('change', render);
				document.getElementById('bdsa-use-main').addEventListener('click', function () {
					if (!list.value) { return; }
					var rest = cur().filter(function (x) { return x !== list.value && x !== 'openrouter/free'; });
					inp.value = [list.value].concat(rest).slice(0, 3).join(', '); show();
				});
				document.getElementById('bdsa-use-backup').addEventListener('click', function () {
					if (!list.value) { return; }
					var c = cur().filter(function (x) { return x !== 'openrouter/free'; });
					if (c.indexOf(list.value) < 0) { c.push(list.value); }
					inp.value = c.slice(0, 3).join(', '); show();
				});
				document.getElementById('bdsa-use-auto').addEventListener('click', function () { inp.value = 'openrouter/free'; show(); });
				inp.addEventListener('input', show);
				show();
			})();
			</script>
			<script>
			(function () {
				var btn = document.getElementById('bdsa-test-btn');
				btn.addEventListener('click', function () {
					var out = document.getElementById('bdsa-test-out');
					out.textContent = '...';
					fetch(<?php echo wp_json_encode( esc_url_raw( rest_url( 'bdsa/v1/parse' ) ) ); ?>, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ address: document.getElementById('bdsa-test-addr').value })
					}).then(function (r) { return r.json(); })
					  .then(function (j) { out.textContent = JSON.stringify(j, null, 2); })
					  .catch(function (e) { out.textContent = 'Error: ' + e; });
				});
			})();
			</script>
		</div>
		<?php
	}
}
