<?php
/**
 * Plugin Name: WooCommerce Moadian Connector
 * Plugin URI:  https://ildrm.com/plugins/woocommerce-moadian-connector
 * Description: Integrates WooCommerce with the Iranian Tax System (Samaneh Moadian).
 * Version:     1.0.0
 * Author:      Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-moadian
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'WC_MOADIAN_VERSION', '1.0.0' );
define( 'WC_MOADIAN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_MOADIAN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin textdomain.
 */
function wc_moadian_load_textdomain() {
	load_plugin_textdomain( 'wc-moadian', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'wc_moadian_load_textdomain' );

/**
 * Initialize plugin hooks.
 */
function wc_moadian_init() {
	add_action( 'admin_menu', 'wc_moadian_add_settings_page' );
	add_action( 'admin_init', 'wc_moadian_register_settings' );
	add_action( 'woocommerce_order_status_completed', 'wc_moadian_handle_completed_order' );
}
add_action( 'plugins_loaded', 'wc_moadian_init' );

/**
 * Add plugin settings page.
 */
function wc_moadian_add_settings_page() {
	add_options_page(
		__( 'Moadian Settings', 'wc-moadian' ),
		__( 'Moadian Settings', 'wc-moadian' ),
		'manage_options',
		'wc-moadian-settings',
		'wc_moadian_render_settings_page'
	);
}

/**
 * Render the settings page.
 */
function wc_moadian_render_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Moadian Settings', 'wc-moadian' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'wc_moadian_options' );
			do_settings_sections( 'wc-moadian-settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Register plugin settings.
 */
function wc_moadian_register_settings() {
	register_setting( 'wc_moadian_options', 'wc_moadian_options', 'wc_moadian_sanitize_options' );

	add_settings_section( 'wc_moadian_main', '', null, 'wc-moadian-settings' );

	add_settings_field( 'env', __( 'Environment', 'wc-moadian' ), 'wc_moadian_env_field', 'wc-moadian-settings', 'wc_moadian_main' );
	add_settings_field( 'private_key', __( 'Private Key (PEM)', 'wc-moadian' ), 'wc_moadian_private_key_field', 'wc-moadian-settings', 'wc_moadian_main' );
	add_settings_field( 'economic_code', __( 'Economic Code', 'wc-moadian' ), 'wc_moadian_economic_code_field', 'wc-moadian-settings', 'wc_moadian_main' );
}

function wc_moadian_env_field() {
	$options = get_option( 'wc_moadian_options' );
	$env = isset( $options['env'] ) ? $options['env'] : 'sandbox';
	echo '<select name="wc_moadian_options[env]">';
	echo '<option value="sandbox" ' . selected( $env, 'sandbox', false ) . '>' . __( 'Sandbox', 'wc-moadian' ) . '</option>';
	echo '<option value="production" ' . selected( $env, 'production', false ) . '>' . __( 'Production', 'wc-moadian' ) . '</option>';
	echo '</select>';
}

function wc_moadian_private_key_field() {
	$options = get_option( 'wc_moadian_options' );
	echo '<textarea name="wc_moadian_options[private_key]" rows="6" cols="70">' . esc_textarea( $options['private_key'] ?? '' ) . '</textarea>';
}

function wc_moadian_economic_code_field() {
	$options = get_option( 'wc_moadian_options' );
	echo '<input type="text" name="wc_moadian_options[economic_code]" value="' . esc_attr( $options['economic_code'] ?? '' ) . '" size="40">';
}

function wc_moadian_sanitize_options( $input ) {
	return array_map( 'sanitize_text_field', $input );
}

/**
 * Get the base API URL.
 */
function wc_moadian_get_api_url( $endpoint ) {
	$options = get_option( 'wc_moadian_options' );
	$base    = ( $options['env'] === 'production' ) ? 'https://tp.tax.gov.ir' : 'https://sandbox.tp.tax.gov.ir';
	return $base . $endpoint;
}

/**
 * Authenticate and get JWT token.
 */
function wc_moadian_authenticate() {
	$options = get_option( 'wc_moadian_options' );
	$challenge_url = wc_moadian_get_api_url( '/api/auth/challenge' );
	$sign_url      = wc_moadian_get_api_url( '/api/auth/token' );

	$response = wp_remote_get( $challenge_url );
	if ( is_wp_error( $response ) ) return false;

	$body     = json_decode( wp_remote_retrieve_body( $response ), true );
	$challenge = $body['challenge'] ?? '';
	if ( ! $challenge ) return false;

	$private_key = openssl_pkey_get_private( $options['private_key'] );
	if ( ! $private_key ) return false;

	openssl_sign( $challenge, $signature, $private_key, OPENSSL_ALGO_SHA256 );
	$signed = base64_encode( $signature );

	$token_response = wp_remote_post( $sign_url, [
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => json_encode([
			'signedChallenge' => $signed,
			'economicCode'    => $options['economic_code'],
		]),
	] );

	if ( is_wp_error( $token_response ) ) return false;
	$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
	return $token_body['token'] ?? false;
}

/**
 * Handle WooCommerce completed orders.
 */
function wc_moadian_handle_completed_order( $order_id ) {
	$token = wc_moadian_authenticate();
	if ( ! $token ) return;

	$order = wc_get_order( $order_id );
	$invoice = [
		'invoiceNumber'   => $order->get_order_number(),
		'amount'          => (float) $order->get_total(),
		'date'            => current_time( 'Y-m-d\TH:i:s' ),
		'buyerNationalId' => '0000000000', // Replace with actual buyer info
	];

	$send_url = wc_moadian_get_api_url( '/api/invoice/send' );
	$response = wp_remote_post( $send_url, [
		'headers' => [
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json'
		],
		'body' => json_encode( [ $invoice ] ),
	] );

	// Optional: Store response data to order meta
}
