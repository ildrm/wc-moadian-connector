<?php
/**
 * Plugin Name: WooCommerce Moadian Connector
 * Description: Sends WooCommerce fiscal invoices to Iran's Moadian self-TSP service.
 * Version:     2.0.0
 * Author:      Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-moadian
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_MOADIAN_VERSION', '2.0.0');
define('WC_MOADIAN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_MOADIAN_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WC_MOADIAN_PLUGIN_DIR . 'includes/class-wc-moadian-api-client.php';
require_once WC_MOADIAN_PLUGIN_DIR . 'includes/class-wc-moadian-invoice-builder.php';

function wc_moadian_init_encryption_key() {
    if (!defined('WC_MOADIAN_ENCRYPTION_KEY')) {
        define('WC_MOADIAN_ENCRYPTION_KEY', wp_salt('secure_auth'));
    }
}

function wc_moadian_check_requirements() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_moadian_woocommerce_notice');
        return false;
    }
    if (version_compare(PHP_VERSION, '7.4', '<') || !extension_loaded('openssl')) {
        add_action('admin_notices', 'wc_moadian_platform_notice');
        return false;
    }
    return true;
}

function wc_moadian_woocommerce_notice() {
    echo '<div class="notice notice-error"><p>'
        . esc_html__('WooCommerce Moadian Connector requires WooCommerce 8.0 or newer.', 'wc-moadian')
        . '</p></div>';
}

function wc_moadian_platform_notice() {
    echo '<div class="notice notice-error"><p>'
        . esc_html__('WooCommerce Moadian Connector requires PHP 7.4 or newer and the OpenSSL extension.', 'wc-moadian')
        . '</p></div>';
}

function wc_moadian_load_textdomain() {
    load_plugin_textdomain('wc-moadian', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'wc_moadian_load_textdomain', 20);

function wc_moadian_init() {
    wc_moadian_init_encryption_key();
    if (!wc_moadian_check_requirements()) {
        return;
    }

    add_action('admin_menu', 'wc_moadian_add_admin_pages');
    add_action('admin_init', 'wc_moadian_register_settings');
    add_action('admin_enqueue_scripts', 'wc_moadian_enqueue_admin_assets');
    add_action('woocommerce_order_status_completed', 'wc_moadian_queue_order');
    add_action('wc_moadian_process_invoice', 'wc_moadian_process_invoice');
    add_action('wc_moadian_inquire_invoice', 'wc_moadian_inquire_invoice');
    add_action('wp_ajax_wc_moadian_retry_invoice', 'wc_moadian_retry_invoice');
    add_action('wp_ajax_wc_moadian_test_connection', 'wc_moadian_test_connection');
}
add_action('plugins_loaded', 'wc_moadian_init', 20);

function wc_moadian_default_options() {
    return array(
        'env' => 'sandbox',
        'private_key' => '',
        'fiscal_id' => '',
        'economic_code' => '',
        'tax_org_public_key' => '',
        'tax_org_key_id' => '',
        'invoice_type' => '2',
        'default_service_id' => '',
        'default_measurement_unit' => '1627',
        'amount_multiplier' => '1',
        'buyer_national_id_meta' => '_billing_national_id',
        'buyer_economic_code_meta' => '_billing_economic_code',
    );
}

function wc_moadian_get_options() {
    $options = get_option('wc_moadian_options', array());
    return wp_parse_args(is_array($options) ? $options : array(), wc_moadian_default_options());
}

function wc_moadian_register_settings() {
    register_setting('wc_moadian_options', 'wc_moadian_options', 'wc_moadian_sanitize_options');

    add_settings_section(
        'wc_moadian_connection',
        __('Connection', 'wc-moadian'),
        '__return_null',
        'wc-moadian-settings'
    );
    wc_moadian_add_field('env', __('Environment', 'wc-moadian'), 'wc_moadian_env_field', 'wc_moadian_connection');
    wc_moadian_add_field('fiscal_id', __('Fiscal memory ID', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_connection');
    wc_moadian_add_field('economic_code', __('Seller economic code', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_connection');
    wc_moadian_add_field('private_key', __('Private key (PEM)', 'wc-moadian'), 'wc_moadian_private_key_field', 'wc_moadian_connection');
    wc_moadian_add_field('tax_org_public_key', __('Tax Organization public key', 'wc-moadian'), 'wc_moadian_public_key_field', 'wc_moadian_connection');
    wc_moadian_add_field('tax_org_key_id', __('Tax Organization key ID', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_connection');

    add_settings_section(
        'wc_moadian_invoice',
        __('Invoice mapping', 'wc-moadian'),
        'wc_moadian_invoice_section_description',
        'wc-moadian-settings'
    );
    wc_moadian_add_field('invoice_type', __('Invoice type', 'wc-moadian'), 'wc_moadian_invoice_type_field', 'wc_moadian_invoice');
    wc_moadian_add_field('default_service_id', __('Fallback goods/service ID', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_invoice');
    wc_moadian_add_field('default_measurement_unit', __('Fallback measurement unit', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_invoice');
    wc_moadian_add_field('amount_multiplier', __('Amount multiplier', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_invoice');
    wc_moadian_add_field('buyer_national_id_meta', __('Buyer national-ID meta key', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_invoice');
    wc_moadian_add_field('buyer_economic_code_meta', __('Buyer economic-code meta key', 'wc-moadian'), 'wc_moadian_text_field', 'wc_moadian_invoice');
}

function wc_moadian_add_field($id, $label, $callback, $section) {
    add_settings_field(
        $id,
        $label,
        $callback,
        'wc-moadian-settings',
        $section,
        array('id' => $id, 'label_for' => 'wc_moadian_' . $id)
    );
}

function wc_moadian_invoice_section_description() {
    echo '<p>' . esc_html__(
        'Set _moadian_service_id and _moadian_measurement_unit on each product. The fallback is also used for shipping and fee lines.',
        'wc-moadian'
    ) . '</p>';
}

function wc_moadian_env_field() {
    $options = wc_moadian_get_options();
    ?>
    <select id="wc_moadian_env" name="wc_moadian_options[env]">
        <option value="sandbox" <?php selected($options['env'], 'sandbox'); ?>><?php esc_html_e('Sandbox', 'wc-moadian'); ?></option>
        <option value="production" <?php selected($options['env'], 'production'); ?>><?php esc_html_e('Production', 'wc-moadian'); ?></option>
    </select>
    <?php
}

function wc_moadian_text_field($args) {
    $options = wc_moadian_get_options();
    $id = $args['id'];
    ?>
    <input class="regular-text" id="wc_moadian_<?php echo esc_attr($id); ?>" type="text"
        name="wc_moadian_options[<?php echo esc_attr($id); ?>]"
        value="<?php echo esc_attr($options[$id]); ?>">
    <?php
}

function wc_moadian_private_key_field() {
    $options = wc_moadian_get_options();
    $stored = !empty($options['private_key']);
    ?>
    <textarea class="large-text code" id="wc_moadian_private_key" name="wc_moadian_options[private_key]" rows="7" autocomplete="new-password"></textarea>
    <p class="description">
        <?php echo esc_html($stored
            ? __('A private key is stored. Leave this field empty to keep it.', 'wc-moadian')
            : __('Paste the unencrypted PKCS#8 or PKCS#1 PEM private key.', 'wc-moadian')); ?>
    </p>
    <?php
}

function wc_moadian_public_key_field() {
    $options = wc_moadian_get_options();
    ?>
    <textarea class="large-text code" id="wc_moadian_tax_org_public_key" name="wc_moadian_options[tax_org_public_key]" rows="6"><?php echo esc_textarea($options['tax_org_public_key']); ?></textarea>
    <p class="description"><?php esc_html_e('Optional. If empty, the plugin retrieves the active encryption key from GET_SERVER_INFORMATION.', 'wc-moadian'); ?></p>
    <?php
}

function wc_moadian_invoice_type_field() {
    $options = wc_moadian_get_options();
    ?>
    <select id="wc_moadian_invoice_type" name="wc_moadian_options[invoice_type]">
        <option value="1" <?php selected($options['invoice_type'], '1'); ?>><?php esc_html_e('Type 1 — standard (buyer identity required)', 'wc-moadian'); ?></option>
        <option value="2" <?php selected($options['invoice_type'], '2'); ?>><?php esc_html_e('Type 2 — simplified', 'wc-moadian'); ?></option>
    </select>
    <?php
}

function wc_moadian_sanitize_options($input) {
    wc_moadian_init_encryption_key();
    $existing = wc_moadian_get_options();
    $input = is_array($input) ? wp_unslash($input) : array();
    $sanitized = wc_moadian_default_options();
    $sanitized['env'] = in_array($input['env'] ?? '', array('sandbox', 'production'), true) ? $input['env'] : 'sandbox';
    $sanitized['fiscal_id'] = strtoupper(sanitize_text_field($input['fiscal_id'] ?? ''));
    $sanitized['economic_code'] = preg_replace('/\D+/', '', (string) ($input['economic_code'] ?? ''));
    $sanitized['invoice_type'] = in_array((string) ($input['invoice_type'] ?? ''), array('1', '2'), true) ? (string) $input['invoice_type'] : '2';
    $sanitized['default_service_id'] = preg_replace('/\D+/', '', (string) ($input['default_service_id'] ?? ''));
    $sanitized['default_measurement_unit'] = sanitize_text_field($input['default_measurement_unit'] ?? '1627');
    $sanitized['amount_multiplier'] = (string) max(0.0001, (float) ($input['amount_multiplier'] ?? 1));
    $sanitized['buyer_national_id_meta'] = sanitize_key($input['buyer_national_id_meta'] ?? '_billing_national_id');
    $sanitized['buyer_economic_code_meta'] = sanitize_key($input['buyer_economic_code_meta'] ?? '_billing_economic_code');
    $sanitized['tax_org_public_key'] = trim((string) ($input['tax_org_public_key'] ?? ''));
    $sanitized['tax_org_key_id'] = sanitize_text_field($input['tax_org_key_id'] ?? '');

    if (!preg_match('/^[A-Z0-9]{6}$/', $sanitized['fiscal_id'])) {
        add_settings_error('wc_moadian_options', 'invalid_fiscal_id', __('Fiscal memory ID must contain exactly six letters or digits.', 'wc-moadian'));
    }
    if ($sanitized['economic_code'] === '') {
        add_settings_error('wc_moadian_options', 'invalid_economic_code', __('Seller economic code is required.', 'wc-moadian'));
    }
    if ($sanitized['default_service_id'] !== '' && !preg_match('/^\d{13}$/', $sanitized['default_service_id'])) {
        add_settings_error('wc_moadian_options', 'invalid_service_id', __('The fallback goods/service ID must contain 13 digits.', 'wc-moadian'));
    }

    $private_key = trim((string) ($input['private_key'] ?? ''));
    if ($private_key !== '') {
        if (!openssl_pkey_get_private($private_key)) {
            add_settings_error('wc_moadian_options', 'invalid_private_key', __('The private key is not a valid PEM key; the stored key was preserved.', 'wc-moadian'));
            $sanitized['private_key'] = $existing['private_key'];
        } else {
            $sanitized['private_key'] = wc_moadian_encrypt_secret($private_key);
            wc_moadian_clear_cached_credentials($sanitized['fiscal_id'], $sanitized['env']);
        }
    } else {
        $sanitized['private_key'] = $existing['private_key'];
    }

    if ($sanitized['tax_org_public_key'] !== '' && !openssl_pkey_get_public(wc_moadian_format_public_key($sanitized['tax_org_public_key']))) {
        add_settings_error('wc_moadian_options', 'invalid_public_key', __('The Tax Organization public key is invalid; the stored key was preserved.', 'wc-moadian'));
        $sanitized['tax_org_public_key'] = $existing['tax_org_public_key'];
        $sanitized['tax_org_key_id'] = $existing['tax_org_key_id'];
    }

    return $sanitized;
}

function wc_moadian_encrypt_secret($plaintext) {
    $key = hash('sha256', WC_MOADIAN_ENCRYPTION_KEY, true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) {
        throw new RuntimeException('Could not encrypt the Moadian private key.');
    }
    return 'v2:' . base64_encode($iv . $tag . $ciphertext);
}

function wc_moadian_decrypt_secret($stored) {
    if ($stored === '') {
        return '';
    }
    if (strpos($stored, 'v2:') === 0) {
        $payload = base64_decode(substr($stored, 3), true);
        if ($payload === false || strlen($payload) < 29) {
            return '';
        }
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $key = hash('sha256', WC_MOADIAN_ENCRYPTION_KEY, true);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? '' : $plaintext;
    }

    // Backward-compatible migration path for plugin versions before 2.0.
    $legacy = openssl_decrypt(
        $stored,
        'AES-256-CBC',
        WC_MOADIAN_ENCRYPTION_KEY,
        0,
        substr(WC_MOADIAN_ENCRYPTION_KEY, 0, 16)
    );
    return $legacy === false ? '' : $legacy;
}

function wc_moadian_get_api_base_url($env) {
    $url = $env === 'production' ? 'https://tp.tax.gov.ir/' : 'https://sandboxrc.tax.gov.ir/';
    return trailingslashit(apply_filters('wc_moadian_api_base_url', $url, $env));
}

function wc_moadian_create_client() {
    $options = wc_moadian_get_options();
    $private_key = wc_moadian_decrypt_secret($options['private_key']);
    $config = array(
        'base_url' => wc_moadian_get_api_base_url($options['env']),
        'private_key' => $private_key,
        'fiscal_id' => $options['fiscal_id'],
        'tax_org_public_key' => $options['tax_org_public_key'],
        'tax_org_key_id' => $options['tax_org_key_id'],
        'timeout' => 30,
    );

    if ($config['tax_org_public_key'] === '' || $config['tax_org_key_id'] === '') {
        $cache_key = 'wc_moadian_server_key_' . md5($options['env'] . '|' . $options['fiscal_id']);
        $server_key = get_transient($cache_key);
        if (!is_array($server_key)) {
            $temporary_client = new WC_Moadian_API_Client($config);
            $response = $temporary_client->get_server_information();
            $keys = $response['result']['data']['publicKeys'] ?? array();
            $server_key = wc_moadian_select_server_key($keys);
            set_transient($cache_key, $server_key, 7 * DAY_IN_SECONDS);
        }
        $config['tax_org_public_key'] = $server_key['key'];
        $config['tax_org_key_id'] = $server_key['id'];
    }

    return new WC_Moadian_API_Client($config);
}

function wc_moadian_select_server_key($keys) {
    if (!is_array($keys) || !$keys) {
        throw new WC_Moadian_API_Exception('GET_SERVER_INFORMATION returned no encryption key.');
    }
    $selected = null;
    foreach ($keys as $key) {
        if (is_array($key) && (int) ($key['purpose'] ?? 0) === 1) {
            $selected = $key;
            break;
        }
    }
    if (!$selected) {
        $selected = reset($keys);
    }
    if (empty($selected['key']) || empty($selected['id'])) {
        throw new WC_Moadian_API_Exception('GET_SERVER_INFORMATION returned an incomplete encryption key.');
    }
    return array('key' => (string) $selected['key'], 'id' => (string) $selected['id']);
}

function wc_moadian_get_token($client, $force = false) {
    $options = wc_moadian_get_options();
    $cache_key = 'wc_moadian_token_' . md5($options['env'] . '|' . $options['fiscal_id']);
    if (!$force) {
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
    }

    $auth = $client->authenticate();
    $expires = (int) $auth['expires_in'];
    $now_ms = (int) floor(microtime(true) * 1000);
    if ($expires > $now_ms) {
        $ttl = max(60, (int) floor($expires / 1000) - time() - 100);
    } elseif ($expires > 0 && $expires < DAY_IN_SECONDS) {
        $ttl = max(60, $expires - 100);
    } else {
        $ttl = 50 * MINUTE_IN_SECONDS;
    }
    set_transient($cache_key, $auth['token'], $ttl);
    return $auth['token'];
}

function wc_moadian_clear_cached_credentials($fiscal_id = '', $env = '') {
    $options = wc_moadian_get_options();
    $fiscal_id = $fiscal_id ?: $options['fiscal_id'];
    $env = $env ?: $options['env'];
    delete_transient('wc_moadian_token_' . md5($env . '|' . $fiscal_id));
    delete_transient('wc_moadian_server_key_' . md5($env . '|' . $fiscal_id));
}

function wc_moadian_format_public_key($key) {
    $key = trim((string) $key);
    if (strpos($key, '-----BEGIN') !== false) {
        return $key;
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function wc_moadian_queue_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    $status = $order->get_meta('_moadian_invoice_status', true);
    if (in_array($status, array('queued', 'sending', 'pending', 'success'), true)) {
        return;
    }
    $order->update_meta_data('_moadian_invoice_status', 'queued');
    $order->update_meta_data('_moadian_response_message', __('Invoice queued for submission.', 'wc-moadian'));
    $order->save();

    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('wc_moadian_process_invoice', array($order->get_id()), 'wc-moadian');
    } elseif (!wp_next_scheduled('wc_moadian_process_invoice', array($order->get_id()))) {
        wp_schedule_single_event(time() + 1, 'wc_moadian_process_invoice', array($order->get_id()));
    }
}

function wc_moadian_process_invoice($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    if ($order->get_meta('_moadian_invoice_status', true) === 'success') {
        return true;
    }
    if ($order->get_meta('_moadian_reference_number', true)) {
        wc_moadian_schedule_inquiry($order->get_id(), 5);
        return true;
    }
    return wc_moadian_send_invoice($order);
}

function wc_moadian_send_invoice($order) {
    $lock_key = 'wc_moadian_order_lock_' . $order->get_id();
    if (!wc_moadian_acquire_lock($lock_key)) {
        return false;
    }

    try {
        $order->update_meta_data('_moadian_invoice_status', 'sending');
        $order->save();
        $issue_timestamp = (int) $order->get_meta('_moadian_issue_timestamp', true);
        if (!$issue_timestamp) {
            $issue_timestamp = (int) floor(microtime(true) * 1000);
            $order->update_meta_data('_moadian_issue_timestamp', $issue_timestamp);
        }
        $uid = (string) $order->get_meta('_moadian_uid', true);
        if ($uid === '') {
            $uid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('moadian-', true);
            $order->update_meta_data('_moadian_uid', $uid);
        }
        $attempts = (int) $order->get_meta('_moadian_send_attempts', true) + 1;
        $order->update_meta_data('_moadian_send_attempts', $attempts);
        $order->save();

        $builder = new WC_Moadian_Invoice_Builder(wc_moadian_get_options());
        $stored_serial = $order->get_meta('_moadian_invoice_serial', true);
        $serial = (int) $stored_serial;
        if ($stored_serial === '') {
            $serial = (int) apply_filters('wc_moadian_invoice_serial', $order->get_id(), $order);
            $order->update_meta_data('_moadian_invoice_serial', $serial);
        }
        $built = $builder->build($order, $issue_timestamp, $serial);
        $order->update_meta_data('_moadian_tax_id', $built['tax_id']);
        $order->update_meta_data('_moadian_invoice_number', $built['invoice_number']);
        $order->save();

        $client = wc_moadian_create_client();
        $token = wc_moadian_get_token($client);
        try {
            $response = $client->send_invoice($built['invoice'], $token, $uid, $attempts > 1);
        } catch (WC_Moadian_API_Exception $exception) {
            if ($exception->get_http_status() !== 401) {
                throw $exception;
            }
            $token = wc_moadian_get_token($client, true);
            $response = $client->send_invoice($built['invoice'], $token, $uid, $attempts > 1);
        }

        $result = isset($response['result'][0]) && is_array($response['result'][0]) ? $response['result'][0] : array();
        if (!empty($result['errorCode'])) {
            $error_detail = $result['errorDetail'] ?? '';
            if (!is_scalar($error_detail)) {
                $error_detail = wp_json_encode($error_detail);
            }
            throw new WC_Moadian_API_Exception(trim($result['errorCode'] . ' ' . $error_detail), 0, $response);
        }
        if (empty($result['referenceNumber'])) {
            throw new WC_Moadian_API_Exception(wc_moadian_response_message($response), 0, $response);
        }

        $order->update_meta_data('_moadian_uid', (string) ($result['uid'] ?? $uid));
        $order->update_meta_data('_moadian_reference_number', (string) $result['referenceNumber']);
        $order->update_meta_data('_moadian_invoice_status', 'pending');
        $order->update_meta_data('_moadian_submission_date', current_time('mysql'));
        $order->update_meta_data('_moadian_response_message', __('Accepted by Moadian; awaiting final confirmation.', 'wc-moadian'));
        $order->save();
        wc_moadian_schedule_inquiry($order->get_id(), 60);
        return true;
    } catch (Throwable $exception) {
        wc_moadian_fail_order($order, $exception->getMessage());
        return false;
    } finally {
        delete_option($lock_key);
    }
}

function wc_moadian_acquire_lock($lock_key) {
    $now = time();
    if (add_option($lock_key, $now, '', false)) {
        return true;
    }

    $created_at = (int) get_option($lock_key, 0);
    if ($created_at > 0 && ($now - $created_at) > 2 * MINUTE_IN_SECONDS) {
        delete_option($lock_key);
        return add_option($lock_key, $now, '', false);
    }

    return false;
}

function wc_moadian_schedule_inquiry($order_id, $delay = 300) {
    $args = array((int) $order_id);
    if (function_exists('as_schedule_single_action')) {
        if (!function_exists('as_next_scheduled_action') || !as_next_scheduled_action('wc_moadian_inquire_invoice', $args, 'wc-moadian')) {
            as_schedule_single_action(time() + (int) $delay, 'wc_moadian_inquire_invoice', $args, 'wc-moadian');
        }
    } elseif (!wp_next_scheduled('wc_moadian_inquire_invoice', $args)) {
        wp_schedule_single_event(time() + (int) $delay, 'wc_moadian_inquire_invoice', $args);
    }
}

function wc_moadian_inquire_invoice($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_moadian_invoice_status', true) === 'success') {
        return false;
    }
    $reference_number = (string) $order->get_meta('_moadian_reference_number', true);
    if ($reference_number === '') {
        return false;
    }

    try {
        $client = wc_moadian_create_client();
        $token = wc_moadian_get_token($client);
        try {
            $response = $client->inquire_by_reference_number($reference_number, $token);
        } catch (WC_Moadian_API_Exception $exception) {
            if ($exception->get_http_status() !== 401) {
                throw $exception;
            }
            $response = $client->inquire_by_reference_number($reference_number, wc_moadian_get_token($client, true));
        }
        $results = $response['result']['data'] ?? array();
        $result = wc_moadian_find_inquiry_result($results, $reference_number);
        $status = strtoupper((string) ($result['status'] ?? 'PENDING'));
        $message = wc_moadian_inquiry_message($result);

        if ($status === 'SUCCESS' || !empty($result['data']['success'])) {
            $order->update_meta_data('_moadian_invoice_status', 'success');
            $order->update_meta_data('_moadian_response_message', $message ?: __('Invoice confirmed successfully.', 'wc-moadian'));
            $order->save();
            return true;
        }
        if ($status === 'FAILED') {
            wc_moadian_fail_order($order, $message ?: __('Moadian rejected the invoice.', 'wc-moadian'));
            return false;
        }

        $attempts = (int) $order->get_meta('_moadian_inquiry_attempts', true) + 1;
        $order->update_meta_data('_moadian_inquiry_attempts', $attempts);
        $order->update_meta_data('_moadian_invoice_status', 'pending');
        $order->update_meta_data('_moadian_response_message', $message ?: __('Moadian confirmation is still pending.', 'wc-moadian'));
        $order->save();
        if ($attempts < 12) {
            wc_moadian_schedule_inquiry($order->get_id(), 300);
        }
        return false;
    } catch (Throwable $exception) {
        wc_moadian_log_error('Inquiry failed for order ' . $order->get_id() . ': ' . $exception->getMessage());
        $attempts = (int) $order->get_meta('_moadian_inquiry_attempts', true) + 1;
        $order->update_meta_data('_moadian_inquiry_attempts', $attempts);
        $order->update_meta_data('_moadian_response_message', $exception->getMessage());
        $order->save();
        if ($attempts < 12) {
            wc_moadian_schedule_inquiry($order->get_id(), 300);
        }
        return false;
    }
}

function wc_moadian_find_inquiry_result($results, $reference_number) {
    if (!is_array($results)) {
        return array();
    }
    foreach ($results as $result) {
        if (is_array($result) && (string) ($result['referenceNumber'] ?? '') === (string) $reference_number) {
            return $result;
        }
    }
    return array();
}

function wc_moadian_inquiry_message($result) {
    $messages = array();
    foreach (array('error', 'warning') as $type) {
        $entries = $result['data'][$type] ?? array();
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (is_array($entry)) {
                    $messages[] = trim(($entry['code'] ?? '') . ' ' . ($entry['message'] ?? ''));
                }
            }
        }
    }
    return implode('; ', array_filter($messages));
}

function wc_moadian_response_message($response) {
    if (!empty($response['message'])) {
        return (string) $response['message'];
    }
    $messages = array();
    foreach (($response['errors'] ?? array()) as $error) {
        if (is_array($error)) {
            $messages[] = trim(($error['code'] ?? '') . ' ' . ($error['message'] ?? ''));
        }
    }
    return $messages ? implode('; ', $messages) : 'Invoice submission returned no reference number.';
}

function wc_moadian_fail_order($order, $message) {
    wc_moadian_log_error('Order ' . $order->get_id() . ': ' . $message);
    $order->update_meta_data('_moadian_invoice_status', 'failed');
    $order->update_meta_data('_moadian_response_message', sanitize_text_field($message));
    $order->save();
}

function wc_moadian_log_error($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WC Moadian] ' . sanitize_text_field($message));
    }
}

function wc_moadian_add_admin_pages() {
    add_options_page(
        __('Moadian Settings', 'wc-moadian'),
        __('Moadian Settings', 'wc-moadian'),
        'manage_options',
        'wc-moadian-settings',
        'wc_moadian_render_settings_page'
    );
    add_submenu_page(
        'woocommerce',
        __('Moadian Invoices', 'wc-moadian'),
        __('Moadian Invoices', 'wc-moadian'),
        'manage_woocommerce',
        'wc-moadian-invoices',
        'wc_moadian_render_invoices_page'
    );
}

function wc_moadian_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap wc-moadian-settings">
        <h1><?php esc_html_e('Moadian Settings', 'wc-moadian'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('wc_moadian_options'); ?>
            <?php do_settings_sections('wc-moadian-settings'); ?>
            <?php submit_button(); ?>
            <button type="button" class="button" id="wc-moadian-test-connection"><?php esc_html_e('Test connection', 'wc-moadian'); ?></button>
            <span id="wc-moadian-test-result" aria-live="polite"></span>
        </form>
    </div>
    <?php
}

function wc_moadian_render_invoices_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $orders = wc_get_orders(array(
        'limit' => 100,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => array(array('key' => '_moadian_invoice_status', 'compare' => 'EXISTS')),
    ));
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Moadian Invoices', 'wc-moadian'); ?></h1>
        <p><?php esc_html_e('Showing the 100 most recent WooCommerce orders with Moadian activity.', 'wc-moadian'); ?></p>
        <table class="widefat striped wc-moadian-invoices-table">
            <thead><tr>
                <th><?php esc_html_e('Order', 'wc-moadian'); ?></th>
                <th><?php esc_html_e('Tax ID', 'wc-moadian'); ?></th>
                <th><?php esc_html_e('Reference', 'wc-moadian'); ?></th>
                <th><?php esc_html_e('Status', 'wc-moadian'); ?></th>
                <th><?php esc_html_e('Submitted', 'wc-moadian'); ?></th>
                <th><?php esc_html_e('Message', 'wc-moadian'); ?></th>
                <th><?php esc_html_e('Action', 'wc-moadian'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (!$orders) : ?>
                <tr><td colspan="7"><?php esc_html_e('No invoices found.', 'wc-moadian'); ?></td></tr>
            <?php else : foreach ($orders as $order) :
                $status = $order->get_meta('_moadian_invoice_status', true) ?: 'queued';
                ?>
                <tr>
                    <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
                    <td><?php echo esc_html($order->get_meta('_moadian_tax_id', true) ?: '—'); ?></td>
                    <td><?php echo esc_html($order->get_meta('_moadian_reference_number', true) ?: '—'); ?></td>
                    <td class="status"><span class="status-<?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span></td>
                    <td><?php echo esc_html($order->get_meta('_moadian_submission_date', true) ?: '—'); ?></td>
                    <td class="message"><?php echo esc_html($order->get_meta('_moadian_response_message', true)); ?></td>
                    <td>
                        <?php if (in_array($status, array('failed', 'pending'), true)) : ?>
                            <button type="button" class="button-link wc-moadian-retry-button" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                                <?php echo esc_html($status === 'pending' ? __('Check status', 'wc-moadian') : __('Retry', 'wc-moadian')); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function wc_moadian_enqueue_admin_assets($hook) {
    if (!in_array($hook, array('settings_page_wc-moadian-settings', 'woocommerce_page_wc-moadian-invoices'), true)) {
        return;
    }
    wp_enqueue_script('jquery');
    $data = array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wc_moadian_admin'),
        'working' => __('Working…', 'wc-moadian'),
        'error' => __('The request failed.', 'wc-moadian'),
    );
    $script = 'jQuery(function($){var cfg=' . wp_json_encode($data) . ';'
        . '$(".wc-moadian-retry-button").on("click",function(){var b=$(this),r=b.closest("tr");b.prop("disabled",true).text(cfg.working);'
        . '$.post(cfg.ajaxUrl,{action:"wc_moadian_retry_invoice",nonce:cfg.nonce,order_id:b.data("order-id")})'
        . '.done(function(x){if(x&&x.data){r.find(".status").text(x.data.status);r.find(".message").text(x.data.message);}if(!x.success){alert((x.data&&x.data.message)||cfg.error);}})'
        . '.fail(function(){alert(cfg.error);}).always(function(){b.prop("disabled",false);});});'
        . '$("#wc-moadian-test-connection").on("click",function(){var b=$(this),o=$("#wc-moadian-test-result");b.prop("disabled",true);o.text(cfg.working);'
        . '$.post(cfg.ajaxUrl,{action:"wc_moadian_test_connection",nonce:cfg.nonce}).done(function(x){o.text((x.data&&x.data.message)||cfg.error);}).fail(function(){o.text(cfg.error);}).always(function(){b.prop("disabled",false);});});});';
    wp_add_inline_script('jquery', $script);
    wp_register_style('wc-moadian-admin', false, array(), WC_MOADIAN_VERSION);
    wp_enqueue_style('wc-moadian-admin');
    wp_add_inline_style('wc-moadian-admin', '.wc-moadian-settings .form-table th{width:230px}.status-success{color:#008a20}.status-failed{color:#b32d2e}.status-pending,.status-queued,.status-sending{color:#996800}#wc-moadian-test-result{margin-inline-start:10px}');
}

function wc_moadian_retry_invoice() {
    check_ajax_referer('wc_moadian_admin', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('You are not allowed to manage invoices.', 'wc-moadian')), 403);
    }
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Invalid order.', 'wc-moadian')), 400);
    }

    if ($order->get_meta('_moadian_invoice_status', true) === 'pending' && $order->get_meta('_moadian_reference_number', true)) {
        wc_moadian_inquire_invoice($order_id);
    } else {
        wc_moadian_send_invoice($order);
    }
    $order = wc_get_order($order_id);
    $payload = array(
        'status' => $order->get_meta('_moadian_invoice_status', true),
        'message' => $order->get_meta('_moadian_response_message', true),
    );
    if ($payload['status'] === 'failed') {
        wp_send_json_error($payload);
    }
    wp_send_json_success($payload);
}

function wc_moadian_test_connection() {
    check_ajax_referer('wc_moadian_admin', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You are not allowed to change these settings.', 'wc-moadian')), 403);
    }
    try {
        $client = wc_moadian_create_client();
        $token = wc_moadian_get_token($client, true);
        $client->get_fiscal_information($token);
        wp_send_json_success(array('message' => __('Connection and fiscal-memory authentication succeeded.', 'wc-moadian')));
    } catch (Throwable $exception) {
        wp_send_json_error(array('message' => $exception->getMessage()));
    }
}
