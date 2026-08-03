<?php

define('ABSPATH', __DIR__ . '/');

function trailingslashit($value) {
    return rtrim($value, '/\\') . '/';
}

function wp_json_encode($value) {
    return json_encode($value);
}

function __($value) {
    return $value;
}

function apply_filters($hook, $value) {
    return $value;
}

function wc_get_product($product_id) {
    return null;
}

function is_wp_error($value) {
    return false;
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'];
}

function wp_remote_retrieve_body($response) {
    return $response['body'];
}

function wp_remote_post($url, $args) {
    $GLOBALS['wc_moadian_last_request'] = array('url' => $url, 'args' => $args);
    if (substr($url, -9) === 'GET_TOKEN') {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'result' => array('data' => array('token' => 'test-token', 'expiresIn' => 2000000000000)),
            )),
        );
    }
    return array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'result' => array(array(
                'uid' => 'test-uid',
                'referenceNumber' => 'test-reference',
                'errorCode' => null,
                'errorDetail' => null,
            )),
        )),
    );
}

require_once dirname(__DIR__) . '/includes/class-wc-moadian-api-client.php';
require_once dirname(__DIR__) . '/includes/class-wc-moadian-invoice-builder.php';

class WC_Order {
    private $items;

    public function __construct($items) {
        $this->items = $items;
    }

    public function get_items($type) {
        return $type === 'line_item' ? $this->items : array();
    }

    public function get_total() {
        return 109000;
    }

    public function get_meta($key, $single = true) {
        return '';
    }

    public function get_billing_postcode() {
        return '';
    }
}

class Test_Moadian_Product {
    public function get_meta($key, $single = true) {
        return $key === '_moadian_service_id' ? '2720000166053' : '1627';
    }

    public function is_type($type) {
        return false;
    }
}

class Test_Moadian_Order_Item {
    private $taxes;

    public function __construct($taxes = array('total' => array(1 => 9000))) {
        $this->taxes = $taxes;
    }

    public function get_product() {
        return new Test_Moadian_Product();
    }

    public function get_name() {
        return 'Test product';
    }

    public function get_quantity() {
        return 2;
    }

    public function get_subtotal() {
        return 100000;
    }

    public function get_total() {
        return 100000;
    }

    public function get_total_tax() {
        return 9000;
    }

    public function get_taxes() {
        return $this->taxes;
    }
}

$assertions = 0;

function assert_same($expected, $actual, $message) {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_true($actual, $message) {
    assert_same(true, (bool) $actual, $message);
}

assert_same(
    '700#FGB#ABC#12.94',
    WC_Moadian_API_Client::normalize(array(
        'KD' => 12.94,
        'KB' => 'ABC',
        'KA' => array('B' => 'FGB', 'A' => '700'),
    )),
    'SimpleNormalizer must flatten and sort fields.'
);

assert_same(
    '1#2#3#4#ABC#12.94',
    WC_Moadian_API_Client::normalize(array(
        'KD' => 12.94,
        'KB' => 'ABC',
        'KA' => array(array('B' => 2, 'A' => 1), array('A' => 3, 'B' => 4)),
    )),
    'SimpleNormalizer must preserve numeric list positions.'
);

$invoice_id_vectors = array(
    array('DEF5GH', '2020-07-20 01:00:10', 12, 'DEF5GH0481F000000000C2'),
    array('DEF5GH', '2020-07-20 08:30:30', 8173, 'DEF5GH0481F0000001FED8'),
    array('DEF5GH', '2020-07-20 23:11:12', 2572613409, 'DEF5GH0481F009956F7211'),
);
foreach ($invoice_id_vectors as $vector) {
    assert_same(
        $vector[3],
        WC_Moadian_API_Client::generate_tax_id(
            $vector[0],
            $vector[2],
            new DateTimeImmutable($vector[1], new DateTimeZone('UTC'))
        ),
        'Tax ID generation must match the SDK vector.'
    );
}

$builder = new WC_Moadian_Invoice_Builder(array(
    'fiscal_id' => 'DEF5GH',
    'economic_code' => '12345678901',
    'invoice_type' => '2',
    'amount_multiplier' => '1',
));
$built_invoice = $builder->build(
    new WC_Order(array(new Test_Moadian_Order_Item())),
    (new DateTimeImmutable('2020-07-20 01:00:10', new DateTimeZone('UTC')))->getTimestamp() * 1000,
    12
);
assert_same('DEF5GH0481F000000000C2', $built_invoice['tax_id'], 'WooCommerce mapping must use the protocol tax-ID generator.');
assert_same(109000, $built_invoice['invoice']['header']['tbill'], 'Header bill total must equal mapped line totals.');
assert_same(50000, $built_invoice['invoice']['body'][0]['fee'], 'Unit fee must use stored order subtotal values.');
assert_same(9.0, $built_invoice['invoice']['body'][0]['vra'], 'VAT rate must be derived from stored order tax values.');
assert_same(null, $built_invoice['invoice']['header']['bid'], 'Type-2 invoices must not fabricate buyer identity.');

$multiple_tax_failed = false;
try {
    $builder->build(
        new WC_Order(array(new Test_Moadian_Order_Item(array('total' => array(1 => 4000, 2 => 5000))))),
        (new DateTimeImmutable('2020-07-20 01:00:10', new DateTimeZone('UTC')))->getTimestamp() * 1000,
        12
    );
} catch (WC_Moadian_Invoice_Exception $exception) {
    $multiple_tax_failed = true;
}
assert_true($multiple_tax_failed, 'Ambiguous multi-tax WooCommerce lines must fail instead of being silently misreported.');

$key_resource = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($key_resource, $private_key);
$public_key = openssl_pkey_get_details($key_resource)['key'];
$client = new WC_Moadian_API_Client(array(
    'base_url' => 'https://example.test/',
    'private_key' => $private_key,
    'fiscal_id' => 'A1B2C3',
    'tax_org_public_key' => $public_key,
    'tax_org_key_id' => 'key-id',
));

$invoice = array(
    'header' => array('taxid' => 'A1B2C3TEST', 'tbill' => 109000),
    'body' => array(array('sstid' => '2720000166053', 'am' => 2, 'fee' => 50000)),
    'payments' => array(),
    'extension' => null,
);
$response = $client->send_invoice($invoice, 'access-token', 'test-uid');
assert_same('test-reference', $response['result'][0]['referenceNumber'], 'The client must return the decoded submission response.');

$captured = $GLOBALS['wc_moadian_last_request'];
assert_same(
    'https://example.test/req/api/self-tsp/async/normal-enqueue',
    $captured['url'],
    'Invoice submission must use the documented self-TSP route.'
);
$request_body = json_decode($captured['args']['body'], true);
$packet = $request_body['packets'][0];
assert_same('INVOICE.V01', $packet['packetType'], 'Invoice packet type must be INVOICE.V01.');
assert_same('key-id', $packet['encryptionKeyId'], 'Invoice packet must identify the Tax Organization encryption key.');

$data_signature_valid = openssl_verify(
    WC_Moadian_API_Client::normalize($invoice),
    base64_decode($packet['dataSignature']),
    $public_key,
    OPENSSL_ALGO_SHA256
);
assert_same(1, $data_signature_valid, 'The invoice data signature must verify with the matching public key.');

$headers = $captured['args']['headers'];
$content_type = $headers['Content-Type'];
unset($headers['Content-Type']);
assert_same('application/json', $content_type, 'HTTP requests must declare JSON without signing the transport-only content type.');
$signature_headers = $headers;
$signature_headers['Authorization'] = 'access-token';
$request_normalized = WC_Moadian_API_Client::normalize(array_merge(
    array('packets' => array(array($packet))),
    $signature_headers
));
assert_same(
    1,
    openssl_verify($request_normalized, base64_decode($request_body['signature']), $public_key, OPENSSL_ALGO_SHA256),
    'The batch request signature must include the packets and essential headers.'
);

$token_response = $client->authenticate();
assert_same('test-token', $token_response['token'], 'GET_TOKEN must return the access token.');
$captured_token_request = $GLOBALS['wc_moadian_last_request'];
assert_same(
    'https://example.test/req/api/self-tsp/sync/GET_TOKEN',
    $captured_token_request['url'],
    'Authentication must use the documented sync GET_TOKEN route.'
);
$token_body = json_decode($captured_token_request['args']['body'], true);
$token_headers = $captured_token_request['args']['headers'];
unset($token_headers['Content-Type']);
assert_true(!isset($token_headers['Authorization']), 'GET_TOKEN must not send an Authorization header.');
assert_same(
    1,
    openssl_verify(
        WC_Moadian_API_Client::normalize(array_merge($token_body['packet'], $token_headers)),
        base64_decode($token_body['signature']),
        $public_key,
        OPENSSL_ALGO_SHA256
    ),
    'The sync token packet signature must include only packet and essential protocol headers.'
);

$reference_autoload = '/private/tmp/wc-moadian-review-deps/moadian-master/vendor/autoload.php';
if (is_file($reference_autoload)) {
    require_once $reference_autoload;
    $rsa = \phpseclib3\Crypt\RSA::loadPrivateKey($private_key);
    $aes_hex = $rsa->decrypt(base64_decode($packet['symmetricKey']));
    assert_same(64, strlen($aes_hex), 'RSA-OAEP-256 must unwrap a 32-byte AES key encoded as hex.');
    $aes_key = hex2bin($aes_hex);
    $reference_encryption = new \SnappMarketPro\Moadian\Services\EncryptionService($public_key, 'key-id');
    $plaintext = $reference_encryption->decrypt($packet['data'], $aes_key, hex2bin($packet['iv']), 16);
    assert_same($invoice, json_decode($plaintext, true), 'The encrypted packet must decrypt to the original invoice.');
}

echo 'OK (' . $assertions . " assertions)\n";
