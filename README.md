# BD Smart Address for WooCommerce

Smart checkout for Bangladesh: type the full address and District / Thana fill in automatically, with a cleaned-up full address, Bangla district and thana names, and an optional AI fallback (OpenRouter) for tricky addresses.

- Plugin file: `bd-smart-address.php` (WordPress plugin, install this folder as `bd-smart-address`)
- Geo data: `data/bd-geo.json` (district, Bangla names, thanas, aliases)
- WordPress-style readme: `README.txt`

Current version: 1.6.1

Note: the OpenRouter API key is stored in the WordPress database (option `bdsa_settings`) or in `wp-config.php` via `BDSA_OPENROUTER_KEY`. Never commit a key to this repository.
