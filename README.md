# WooCommerce Moadian Connector

WordPress/WooCommerce integration for the Iranian Tax Organization's no-certificate self-TSP protocol described in the supplied .NET SDK guide.

## What it does

- Queues a fiscal invoice when a WooCommerce order becomes `completed`.
- Builds the documented `INVOICE.V01` header/body structure from order-time values.
- Generates the fiscal tax ID and invoice serial required by Moadian.
- Signs requests with RSA-SHA256 and encrypts invoice packets with RSA-OAEP-256 plus AES-256-GCM.
- Retrieves and caches access tokens using `GET_TOKEN`.
- Retrieves the Tax Organization encryption key with `GET_SERVER_INFORMATION` when it is not configured manually.
- Stores the returned UID/reference number, then polls `INQUIRY_BY_REFERENCE_NUMBER` until Moadian confirms or rejects the invoice.
- Uses WooCommerce CRUD APIs, including HPOS-compatible order metadata access.
- Encrypts the private key at rest with authenticated AES-256-GCM.

See [Protocol coverage](docs/PROTOCOL-COVERAGE.md) for the feature-by-feature comparison and known gaps.

## Requirements

- WordPress 5.6+
- WooCommerce 8.0+
- PHP 7.4+
- PHP OpenSSL extension
- Action Scheduler (bundled with WooCommerce) or working WP-Cron

## Configuration

Open **Settings → Moadian Settings** and configure:

1. Sandbox or production environment.
2. Six-character fiscal memory ID (the client ID assigned after registering the public key).
3. Seller economic code.
4. The matching PEM private key.
5. Optionally, the Tax Organization public key and key ID. If omitted, the plugin retrieves them from `GET_SERVER_INFORMATION`.
6. Invoice type, amount multiplier, fallback goods/service ID, and measurement-unit code.

Use an amount multiplier of `10` only when WooCommerce prices are stored in tomans and the invoice must be sent in rials. Keep it at `1` when prices are already stored in rials.

### Product mapping

Every product or variation should have these custom-meta values:

- `_moadian_service_id`: valid 13-digit goods/service ID.
- `_moadian_measurement_unit`: Moadian measurement-unit code.

Variation values fall back to the parent product. Shipping and fee order items can also carry those metadata values; otherwise the plugin uses the configured fallbacks. Submission fails explicitly when required mapping is missing—no placeholder tax data is sent.

Type-1 invoices additionally read buyer identity from `_billing_national_id` and `_billing_economic_code` by default. Both meta-key names are configurable.

## Invoice lifecycle

`queued` → `sending` → `pending` → `success` or `failed`

An accepted async submission is deliberately recorded as `pending`, not `success`. The plugin schedules an inquiry and only marks the order successful after Moadian returns `SUCCESS`. From **WooCommerce → Moadian Invoices**, retry failed submissions or manually check pending references.

## Extension hooks

- `wc_moadian_invoice_data`: modify the final documented invoice array.
- `wc_moadian_invoice_serial`: supply a unique, at-most-12-digit serial before the first send (defaults to order ID and is then persisted).
- `wc_moadian_line_vat_rate`: override a line's derived VAT rate.
- `wc_moadian_allow_multiple_taxes`: opt into custom multi-tax mapping; by default such lines fail explicitly.
- `wc_moadian_api_base_url`: override the selected sandbox/production base URL.

## Tests

Run the dependency-free protocol checks with:

```bash
php tests/run.php
```

The suite checks official/community SDK vectors for normalization and tax-ID generation, verifies request and data signatures, and—when the temporary reference SDK is available—cross-decrypts the RSA-OAEP/AES-GCM payload.

## Important scope note

This code implements the protocol in the document supplied for this review. The original government URL currently returns HTTP 404, and later SDK revisions have been announced. Confirm the currently mandated SDK/version and invoice rules with the Iranian Tax Organization before production filing. This software is not tax or legal advice.

## License

MIT
