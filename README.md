# BD Smart Address for WooCommerce

Smart checkout for Bangladesh: type the full address and District / Thana fill in automatically, with a cleaned-up full address, Bangla district and thana names, and an optional AI fallback (OpenRouter) for tricky addresses.

- Plugin file: `bd-smart-address.php` (WordPress plugin, install this folder as `bd-smart-address`)
- Geo data: `data/bd-geo.json` (district, Bangla names, thanas, aliases)
- WordPress-style readme: `README.txt`

Current version: 1.7.1

Note: the OpenRouter API key is stored in the WordPress database (option `bdsa_settings`) or in `wp-config.php` via `BDSA_OPENROUTER_KEY`. Never commit a key to this repository.

## Automatic updates

Every site running the plugin checks this repository's GitHub Releases (cached 6 hours, no token needed because the repo is public) and shows "Update available" on the Plugins page. WordPress auto-updates are switched on for the plugin the first time it runs; the site owner can turn them off on the Plugins page. "Check for updates" on the plugin row forces a check now.

## Releasing a new version

1. Edit the code.
2. Run `tools/release.sh 1.7.1 "What changed"`. It sets the version (header + `BDSA_VERSION`), commits and pushes to `main`.
3. The Release action sees the new version, tags `vX.Y.Z`, builds `bd-smart-address.zip` and publishes the release. Sites pick it up within hours.
