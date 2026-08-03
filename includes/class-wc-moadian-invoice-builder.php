<?php
/**
 * Maps a WooCommerce order to the INVOICE.V01 schema.
 *
 * @package WC_Moadian
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Moadian_Invoice_Exception extends RuntimeException {}

class WC_Moadian_Invoice_Builder {
    /** @var array */
    private $options;

    public function __construct(array $options) {
        $this->options = $options;
    }

    public function build($order, $issue_timestamp_ms, $serial) {
        if (!$order || !is_a($order, 'WC_Order')) {
            throw new WC_Moadian_Invoice_Exception('A valid WooCommerce order is required.');
        }

        $fiscal_id = strtoupper((string) ($this->options['fiscal_id'] ?? ''));
        $economic_code = preg_replace('/\D+/', '', (string) ($this->options['economic_code'] ?? ''));
        $invoice_type = (int) ($this->options['invoice_type'] ?? 2);
        $created_at = new DateTimeImmutable('@' . (int) floor($issue_timestamp_ms / 1000));
        $tax_id = WC_Moadian_API_Client::generate_tax_id($fiscal_id, $serial, $created_at);
        $body = array();

        foreach ($order->get_items('line_item') as $item) {
            $body[] = $this->build_product_line($item, $order);
        }

        foreach ($order->get_items('shipping') as $item) {
            $body[] = $this->build_charge_line($item, __('Shipping', 'wc-moadian'), $order);
        }

        foreach ($order->get_items('fee') as $item) {
            if ((float) $item->get_total() < 0) {
                throw new WC_Moadian_Invoice_Exception('Negative WooCommerce fees cannot be represented by the standard sales invoice pattern.');
            }
            $body[] = $this->build_charge_line($item, $item->get_name(), $order);
        }

        if (!$body) {
            throw new WC_Moadian_Invoice_Exception('The order does not contain any invoiceable lines.');
        }

        $totals = array(
            'tprdis' => 0,
            'tdis' => 0,
            'tadis' => 0,
            'tvam' => 0,
            'todam' => 0,
            'tbill' => 0,
        );
        foreach ($body as $line) {
            $totals['tprdis'] += $line['prdis'];
            $totals['tdis'] += $line['dis'];
            $totals['tadis'] += $line['adis'];
            $totals['tvam'] += $line['vam'];
            $totals['todam'] += $line['odam'] + $line['olam'];
            $totals['tbill'] += $line['tsstam'];
        }

        $woo_total = $this->amount($order->get_total());
        $rounding_tolerance = max(1, count($body));
        if (abs($woo_total - $totals['tbill']) > $rounding_tolerance) {
            throw new WC_Moadian_Invoice_Exception(
                sprintf(
                    'Mapped line totals (%1$d) do not match the WooCommerce order total (%2$d).',
                    $totals['tbill'],
                    $woo_total
                )
            );
        }

        $buyer = $this->buyer_fields($order, $invoice_type);
        $invoice_number = strtoupper(str_pad(dechex((int) $serial), 10, '0', STR_PAD_LEFT));
        $header = array_merge(
            array(
                'taxid' => $tax_id,
                'indatim' => (int) $issue_timestamp_ms,
                'indati2m' => (int) $issue_timestamp_ms,
                'inty' => $invoice_type,
                'inno' => $invoice_number,
                'irtaxid' => null,
                'inp' => 1,
                'ins' => 1,
                'tins' => $economic_code,
            ),
            $buyer,
            array(
                'sbc' => null,
                'bbc' => null,
                'ft' => null,
                'bpn' => null,
                'scln' => null,
                'scc' => null,
                'crn' => null,
                'billid' => null,
                'tprdis' => $totals['tprdis'],
                'tdis' => $totals['tdis'],
                'tadis' => $totals['tadis'],
                'tvam' => $totals['tvam'],
                'todam' => $totals['todam'],
                'tbill' => $totals['tbill'],
                'setm' => 1,
                'cap' => $totals['tbill'],
                'insp' => 0,
                'tvop' => $totals['tvam'],
                'dpvb' => null,
                'tax17' => 0,
            )
        );

        $invoice = array(
            'header' => $header,
            'body' => $body,
            'payments' => array(),
            'extension' => null,
        );

        $invoice = apply_filters('wc_moadian_invoice_data', $invoice, $order);

        if (!is_array($invoice) || empty($invoice['header']) || empty($invoice['body'])) {
            throw new WC_Moadian_Invoice_Exception('The wc_moadian_invoice_data filter returned an invalid invoice.');
        }

        return array(
            'invoice' => $invoice,
            'tax_id' => (string) $invoice['header']['taxid'],
            'invoice_number' => (string) $invoice['header']['inno'],
            'issue_timestamp' => (int) $issue_timestamp_ms,
        );
    }

    private function build_product_line($item, $order) {
        $product = $item->get_product();
        if (!$product) {
            throw new WC_Moadian_Invoice_Exception('An order item references a product that no longer exists.');
        }
        $this->assert_supported_taxes($item, $item->get_name(), $order);

        $service_id = $this->product_meta($product, '_moadian_service_id');
        $measurement_unit = $this->product_meta($product, '_moadian_measurement_unit');

        return $this->line(
            $service_id,
            $item->get_name(),
            (float) $item->get_quantity(),
            $measurement_unit,
            $item->get_subtotal(),
            $item->get_total(),
            $item->get_total_tax(),
            $item,
            $order
        );
    }

    private function build_charge_line($item, $fallback_title, $order) {
        $this->assert_supported_taxes($item, $item->get_name() ?: $fallback_title, $order);
        $service_id = trim((string) $item->get_meta('_moadian_service_id', true));
        $measurement_unit = trim((string) $item->get_meta('_moadian_measurement_unit', true));

        return $this->line(
            $service_id,
            $item->get_name() ?: $fallback_title,
            1,
            $measurement_unit,
            $item->get_total(),
            $item->get_total(),
            $item->get_total_tax(),
            $item,
            $order
        );
    }

    private function line($service_id, $title, $quantity, $measurement_unit, $pre_discount, $after_discount, $tax, $item, $order) {
        $service_id = $service_id ?: trim((string) ($this->options['default_service_id'] ?? ''));
        $measurement_unit = $measurement_unit ?: trim((string) ($this->options['default_measurement_unit'] ?? ''));

        if (!preg_match('/^\d{13}$/', $service_id)) {
            throw new WC_Moadian_Invoice_Exception(
                sprintf('A valid 13-digit Moadian goods/service ID is required for "%s".', $title)
            );
        }
        if ($measurement_unit === '') {
            throw new WC_Moadian_Invoice_Exception(
                sprintf('A Moadian measurement-unit code is required for "%s".', $title)
            );
        }
        if ($quantity <= 0) {
            throw new WC_Moadian_Invoice_Exception(sprintf('The quantity for "%s" must be greater than zero.', $title));
        }

        $pre_discount = $this->amount($pre_discount);
        $after_discount = $this->amount($after_discount);
        $tax = $this->amount($tax);
        if ($pre_discount < 0 || $after_discount < 0 || $tax < 0 || $after_discount > $pre_discount) {
            throw new WC_Moadian_Invoice_Exception(
                sprintf('The totals for "%s" cannot be represented by a standard positive sales line.', $title)
            );
        }
        $discount = max(0, $pre_discount - $after_discount);
        $unit_fee = $pre_discount / $quantity;
        $unit_fee = floor($unit_fee) === $unit_fee ? (int) $unit_fee : $unit_fee;
        $vat_rate = $after_discount > 0 ? round(($tax * 100) / $after_discount, 2) : 0;
        $vat_rate = apply_filters('wc_moadian_line_vat_rate', $vat_rate, $item, $order);

        return array(
            'sstid' => $service_id,
            'sstt' => (string) $title,
            'mu' => (string) $measurement_unit,
            'am' => (float) $quantity,
            'fee' => $unit_fee,
            'cfee' => null,
            'cut' => null,
            'exr' => null,
            'prdis' => $pre_discount,
            'dis' => $discount,
            'adis' => $after_discount,
            'vra' => (float) $vat_rate,
            'vam' => $tax,
            'odt' => null,
            'odr' => 0,
            'odam' => 0,
            'olt' => null,
            'olr' => 0,
            'olam' => 0,
            'consfee' => null,
            'spro' => null,
            'bros' => null,
            'tcpbs' => null,
            'cop' => null,
            'vop' => null,
            'bsrn' => null,
            'tsstam' => $after_discount + $tax,
        );
    }

    private function buyer_fields($order, $invoice_type) {
        if ($invoice_type === 2) {
            return array('tob' => null, 'bid' => null, 'tinb' => null, 'bpc' => null);
        }

        $national_meta_key = (string) ($this->options['buyer_national_id_meta'] ?? '_billing_national_id');
        $economic_meta_key = (string) ($this->options['buyer_economic_code_meta'] ?? '_billing_economic_code');
        $national_id = preg_replace('/\D+/', '', (string) $order->get_meta($national_meta_key, true));
        $buyer_economic_code = preg_replace('/\D+/', '', (string) $order->get_meta($economic_meta_key, true));

        if ($national_id === '') {
            throw new WC_Moadian_Invoice_Exception('A buyer national/legal ID is required for a type-1 invoice.');
        }

        return array(
            'tob' => strlen($national_id) === 10 ? 1 : 2,
            'bid' => $national_id,
            'tinb' => $buyer_economic_code ?: $national_id,
            'bpc' => preg_replace('/\D+/', '', (string) $order->get_billing_postcode()),
        );
    }

    private function product_meta($product, $key) {
        $value = trim((string) $product->get_meta($key, true));
        if ($value === '' && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $value = trim((string) $parent->get_meta($key, true));
            }
        }
        return $value;
    }

    private function assert_supported_taxes($item, $title, $order) {
        $taxes = $item->get_taxes();
        $nonzero_tax_count = 0;
        foreach (($taxes['total'] ?? array()) as $amount) {
            if (abs((float) $amount) > 0.000001) {
                $nonzero_tax_count++;
            }
        }
        $allow_multiple = apply_filters('wc_moadian_allow_multiple_taxes', false, $item, $order);
        if ($nonzero_tax_count > 1 && !$allow_multiple) {
            throw new WC_Moadian_Invoice_Exception(
                sprintf('Multiple WooCommerce taxes on "%s" require custom Moadian tax mapping.', $title)
            );
        }
    }

    private function amount($amount) {
        $multiplier = (float) ($this->options['amount_multiplier'] ?? 1);
        if ($multiplier <= 0) {
            throw new WC_Moadian_Invoice_Exception('The amount multiplier must be greater than zero.');
        }
        return (int) round((float) $amount * $multiplier, 0, PHP_ROUND_HALF_UP);
    }
}
