BD Smart Address for WooCommerce  (v1.7.2)
==========================================

What it does
------------
1. The customer types the full address at checkout. After they stop typing:
   - a box above the address field shows the cleaned-up full address:  "street part, Thana, District"
     (example: "uttar badda, dhaka"  ->  "Uttar Badda, Badda, Dhaka")
   - District and Thana are selected automatically (the customer can change them; the plugin never
     overwrites a field the customer chose by hand)
   - the delivery charge for that address is shown
2. Delivery charge is automatic, in three zones (all fees and names editable in Settings):
     Inside Dhaka city   60 Tk   (Mirpur, Uttara, Gulshan, Dhanmondi, Motijheel ...)
     Sub-urban Dhaka    100 Tk   (Savar, Keraniganj, Dhamrai, Nawabganj, Dohar + the Gazipur and
                                  Narayanganj city areas; the list is editable: "District:Thana")
     Outside Dhaka      120 Tk   (everywhere else, and until the district is known)
3. Checkout fields are always there: Name, Phone, Email, Address, District, Thana. Fields that a
   checkout builder (CartFlows) switched off are brought back.
4. Clean mobile-first design and Bangla labels (can be switched off in Settings). One flat card per
   column (thin border, no shadows) on classic WooCommerce, CartFlows and the Elementor Pro checkout widget.
5. The delivery charge is LOCKED: the customer sees one charge for their place and cannot change it.
   A WooCommerce free-shipping rule (e.g. "free over 1000") still applies when met (switch off in Settings).
6. Optional AI for tricky addresses (Settings > BD Smart Address > AI): OpenRouter free models
   (key from openrouter.ai/keys, model "openrouter/free" or e.g. "xxx:free"), Google Gemini, or Claude.
   The AI's answer is always checked against the built-in district/thana list. Off by default.
7. Both addresses go with the order: what the customer typed and the cleaned-up address. They show on the
   order screen (under the billing address) and in the new-order email to the shop owner.

Install
-------
1. Take a backup first (UpdraftPlus).
2. Plugins > Add New > Upload Plugin > bd-smart-address.zip > Activate.
   (If an older version is installed, upload over it and choose "Replace current with uploaded".)
3. Settings > BD Smart Address: check the fees, labels and accent colour.
4. Open the checkout page in a private window, add a product and type an address.
   "Test an address" at the bottom of the settings page shows what the server understands.

Works with
----------
Classic WooCommerce checkout ([woocommerce_checkout]) and CartFlows checkout steps.
NOT the new WooCommerce "Checkout block".

Delivery charge notes
---------------------
- The charge is a normal WooCommerce shipping rate, so it appears in the order review, the order,
  invoices and emails. It replaces flat-rate options; Free shipping and Local pickup options you
  created in WooCommerce keep working.
- Guests start with no district selected, so the price never starts at the cheap fee by accident.
  Until a district is known the outside-Dhaka fee is shown.
- If a customer picks Dhaka but no thana can be found, the "District = Dhaka, thana unknown" setting
  decides (default: inside Dhaka fee).
- Dhaka district areas outside the city count as Sub-urban; move one to the city fee with
  "Also count as inside Dhaka city".
- Only Bangladesh deliveries are touched. Other countries keep your normal WooCommerce shipping.

How addresses are understood
----------------------------
1. Built-in list: 64 districts and 562 upazilas / thanas (including Dhaka and Chattogram city thanas),
   Bangla + English names, common spellings (Chittagong / Chattogram, Comilla / Cumilla, ...), small typos.
2. Optional AI for addresses the list is unsure about. Its answer is checked against the
   built-in list, so it cannot invent a district or thana. AI is OFF by default and not needed:
   the built-in list does the work for free.
   Provider: Google Gemini (default model gemini-3.5-flash-lite, has a free tier; get a key at
   aistudio.google.com/apikey) or Anthropic Claude. Google is retiring gemini-2.5-flash, so if the
   model you typed stops working the plugin tries gemini-3.5-flash-lite, then gemini-3.1-flash-lite.
   Safer key storage in wp-config.php:  define( 'BDSA_GEMINI_KEY', 'your-key' );
   (or BDSA_ANTHROPIC_KEY for Claude). A daily limit (default 300) protects you from a surprise bill.

Developer filters
-----------------
bdsa_should_load          (bool)    load on a custom checkout page
bdsa_bd_only              (bool)    force the Bangladesh-only behaviour on/off
bdsa_strings              (array)   customer-facing text
bdsa_bangla_labels        (array)   English -> Bangla checkout words

REST: POST /wp-json/bdsa/v1/parse   { "address": "..." }
Uninstalling deletes the plugin settings only. Orders are not touched.
