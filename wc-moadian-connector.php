<?php
/**
 * Plugin Name: WooCommerce Moadian Connector
 * Description: Integrates WooCommerce with the Iranian Tax System (Samaneh Moadian).
 * Version:     1.2.6
 * Author:      Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-moadian
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC tested up to: 9.6.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
define('WC_MOADIAN_VERSION', '1.2.6');
define('WC_MOADIAN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_MOADIAN_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Initialize encryption key after WordPress is fully loaded.
 */
function wc_moadian_init_encryption_key() {
    if (!defined('WC_MOADIAN_ENCRYPTION_KEY')) {
        if (function_exists('wp_salt')) {
            define('WC_MOADIAN_ENCRYPTION_KEY', wp_salt('secure_auth'));
        } else {
            // Fallback key to prevent fatal errors
            define('WC_MOADIAN_ENCRYPTION_KEY', 'fallback_moadian_key_' . md5(NONCE_KEY . AUTH_KEY));
            wc_moadian_log_error('wp_salt() is not available. Using fallback encryption key.');
        }
    }
}

/**
 * Check plugin requirements.
 */
function wc_moadian_check_requirements() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . __('WooCommerce Moadian Connector requires WooCommerce to be installed and active.', 'wc-moadian') . '</p></div>';
        });
        return false;
    }
    if (version_compare(PHP_VERSION, '7.2', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . __('WooCommerce Moadian Connector requires PHP 7.2 or higher.', 'wc-moadian') . '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Load plugin textdomain.
 */
function wc_moadian_load_textdomain() {
    load_plugin_textdomain('wc-moadian', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'wc_moadian_load_textdomain', 20);

/**
 * Initialize plugin hooks.
 */
function wc_moadian_init() {
    if (!wc_moadian_check_requirements()) {
        return;
    }

    // Initialize encryption key
    wc_moadian_init_encryption_key();

    add_action('admin_menu', 'wc_moadian_add_admin_pages', 1000);
    add_action('admin_init', 'wc_moadian_register_settings');
    add_action('woocommerce_order_status_completed', 'wc_moadian_handle_completed_order');
    add_action('admin_enqueue_scripts', 'wc_moadian_enqueue_admin_scripts');
    add_action('wp_ajax_wc_moadian_retry_invoice', 'wc_moadian_retry_invoice');

    // Debug: Check menu registration
    add_action('admin_notices', 'wc_moadian_menu_debug_notice');
}
add_action('plugins_loaded', 'wc_moadian_init', 20);

/**
 * Debug notice for menu visibility.
 */
function wc_moadian_menu_debug_notice() {
    global $submenu;
    $settings_menu_exists = false;
    $invoices_menu_exists = false;
    $woo_menu_details = '';

    // Check Settings menu
    if (isset($submenu['options-general.php'])) {
        foreach ($submenu['options-general.php'] as $item) {
            if ($item[2] === 'wc-moadian-settings') {
                $settings_menu_exists = true;
                break;
            }
        }
    }

    // Check WooCommerce submenu
    if (isset($submenu['woocommerce'])) {
        foreach ($submenu['woocommerce'] as $item) {
            if ($item[2] === 'wc-moadian-invoices') {
                $invoices_menu_exists = true;
                break;
            }
        }
        // Collect WooCommerce menu items for debugging
        $woo_menu_details = implode(', ', array_map(function($item) {
            return wp_strip_all_tags($item[0]);
        }, $submenu['woocommerce']));
    } else {
        $woo_menu_details = 'WooCommerce menu not found.';
    }

    if (!$settings_menu_exists && current_user_can('manage_options')) {
        echo '<div class="error"><p>' . __('Error: Moadian Settings menu is not visible. Check for plugin conflicts or permissions.', 'wc-moadian') . '</p></div>';
    }
    if (!$invoices_menu_exists && current_user_can('manage_options')) {
        echo '<div class="error"><p>' . sprintf(
            __('Error: Moadian Invoices menu is not visible in WooCommerce menu. WooCommerce version: %s. Registered WooCommerce menu items: %s. Check for plugin conflicts or permissions.', 'wc-moadian'),
            defined('WC_VERSION') ? WC_VERSION : 'Unknown',
            esc_html($woo_menu_details)
        ) . '</p></div>';
    }
}

/**
 * Enqueue admin scripts and styles inline.
 */
function wc_moadian_enqueue_admin_scripts($hook) {
    if (!in_array($hook, ['settings_page_wc-moadian-settings', 'woocommerce_page_wc-moadian-invoices'])) {
        return;
    }
    ?>
    <style>
        .wc-moadian-settings .nav-tab-wrapper { margin-bottom: 20px; }
        .wc-moadian-settings .form-table th { width: 200px; }
        .wc-moadian-settings .description { color: #666; font-size: 12px; }
        .wc-moadian-invoices-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .wc-moadian-invoices-table th, .wc-moadian-invoices-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .wc-moadian-invoices-table th { background-color: #f2f2f2; }
        .wc-moadian-invoices-table .status-success { color: green; }
        .wc-moadian-invoices-table .status-failed { color: red; }
        .wc-moadian-invoices-table .status-pending { color: orange; }
        .wc-moadian-retry-button { cursor: pointer; color: #0073aa; text-decoration: underline; }
        .wc-moadian-retry-button:hover { color: #005177; }
    </style>
    <script>
        jQuery(document).ready(function($) {
            $('.wc-moadian-retry-button').on('click', function(e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                var $row = $(this).closest('tr');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_moadian_retry_invoice',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('wc_moadian_retry_invoice'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.find('.status').html('<span class="status-' + response.data.status + '">' + response.data.status + '</span>');
                            alert('<?php _e('Invoice sent successfully.', 'wc-moadian'); ?>');
                        } else {
                            alert('<?php _e('Failed to retry invoice: ', 'wc-moadian'); ?>' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('<?php _e('An error occurred while retrying the invoice.', 'wc-moadian'); ?>');
                    }
                });
            });
        });
    </script>
    <?php
}

/**
 * Add admin pages (Settings and Invoices).
 */
function wc_moadian_add_admin_pages() {
    // Add Settings page under Settings
    add_options_page(
        __('Moadian Settings', 'wc-moadian'),
        __('Moadian Settings', 'wc-moadian'),
        'manage_options',
        'wc-moadian-settings',
        'wc_moadian_render_settings_page'
    );

    // Add Invoices page under WooCommerce
    global $submenu;
    if (!isset($submenu['woocommerce'])) {
        wc_moadian_log_error('WooCommerce menu not found when trying to add Moadian Invoices.');
        return;
    }

    if (!current_user_can('manage_options')) {
        wc_moadian_log_error('User lacks manage_options capability for Moadian Invoices menu.');
        return;
    }

    $hook = add_submenu_page(
        'woocommerce',
        __('Moadian Invoices', 'wc-moadian'),
        __('Moadian Invoices', 'wc-moadian'),
        'manage_options',
        'wc-moadian-invoices',
        'wc_moadian_render_invoices_page'
    );

    // Log result of menu registration
    if ($hook) {
        wc_moadian_log_error('Moadian Invoices menu added successfully with hook: ' . $hook);
    } else {
        wc_moadian_log_error('Failed to add Moadian Invoices menu.');
    }
}

/**
 * Render the settings page with tabs.
 */
function wc_moadian_render_settings_page() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    ?>
    <div class="wrap wc-moadian-settings">
        <h1><?php esc_html_e('Moadian Settings', 'wc-moadian'); ?></h1>
        <nav class="nav-tab-wrapper">
            <a href="?page=wc-moadian-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'wc-moadian'); ?></a>
            <a href="?page=wc-moadian-settings&tab=advanced" class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>"><?php _e('Advanced', 'wc-moadian'); ?></a>
        </nav>
        <form method="post" action="options.php">
            <?php
            settings_fields('wc_moadian_options');
            do_settings_sections('wc-moadian-settings-' . $active_tab);
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Render the invoices list page.
 */
function wc_moadian_render_invoices_page() {
    $invoices = wc_moadian_get_invoices();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Moadian Invoices', 'wc-moadian'); ?></h1>
        <table class="wc-moadian-invoices-table">
            <thead>
                <tr>
                    <th><?php _e('Order ID', 'wc-moadian'); ?></th>
                    <th><?php _e('Invoice Number', 'wc-moadian'); ?></th>
                    <th><?php _e('Status', 'wc-moadian'); ?></th>
                    <th><?php _e('Submission Date', 'wc-moadian'); ?></th>
                    <th><?php _e('Message', 'wc-moadian'); ?></th>
                    <th><?php _e('Actions', 'wc-moadian'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr><td colspan="6"><?php _e('No invoices found.', 'wc-moadian'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><?php echo esc_html($invoice['order_id']); ?></td>
                            <td><?php echo esc_html($invoice['invoice_number']); ?></td>
                            <td class="status"><span class="status-<?php echo esc_attr($invoice['status']); ?>"><?php echo esc_html($invoice['status']); ?></span></td>
                            <td><?php echo esc_html($invoice['submission_date']); ?></td>
                            <td><?php echo esc_html($invoice['message']); ?></td>
                            <td>
                                <?php if (in_array($invoice['status'], ['failed', 'pending'])): ?>
                                    <a href="#" class="wc-moadian-retry-button" data-order-id="<?php echo esc_attr($invoice['order_id']); ?>"><?php _e('Retry', 'wc-moadian'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Get invoices from order meta.
 */
function wc_moadian_get_invoices() {
    $args = [
        'post_type' => 'shop_order',
        'post_status' => 'any',
        'meta_query' => [
            [
                'key' => '_moadian_invoice_status',
                'compare' => 'EXISTS',
            ],
        ],
        'posts_per_page' => -1,
    ];
    $orders = get_posts($args);
    $invoices = [];

    foreach ($orders as $order) {
        $order_id = $order->ID;
        $invoices[] = [
            'order_id' => $order_id,
            'invoice_number' => get_post_meta($order_id, '_moadian_invoice_number', true) ?: 'N/A',
            'status' => get_post_meta($order_id, '_moadian_invoice_status', true) ?: 'pending',
            'submission_date' => get_post_meta($order_id, '_moadian_submission_date', true) ?: 'N/A',
            'message' => get_post_meta($order_id, '_moadian_response_message', true) ?: '',
        ];
    }

    return $invoices;
}

/**
 * Register plugin settings.
 */
function wc_moadian_register_settings() {
    register_setting('wc_moadian_options', 'wc_moadian_options', 'wc_moadian_sanitize_options');

    // General Settings
    add_settings_section('wc_moadian_general', __('General Settings', 'wc-moadian'), null, 'wc-moadian-settings-general');
    add_settings_field('env', __('Environment', 'wc-moadian'), 'wc_moadian_env_field', 'wc-moadian-settings-general', 'wc_moadian_general', ['label_for' => 'wc_moadian_env']);
    add_settings_field('private_key', __('Private Key (PEM)', 'wc-moadian'), 'wc_moadian_private_key_field', 'wc-moadian-settings-general', 'wc_moadian_general', ['label_for' => 'wc_moadian_private_key']);
    add_settings_field('economic_code', __('Economic Code', 'wc-moadian'), 'wc_moadian_economic_code_field', 'wc-moadian-settings-general', 'wc_moadian_general', ['label_for' => 'wc_moadian_economic_code']);

    // Advanced Settings
    add_settings_section('wc_moadian_advanced', __('Advanced Settings', 'wc-moadian'), null, 'wc-moadian-settings-advanced');
    add_settings_field('invoice_type', __('Default Invoice Type', 'wc-moadian'), 'wc_moadian_invoice_type_field', 'wc-moadian-settings-advanced', 'wc_moadian_advanced', ['label_for' => 'wc_moadian_invoice_type']);
}

/**
 * Settings fields.
 */
function wc_moadian_env_field() {
    $options = get_option('wc_moadian_options');
    $env = isset($options['env']) ? $options['env'] : 'sandbox';
    ?>
    <select id="wc_moadian_env" name="wc_moadian_options[env]">
        <option value="sandbox" <?php selected($env, 'sandbox'); ?>><?php _e('Sandbox', 'wc-moadian'); ?></option>
        <option value="production" <?php selected($env, 'production'); ?>><?php _e('Production', 'wc-moadian'); ?></option>
    </select>
    <p class="description"><?php _e('Select the environment for connecting to Moadian.', 'wc-moadian'); ?></p>
    <?php
}

function wc_moadian_private_key_field() {
    $options = get_option('wc_moadian_options');
    $private_key = isset($options['private_key']) ? openssl_decrypt($options['private_key'], 'AES-256-CBC', WC_MOADIAN_ENCRYPTION_KEY, 0, substr(WC_MOADIAN_ENCRYPTION_KEY, 0, 16)) : '';
    ?>
    <textarea id="wc_moadian_private_key" name="wc_moadian_options[private_key]" rows="6" cols="70"><?php echo esc_textarea($private_key); ?></textarea>
    <p class="description"><?php _e('Enter the private key (PEM format) provided by Moadian.', 'wc-moadian'); ?></p>
    <?php
}

function wc_moadian_economic_code_field() {
    $options = get_option('wc_moadian_options');
    $economic_code = isset($options['economic_code']) ? $options['economic_code'] : '';
    ?>
    <input id="wc_moadian_economic_code" type="text" name="wc_moadian_options[economic_code]" value="<?php echo esc_attr($economic_code); ?>" size="40">
    <p class="description"><?php _e('Enter your economic code registered with Moadian.', 'wc-moadian'); ?></p>
    <?php
}

function wc_moadian_invoice_type_field() {
    $options = get_option('wc_moadian_options');
    $invoice_type = isset($options['invoice_type']) ? $options['invoice_type'] : '1';
    ?>
    <select id="wc_moadian_invoice_type" name="wc_moadian_options[invoice_type]">
        <option value="1" <?php selected($invoice_type, '1'); ?>><?php _e('Type 1 - Standard', 'wc-moadian'); ?></option>
        <option value="2" <?php selected($invoice_type, '2'); ?>><?php _e('Type 2 - Simplified', 'wc-moadian'); ?></option>
    </select>
    <p class="description"><?php _e('Select the default invoice type for submissions.', 'wc-moadian'); ?></p>
    <?php
}

/**
 * Sanitize and encrypt options.
 */
function wc_moadian_sanitize_options($input) {
    $sanitized = [];
    $sanitized['env'] = isset($input['env']) && in_array($input['env'], ['sandbox', 'production']) ? $input['env'] : 'sandbox';
    $sanitized['economic_code'] = sanitize_text_field($input['economic_code'] ?? '');
    $sanitized['invoice_type'] = isset($input['invoice_type']) && in_array($input['invoice_type'], ['1', '2']) ? $input['invoice_type'] : '1';

    if (!empty($input['private_key'])) {
        $sanitized['private_key'] = openssl_encrypt($input['private_key'], 'AES-256-CBC', WC_MOADIAN_ENCRYPTION_KEY, 0, substr(WC_MOADIAN_ENCRYPTION_KEY, 0, 16));
    } else {
        $sanitized['private_key'] = get_option('wc_moadian_options')['private_key'] ?? '';
    }

    return $sanitized;
}

/**
 * Get the base API URL.
 */
function wc_moadian_get_api_url($endpoint) {
    $options = get_option('wc_moadian_options');
    $base = ($options['env'] === 'production') ? 'https://tp.tax.gov.ir' : 'https://sandbox.tp.tax.gov.ir';
    return $base . $endpoint;
}

/**
 * Authenticate and get JWT token with caching.
 */
function wc_moadian_authenticate() {
    $cached_token = get_transient('wc_moadian_token');
    if ($cached_token) {
        return $cached_token;
    }

    $options = get_option('wc_moadian_options');
    if (empty($options['private_key']) || empty($options['economic_code'])) {
        wc_moadian_log_error('Missing private key or economic code.');
        return false;
    }

    $private_key = openssl_decrypt($options['private_key'], 'AES-256-CBC', WC_MOADIAN_ENCRYPTION_KEY, 0, substr(WC_MOADIAN_ENCRYPTION_KEY, 0, 16));
    $challenge_url = wc_moadian_get_api_url('/api/auth/challenge');
    $sign_url = wc_moadian_get_api_url('/api/auth/token');

    $response = wp_remote_get($challenge_url, ['timeout' => 10]);
    if (is_wp_error($response)) {
        wc_moadian_log_error('Challenge request failed: ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $challenge = $body['challenge'] ?? '';
    if (!$challenge) {
        wc_moadian_log_error('No challenge received.');
        return false;
    }

    $private_key_resource = openssl_pkey_get_private($private_key);
    if (!$private_key_resource) {
        wc_moadian_log_error('Invalid private key.');
        return false;
    }

    openssl_sign($challenge, $signature, $private_key_resource, OPENSSL_ALGO_SHA256);
    $signed = base64_encode($signature);

    $token_response = wp_remote_post($sign_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'signedChallenge' => $signed,
            'economicCode' => $options['economic_code'],
        ]),
        'timeout' => 10,
    ]);

    if (is_wp_error($token_response)) {
        wc_moadian_log_error('Token request failed: ' . $token_response->get_error_message());
        return false;
    }

    $token_body = json_decode(wp_remote_retrieve_body($token_response), true);
    $token = $token_body['token'] ?? false;
    if ($token) {
        set_transient('wc_moadian_token', $token, HOUR_IN_SECONDS); // Cache for 1 hour
    } else {
        wc_moadian_log_error('No token received.');
    }

    return $token;
}

/**
 * Log errors to debug.log and show admin notice.
 */
function wc_moadian_log_error($message) {
    if (WP_DEBUG) {
        error_log('[WC Moadian] ' . $message);
    }
    add_action('admin_notices', function() use ($message) {
        echo '<div class="error"><p>' . esc_html__('Moadian Error: ', 'wc-moadian') . esc_html($message) . '</p></div>';
    });
}

/**
 * Send invoice to Moadian.
 */
function wc_moadian_send_invoice($order_id) {
    $token = wc_moadian_authenticate();
    if (!$token) {
        wc_moadian_log_error('Authentication failed for order ' . $order_id);
        update_post_meta($order_id, '_moadian_invoice_status', 'failed');
        update_post_meta($order_id, '_moadian_response_message', __('Authentication failed.', 'wc-moadian'));
        return false;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wc_moadian_log_error('Invalid order ID: ' . $order_id);
        update_post_meta($order_id, '_moadian_invoice_status', 'failed');
        update_post_meta($order_id, '_moadian_response_message', __('Invalid order.', 'wc-moadian'));
        return false;
    }

    $options = get_option('wc_moadian_options');
    $items = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $items[] = [
            'description' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'unitPrice' => (float) $product->get_price(),
            'totalAmount' => (float) $item->get_total(),
            'taxRate' => 9, // Example: VAT 9% (adjust as needed)
        ];
    }

    $invoice = [
        'invoiceNumber' => $order->get_order_number(),
        'invoiceType' => $options['invoice_type'] ?? '1',
        'issueDate' => current_time('Y-m-d\TH:i:s'),
        'amount' => (float) $order->get_total(),
        'taxAmount' => (float) $order->get_total_tax(),
        'buyerNationalId' => get_post_meta($order_id, '_billing_national_id', true) ?: '0000000000',
        'items' => $items,
    ];

    $invoice = apply_filters('wc_moadian_invoice_data', $invoice, $order);

    $send_url = wc_moadian_get_api_url('/api/invoice/send');
    $response = wp_remote_post($send_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([$invoice]),
        'timeout' => 15,
    ]);

    update_post_meta($order_id, '_moadian_invoice_number', $invoice['invoiceNumber']);
    update_post_meta($order_id, '_moadian_submission_date', current_time('Y-m-d H:i:s'));

    if (is_wp_error($response)) {
        wc_moadian_log_error('Invoice submission failed for order ' . $order_id . ': ' . $response->get_error_message());
        update_post_meta($order_id, '_moadian_invoice_status', 'failed');
        update_post_meta($order_id, '_moadian_response_message', $response->get_error_message());
        return false;
    }

    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($response_body['success']) && $response_body['success']) {
        update_post_meta($order_id, '_moadian_invoice_id', $response_body['invoiceId'] ?? '');
        update_post_meta($order_id, '_moadian_invoice_status', 'success');
        update_post_meta($order_id, '_moadian_response_message', __('Invoice sent successfully.', 'wc-moadian'));
        return true;
    } else {
        $message = $response_body['message'] ?? 'Unknown error';
        wc_moadian_log_error('Invoice submission failed for order ' . $order_id . ': ' . $message);
        update_post_meta($order_id, '_moadian_invoice_status', 'failed');
        update_post_meta($order_id, '_moadian_response_message', $message);
        return false;
    }
}

/**
 * Handle WooCommerce completed orders.
 */
function wc_moadian_handle_completed_order($order_id) {
    $status = get_post_meta($order_id, '_moadian_invoice_status', true);
    if ($status && $status === 'success') {
        return; // Skip if already sent successfully
    }

    wc_moadian_send_invoice($order_id);
}

/**
 * Handle AJAX retry invoice request.
 */
function wc_moadian_retry_invoice() {
    check_ajax_referer('wc_moadian_retry_invoice', 'nonce');

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(['message' => __('Invalid order ID.', 'wc-moadian')]);
    }

    $result = wc_moadian_send_invoice($order_id);
    if ($result) {
        wp_send_json_success([
            'status' => 'success',
            'message' => __('Invoice sent successfully.', 'wc-moadian'),
        ]);
    } else {
        wp_send_json_error([
            'status' => 'failed',
            'message' => get_post_meta($order_id, '_moadian_response_message', true) ?: __('Failed to send invoice.', 'wc-moadian'),
        ]);
    }
}
?>