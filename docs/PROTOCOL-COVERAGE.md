# Moadian protocol coverage review

Review date: 2026-08-03

The comparison target is the supplied document, “راهنمای اتصال به زیرسامانه جمع‌آوری SDK بدون گواهی – دات‌نت”. Its original `intamedia.ir` URL returned HTTP 404 during this review, so the contents were checked against an indexed reproduction and the production-used open-source PHP implementation of the same self-TSP protocol.

Both documented self-TSP routes were reachable during the review: production and `sandboxrc` returned HTTP 405 to a header-only request, which is the expected method rejection for a POST-only endpoint. No credentialed invoice was submitted.

## Feature matrix

| Document capability | Status | Project behavior |
|---|---|---|
| Configure fiscal/client ID and private-key signer | Implemented | Settings validate the six-character fiscal ID and PEM private key. |
| Production and sandbox base URLs | Implemented | Production and `sandboxrc` endpoints are selectable and filterable. |
| Essential `Authorization`, `requestTraceId`, and millisecond `timestamp` headers | Implemented | Generated per request and included in request signatures. |
| SimpleNormalizer (`v1`) | Implemented | Recursive flattening, key sorting, null/empty handling, boolean normalization, and `#` escaping are covered by vectors. |
| RSA-SHA256 signatures | Implemented | Invoice data, sync packets, and async batch requests are signed. |
| Encrypted transport | Implemented | RSA-OAEP-256 wraps the AES key; AES-256-GCM encrypts invoice data. |
| `GET_SERVER_INFORMATION` | Implemented | Active encryption public key/key ID are retrieved and cached when absent from settings. |
| `GET_TOKEN` and token expiry | Implemented | Tokens use the fiscal ID, are cached to the response expiry, and refresh once on HTTP 401. |
| Generate unique fiscal tax ID | Implemented | Six-character memory ID + epoch-day + 12-digit serial + Verhoeff check digit. |
| Send `INVOICE.V01` asynchronously | Implemented | One WooCommerce order is sent per async request, with stored UID/reference number. |
| Documented invoice header/body/payment shape | Partially implemented | Standard sales pattern (`inp=1`, original subject `ins=1`) and normal WooCommerce product/shipping/fee totals are mapped. Optional industry-specific fields remain null. |
| Type 1 and Type 2 invoices | Implemented | Type 1 requires buyer identity; Type 2 omits it. |
| Inquiry by reference number | Implemented | Scheduled polling and manual status checks update the order from the final result. |
| Inquiry by UID + fiscal ID | Not implemented | UID is stored, but there is no UID inquiry operation/UI. |
| Inquiry from a date | Not implemented | No date-based query/report UI. |
| Inquiry over a date range | Not implemented | No date-range query/report UI. |
| Fiscal-memory information | Implemented internally | Used by **Test connection**; no separate information screen. |
| Economic-code information | Implemented in client | No separate admin screen. |
| Goods/services search with pagination | Not implemented | Product IDs must be supplied as product/order-item metadata or a fallback setting. |
| Multiple-invoice batch submission | Not implemented | Orders are queued and sent independently. |
| Sync/async generic packet APIs | Partially implemented | Required sync operations and async invoice submission are implemented, not a public generic SDK surface. |
| Custom signatory/hardware token | Not implemented | In-memory PEM private-key signing only. |

## Defects found in version 1.2.6 and fixed

1. The plugin used nonexistent `/api/auth/challenge`, `/api/auth/token`, and `/api/invoice/send` routes. These were replaced with the documented self-TSP sync/async routes.
2. Authentication signed a challenge plus economic code, although the SDK authenticates `GET_TOKEN` packets with the fiscal memory ID. The correct packet flow is now used.
3. Invoice packets were neither normalized, signed, nor encrypted. Full transport cryptography is now implemented.
4. The payload used invented names such as `invoiceNumber`, `issueDate`, and `items`, instead of `header`, `body`, `payments`, and fields such as `taxid`, `indatim`, `sstid`, and `tsstam`. Mapping now follows `INVOICE.V01`.
5. No fiscal tax ID or hexadecimal invoice serial was generated. Both are deterministic and persisted across retries.
6. Current product prices and a hard-coded 9% VAT rate were used instead of order-time line values. The builder now uses stored order subtotal/discount/tax values and derives each rate.
7. A fabricated buyer ID (`0000000000`) was submitted when identity was missing. Type-1 mapping now fails explicitly; Type 2 sends no buyer identity.
8. Submission acceptance was recorded as final success. It is now `pending` until inquiry reports `SUCCESS`.
9. UID/reference numbers were discarded, so reliable inquiry and idempotent retry were impossible. They are now stored on the order.
10. Token lifetime was hard-coded to one hour and HTTP 401 was not retried. The response expiry is honored with a safety buffer.
11. Completed-order submission blocked the status-change request. Action Scheduler/WP-Cron now handles automatic submission.
12. AJAX retry lacked a capability check. It now requires `manage_woocommerce` in addition to a nonce.
13. The invoice list used direct `shop_order` post queries, which break with WooCommerce HPOS and were unbounded. It now uses `wc_get_orders` and limits the view to 100 recent records.
14. The private key used deterministic, unauthenticated AES-CBC and was rendered back into the settings form. New writes use randomized AES-256-GCM, and stored secrets are never displayed.
15. Debug code emitted persistent menu errors and logged successful menu registration. That production noise was removed.

## Remaining product/compliance gaps

- Corrective, cancellation, and return/refund invoice subjects are not generated.
- Invoice patterns other than standard sale (`inp=1`) are not mapped.
- Industry-specific header/body fields, payment-terminal data, contracts, exports, utilities, flights, gold/jewelry, and construction fields are not mapped.
- Goods/services identifiers and measurement units are not synchronized from the government list.
- There is no historical inquiry/report page by UID or time range.
- Tax rules and required fields can vary by invoice type/pattern and can change. A tax-domain acceptance test with sandbox credentials is still required.

## Version caveat

The supplied no-certificate document describes the legacy self-TSP/SimpleNormalizer transport. New SDK releases were publicly announced for deployment beginning 1404/10/01, and the supplied official URL is no longer available. Before production use, obtain the latest SDK and technical invoice instructions from the Tax Organization and confirm that this endpoint/version remains accepted.

## Cross-check sources

- Supplied government PDF URL: <https://www.intamedia.ir/Portals/0/news/Terminals/%D8%B1%D8%A7%D9%87%D9%86%D9%85%D8%A7%DB%8C%20%D8%A7%D8%AA%D8%B5%D8%A7%D9%84%20%D8%A8%D9%87%20%D8%B2%DB%8C%D8%B1%D8%B3%D8%A7%D9%85%D8%A7%D9%86%D9%87%20%D8%AC%D9%85%D8%B9%20%D8%A2%D9%88%D8%B1%DB%8C%20SDK%20%D8%A8%D8%AF%D9%88%D9%86%20%DA%AF%D9%88%D8%A7%D9%87%DB%8C-%D8%AF%D8%A7%D8%AA%20%D9%86%D8%AA_8.pdf>
- Indexed document reproduction: <https://rahrokh.com/guide-to-using-sdk-net/>
- Cross-checked PHP self-TSP implementation: <https://github.com/Snapp-Market-Pro/moadian>
