=== WooCommerce Moadian Connector ===
Contributors: shahinilderemi
Donate link: https://ildrm.com
Tags: WooCommerce, Iran Tax, Moadian, Invoicing, سامانه مودیان
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends completed WooCommerce orders through the documented Moadian no-certificate self-TSP protocol.

== Description ==

Protocol-correct fiscal tax IDs, RSA signatures, encrypted INVOICE.V01 packets, asynchronous confirmation inquiries, HPOS-compatible order metadata, retry controls, and sandbox/production settings.

Every product requires a valid Moadian goods/service ID and measurement-unit code. See README.md and docs/PROTOCOL-COVERAGE.md before use.

Important: tax APIs and invoice rules can change. Validate the currently mandated government SDK/version and complete sandbox acceptance testing before production filing.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/` and activate it.
2. Open Settings > Moadian Settings.
3. Configure the fiscal memory ID, economic code, private key, environment, and invoice defaults.
4. Add `_moadian_service_id` and `_moadian_measurement_unit` metadata to products.
5. Use Test connection, then submit representative orders in the government sandbox.

== Changelog ==

= 2.0.0 =
* Replaced placeholder endpoints/payloads with the documented self-TSP protocol.
* Added packet normalization, signatures, encryption, token expiry, fiscal tax IDs, and final-status inquiries.
* Added queued submission, idempotent retry metadata, HPOS order access, authenticated secret storage, and capability checks.

= 1.0.0 =
* Initial release.
