<?php
/**
 * Minimal Web Push sender (RFC 8030 / 8291 / 8292): VAPID authentication and
 * aes128gcm payload encryption with PHP's own OpenSSL. No external library.
 */
defined('ABSPATH') || exit;

function mcr_b64u_enc(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function mcr_b64u_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** Uncompressed P-256 public point (65 bytes) of an OpenSSL key. */
function mcr_ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** OpenSSL public key from a raw uncompressed P-256 point. */
function mcr_ec_public_from_raw(string $raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/** VAPID keys, created once and kept in the database. */
function mcr_vapid_keys(): array
{
    $k = get_option('mcr_vapid');
    if (is_array($k) && !empty($k['private']) && !empty($k['public'])) {
        return $k;
    }
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $pem);
    $k = ['private' => $pem, 'public' => mcr_b64u_enc(mcr_ec_public_raw($key))];
    update_option('mcr_vapid', $k, false);
    return $k;
}

/** DER ECDSA signature -> 64-byte r||s (JWS ES256). */
function mcr_der_to_jose(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function mcr_vapid_header(string $endpoint): string
{
    $k = mcr_vapid_keys();
    $p = wp_parse_url($endpoint);
    $claims = ['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => 'mailto:' . (mcr_setting('store', 'email') ?: get_option('admin_email'))];
    $input = mcr_b64u_enc(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . mcr_b64u_enc(wp_json_encode($claims));
    openssl_sign($input, $sig, openssl_pkey_get_private($k['private']), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $input . '.' . mcr_b64u_enc(mcr_der_to_jose($sig)) . ', k=' . $k['public'];
}

/** Encrypt a payload for one subscription (aes128gcm). */
function mcr_webpush_encrypt(string $payload, string $p256dh_b64u, string $auth_b64u): string
{
    $ua_public = mcr_b64u_dec($p256dh_b64u);
    $auth = mcr_b64u_dec($auth_b64u);
    $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $as_public = mcr_ec_public_raw($local);
    $secret = openssl_pkey_derive(mcr_ec_public_from_raw($ua_public), $local, 32);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $ua_public . $as_public, $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $as_public . $cipher . $tag;
}

/**
 * Send one notification. Returns the HTTP status (201 = delivered to the push service).
 * 404/410 mean the subscription is gone.
 */
function mcr_webpush_send(array $sub, array $data, int $ttl = 3600): int
{
    $body = mcr_webpush_encrypt(wp_json_encode($data), $sub['p256dh'], $sub['auth']);
    $res = wp_remote_post($sub['endpoint'], [
        'timeout' => 8,
        'headers' => [
            'Authorization' => mcr_vapid_header($sub['endpoint']),
            'Content-Encoding' => 'aes128gcm',
            'Content-Type' => 'application/octet-stream',
            'TTL' => (string) $ttl,
            'Urgency' => 'high',
        ],
        'body' => $body,
    ]);
    return is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
}
