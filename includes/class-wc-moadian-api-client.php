<?php
/**
 * Low-level client for the Moadian self-TSP API documented by INTA.
 *
 * The protocol implementation is dependency-free so the WordPress plugin can
 * be installed without running Composer. It implements SimpleNormalizer,
 * RSA-SHA256 signatures, RSA-OAEP-256 key wrapping, and AES-256-GCM payload
 * encryption as required by the no-certificate SDK protocol.
 *
 * @package WC_Moadian
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Moadian_API_Exception extends RuntimeException {
    /** @var int */
    private $http_status;

    /** @var array */
    private $response_data;

    public function __construct($message, $http_status = 0, $response_data = array()) {
        parent::__construct($message);
        $this->http_status = (int) $http_status;
        $this->response_data = is_array($response_data) ? $response_data : array();
    }

    public function get_http_status() {
        return $this->http_status;
    }

    public function get_response_data() {
        return $this->response_data;
    }
}

class WC_Moadian_API_Client {
    const PACKET_GET_TOKEN = 'GET_TOKEN';
    const PACKET_SERVER_INFORMATION = 'GET_SERVER_INFORMATION';
    const PACKET_FISCAL_INFORMATION = 'GET_FISCAL_INFORMATION';
    const PACKET_ECONOMIC_CODE = 'GET_ECONOMIC_CODE_INFORMATION';
    const PACKET_INVOICE = 'INVOICE.V01';
    const PACKET_INQUIRY_REFERENCE = 'INQUIRY_BY_REFERENCE_NUMBER';

    /** @var string */
    private $base_url;

    /** @var string */
    private $private_key;

    /** @var string */
    private $fiscal_id;

    /** @var string */
    private $tax_org_public_key;

    /** @var string */
    private $tax_org_key_id;

    /** @var int */
    private $timeout;

    public function __construct(array $config) {
        $this->base_url = trailingslashit((string) ($config['base_url'] ?? 'https://tp.tax.gov.ir/'));
        $this->private_key = (string) ($config['private_key'] ?? '');
        $this->fiscal_id = strtoupper((string) ($config['fiscal_id'] ?? ''));
        $this->tax_org_public_key = (string) ($config['tax_org_public_key'] ?? '');
        $this->tax_org_key_id = (string) ($config['tax_org_key_id'] ?? '');
        $this->timeout = max(5, (int) ($config['timeout'] ?? 30));

        if (!preg_match('/^[A-Z0-9]{6}$/', $this->fiscal_id)) {
            throw new InvalidArgumentException('Fiscal memory ID must contain exactly six uppercase letters or digits.');
        }

        if (!openssl_pkey_get_private($this->private_key)) {
            throw new InvalidArgumentException('The configured Moadian private key is invalid.');
        }
    }

    public function get_server_information() {
        $packet = $this->create_packet(self::PACKET_SERVER_INFORMATION, null, '', false);

        return $this->send_sync_packet(self::PACKET_SERVER_INFORMATION, $packet, '', false);
    }

    public function authenticate() {
        $packet = $this->create_packet(
            self::PACKET_GET_TOKEN,
            array('username' => $this->fiscal_id),
            $this->fiscal_id,
            false
        );
        $response = $this->send_sync_packet(self::PACKET_GET_TOKEN, $packet, '', false);
        $data = $response['result']['data'] ?? array();

        if (empty($data['token'])) {
            throw new WC_Moadian_API_Exception('The token response did not contain an access token.', 0, $response);
        }

        return array(
            'token' => (string) $data['token'],
            'expires_in' => isset($data['expiresIn']) ? (int) $data['expiresIn'] : 0,
        );
    }

    public function send_invoice(array $invoice, $token, $uid, $retry = false) {
        $this->require_encryption_key();

        $packet = $this->create_packet(
            self::PACKET_INVOICE,
            $invoice,
            $this->fiscal_id,
            (bool) $retry,
            (string) $uid
        );

        $packet['dataSignature'] = $this->sign(self::normalize($invoice));
        $packet = $this->encrypt_packet($packet);
        $headers = $this->essential_headers($token, true);
        $signature_headers = $headers;
        $signature_headers['Authorization'] = preg_replace('/^Bearer\s+/i', '', $signature_headers['Authorization']);

        // The extra list level is part of the SDK's batch normalization format.
        $normalized_request = self::normalize(array_merge(array('packets' => array(array($packet))), $signature_headers));
        $content = array(
            'packets' => array($packet),
            'signature' => $this->sign($normalized_request),
            'signatureKeyId' => null,
        );

        return $this->request('async/normal-enqueue', $content, $headers);
    }

    public function inquire_by_reference_number($reference_number, $token) {
        $packet = $this->create_packet(
            self::PACKET_INQUIRY_REFERENCE,
            array('referenceNumber' => array((string) $reference_number)),
            $this->fiscal_id,
            false
        );

        return $this->send_sync_packet(self::PACKET_INQUIRY_REFERENCE, $packet, $token, true);
    }

    public function get_fiscal_information($token) {
        $packet = $this->create_packet(
            self::PACKET_FISCAL_INFORMATION,
            $this->fiscal_id,
            $this->fiscal_id,
            false
        );

        return $this->send_sync_packet(self::PACKET_FISCAL_INFORMATION, $packet, $token, true);
    }

    public function get_economic_code_information($economic_code, $token) {
        $packet = $this->create_packet(
            self::PACKET_ECONOMIC_CODE,
            array('economicCode' => (string) $economic_code),
            $this->fiscal_id,
            false
        );

        return $this->send_sync_packet(self::PACKET_ECONOMIC_CODE, $packet, $token, true);
    }

    public static function generate_tax_id($fiscal_id, $serial, DateTimeInterface $created_at) {
        $fiscal_id = strtoupper((string) $fiscal_id);
        $serial = (int) $serial;

        if (!preg_match('/^[A-Z0-9]{6}$/', $fiscal_id)) {
            throw new InvalidArgumentException('Fiscal memory ID must contain exactly six uppercase letters or digits.');
        }
        if ($serial < 0 || strlen((string) $serial) > 12) {
            throw new InvalidArgumentException('Invoice serial must be a non-negative number with at most 12 digits.');
        }

        $days = (int) floor($created_at->getTimestamp() / 86400);
        $hex_days = str_pad(dechex($days), 5, '0', STR_PAD_LEFT);
        $hex_serial = str_pad(dechex($serial), 10, '0', STR_PAD_LEFT);
        $numeric_fiscal_id = '';

        foreach (str_split($fiscal_id) as $character) {
            $numeric_fiscal_id .= ctype_digit($character) ? $character : (string) ord($character);
        }

        $control_text = $numeric_fiscal_id
            . str_pad((string) $days, 6, '0', STR_PAD_LEFT)
            . str_pad((string) $serial, 12, '0', STR_PAD_LEFT);

        return strtoupper($fiscal_id . $hex_days . $hex_serial . self::verhoeff_checksum($control_text));
    }

    public static function normalize(array $data) {
        $flattened = self::flatten($data);
        ksort($flattened, SORT_STRING);
        $values = array();

        foreach ($flattened as $value) {
            if (is_bool($value)) {
                $normalized = $value ? 'true' : 'false';
            } elseif ($value === '' || $value === null) {
                $normalized = '#';
            } else {
                $normalized = str_replace('#', '##', (string) $value);
            }
            $values[] = $normalized;
        }

        return implode('#', $values);
    }

    private function send_sync_packet($packet_type, array $packet, $token, $authorization_required) {
        $headers = $this->essential_headers($token, $authorization_required);
        $signature_headers = $headers;

        if (!empty($signature_headers['Authorization'])) {
            $signature_headers['Authorization'] = preg_replace('/^Bearer\s+/i', '', $signature_headers['Authorization']);
        }

        $content = array(
            'packet' => $packet,
            'signature' => $this->sign(self::normalize(array_merge($packet, $signature_headers))),
        );

        return $this->request('sync/' . rawurlencode($packet_type), $content, $headers);
    }

    private function create_packet($packet_type, $data, $fiscal_id, $retry, $uid = '') {
        return array(
            'uid' => $uid !== '' ? $uid : self::uuid_v4(),
            'packetType' => (string) $packet_type,
            'retry' => (bool) $retry,
            'data' => $data,
            'encryptionKeyId' => '',
            'symmetricKey' => '',
            'iv' => '',
            'fiscalId' => (string) $fiscal_id,
            'dataSignature' => '',
        );
    }

    private function encrypt_packet(array $packet) {
        $aes_key = random_bytes(32);
        $aes_key_hex = bin2hex($aes_key);
        $iv = random_bytes(16);
        $tag = '';
        $plain_json = self::json_encode($packet['data']);
        $xored_plaintext = self::xor_bytes($plain_json, $aes_key);
        $ciphertext = openssl_encrypt(
            $xored_plaintext,
            'aes-256-gcm',
            $aes_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Failed to encrypt the Moadian invoice payload.');
        }

        $packet['data'] = base64_encode($ciphertext . $tag);
        $packet['encryptionKeyId'] = $this->tax_org_key_id;
        $packet['symmetricKey'] = base64_encode($this->rsa_oaep_sha256_encrypt($aes_key_hex));
        $packet['iv'] = bin2hex($iv);

        return $packet;
    }

    private function rsa_oaep_sha256_encrypt($message) {
        $public_key = openssl_pkey_get_public($this->format_public_key($this->tax_org_public_key));
        if (!$public_key) {
            throw new InvalidArgumentException('The configured Tax Organization public key is invalid.');
        }

        $details = openssl_pkey_get_details($public_key);
        $modulus_bytes = isset($details['bits']) ? (int) ceil($details['bits'] / 8) : 0;
        $hash_length = 32;
        $message_length = strlen($message);

        if ($modulus_bytes < (2 * $hash_length + 2) || $message_length > ($modulus_bytes - 2 * $hash_length - 2)) {
            throw new RuntimeException('The Tax Organization RSA key is too small for OAEP-256 encryption.');
        }

        $label_hash = hash('sha256', '', true);
        $padding = str_repeat("\0", $modulus_bytes - $message_length - 2 * $hash_length - 2);
        $data_block = $label_hash . $padding . "\1" . $message;
        $seed = random_bytes($hash_length);
        $masked_data_block = self::xor_bytes($data_block, self::mgf1($seed, $modulus_bytes - $hash_length - 1));
        $masked_seed = self::xor_bytes($seed, self::mgf1($masked_data_block, $hash_length));
        $encoded = "\0" . $masked_seed . $masked_data_block;
        $encrypted = '';

        if (!openssl_public_encrypt($encoded, $encrypted, $public_key, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('Failed to wrap the invoice encryption key.');
        }

        return $encrypted;
    }

    private function sign($text) {
        $signature = '';
        $key = openssl_pkey_get_private($this->private_key);

        if (!$key || !openssl_sign((string) $text, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign the Moadian request.');
        }

        return base64_encode($signature);
    }

    private function essential_headers($token, $authorization_required) {
        $headers = array(
            'timestamp' => (string) floor(microtime(true) * 1000),
            'requestTraceId' => self::uuid_v4(),
        );

        if ($authorization_required) {
            if ($token === '') {
                throw new InvalidArgumentException('An access token is required for this request.');
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private function request($path, array $content, array $headers) {
        $url = $this->base_url . 'req/api/self-tsp/' . ltrim($path, '/');
        $http_headers = array_merge(array('Content-Type' => 'application/json'), $headers);
        $response = wp_remote_post(
            $url,
            array(
                'headers' => $http_headers,
                'body' => self::json_encode($content),
                'timeout' => $this->timeout,
                'data_format' => 'body',
            )
        );

        if (is_wp_error($response)) {
            throw new WC_Moadian_API_Exception($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        if (!is_array($body)) {
            throw new WC_Moadian_API_Exception('Moadian returned an invalid JSON response.', $status);
        }

        if ($status < 200 || $status >= 300) {
            throw new WC_Moadian_API_Exception(self::response_message($body), $status, $body);
        }

        return $body;
    }

    private function require_encryption_key() {
        if ($this->tax_org_public_key === '' || $this->tax_org_key_id === '') {
            throw new InvalidArgumentException('Tax Organization public key and key ID are required to send invoices.');
        }
    }

    private function format_public_key($key) {
        $key = trim((string) $key);
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }

        $key = preg_replace('/\s+/', '', $key);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function flatten(array $data, $prefix = '') {
        $result = array();

        foreach ($data as $key => $value) {
            $flat_key = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $result = array_merge($result, self::flatten($value, $flat_key));
            } else {
                $result[$flat_key] = $value;
            }
        }

        return $result;
    }

    private static function mgf1($seed, $length) {
        $mask = '';
        for ($counter = 0; strlen($mask) < $length; $counter++) {
            $mask .= hash('sha256', $seed . pack('N', $counter), true);
        }
        return substr($mask, 0, $length);
    }

    private static function xor_bytes($source, $key) {
        $result = '';
        $key_length = strlen($key);
        $source_length = strlen($source);

        for ($index = 0; $index < $source_length; $index++) {
            $result .= $source[$index] ^ $key[$index % $key_length];
        }

        return $result;
    }

    private static function uuid_v4() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function verhoeff_checksum($number) {
        $multiplication = array(
            array(0,1,2,3,4,5,6,7,8,9), array(1,2,3,4,0,6,7,8,9,5),
            array(2,3,4,0,1,7,8,9,5,6), array(3,4,0,1,2,8,9,5,6,7),
            array(4,0,1,2,3,9,5,6,7,8), array(5,9,8,7,6,0,4,3,2,1),
            array(6,5,9,8,7,1,0,4,3,2), array(7,6,5,9,8,2,1,0,4,3),
            array(8,7,6,5,9,3,2,1,0,4), array(9,8,7,6,5,4,3,2,1,0),
        );
        $permutation = array(
            array(0,1,2,3,4,5,6,7,8,9), array(1,5,7,6,2,8,3,0,9,4),
            array(5,8,0,3,7,9,6,1,4,2), array(8,9,1,6,0,4,3,5,2,7),
            array(9,4,5,3,1,2,6,8,7,0), array(4,2,8,6,5,7,3,9,0,1),
            array(2,7,9,3,8,0,6,4,1,5), array(7,0,4,6,9,1,3,2,5,8),
        );
        $inverse = array(0,4,3,2,1,5,6,7,8,9);
        $digits = array_reverse(array_map('intval', str_split((string) $number)));
        $checksum = 0;

        foreach ($digits as $index => $digit) {
            $checksum = $multiplication[$checksum][$permutation[($index + 1) % 8][$digit]];
        }

        return $inverse[$checksum];
    }

    private static function json_encode($value) {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        if ($json === false) {
            throw new RuntimeException('Failed to encode the Moadian request as JSON.');
        }
        return $json;
    }

    private static function response_message(array $body) {
        if (!empty($body['message'])) {
            return (string) $body['message'];
        }
        if (!empty($body['errors']) && is_array($body['errors'])) {
            $messages = array();
            foreach ($body['errors'] as $error) {
                if (is_array($error)) {
                    $messages[] = trim(($error['code'] ?? '') . ' ' . ($error['message'] ?? ''));
                }
            }
            if ($messages) {
                return implode('; ', $messages);
            }
        }
        return 'Moadian rejected the request.';
    }
}
