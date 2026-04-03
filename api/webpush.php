<?php
// Web Push implementation (RFC 8291/8292) — no external library required
// Requires PHP 8.1+ for openssl_pkey_derive

define('VAPID_PUBLIC_KEY',  'BMv50tM5lqdQRjnxCsMLgi9BWuyz49HXBk2x3jOOKTdfbH1bm0kdbAdoKg43iHWkJmffzQtKA01v3Lyp4wc1cOc');
define('VAPID_PRIVATE_KEY', 'zsjek2odVQ0IV5HnHkaQDdMsP5LQqoS-2PTeBKbglNE');
define('VAPID_SUBJECT',     'mailto:admin@smart-account-book.infinityfreeapp.com');

function wp_b64u_enc($bytes)  { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
function wp_b64u_dec($str)    { return base64_decode(str_pad(strtr($str, '-_', '+/'), strlen($str) + (4 - strlen($str) % 4) % 4, '=')); }

function wp_asn1_len($data) {
    $len = strlen($data);
    if ($len < 0x80) return chr($len);
    $b = ''; while ($len) { $b = chr($len & 0xff) . $b; $len >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}

// Build EC private key PEM (SEC1/RFC 5915) for P-256
function wp_ec_priv_pem($d_raw, $q_raw) {
    $d = str_pad($d_raw, 32, "\x00", STR_PAD_LEFT); // 32-byte private key
    // q_raw = 65-byte uncompressed public key (0x04 || x || y)
    $p256 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // P-256 OID (10 bytes)
    // ECPrivateKey SEQUENCE { version=1, privateKey, [0]params, [1]publicKey }
    $inner = "\x02\x01\x01"                     // version
           . "\x04\x20" . $d                     // privateKey (32 bytes)
           . "\xa0\x0a" . $p256                  // [0] parameters
           . "\xa1\x44\x03\x42\x00" . $q_raw;   // [1] publicKey BIT STRING
    $der = "\x30" . wp_asn1_len($inner) . $inner;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

// Build SubjectPublicKeyInfo PEM for P-256 uncompressed key
function wp_ec_pub_pem($q_raw) {
    $alg_oid   = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // ecPublicKey OID
    $curve_oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // P-256 OID
    $alg_seq   = "\x30" . wp_asn1_len($alg_oid . $curve_oid) . $alg_oid . $curve_oid;
    $bit_str   = "\x03" . wp_asn1_len("\x00" . $q_raw) . "\x00" . $q_raw;
    $spki      = "\x30" . wp_asn1_len($alg_seq . $bit_str) . $alg_seq . $bit_str;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// Convert DER ECDSA signature → raw r||s (64 bytes)
function wp_der_to_raw64($der) {
    $pos = 0;
    $pos++; // skip SEQUENCE tag (0x30)
    $lb = ord($der[$pos++]);
    if ($lb >= 0x80) $pos += $lb - 0x80; // long-form length
    // r
    $pos++; // skip INTEGER tag (0x02)
    $rlen = ord($der[$pos++]);
    $r = substr($der, $pos, $rlen); $pos += $rlen;
    // s
    $pos++; // skip INTEGER tag (0x02)
    $slen = ord($der[$pos++]);
    $s = substr($der, $pos, $slen);
    return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
         . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
}

// HKDF-Expand (RFC 5869)
function wp_hkdf_expand($prk, $info, $len) {
    $t = ''; $out = '';
    for ($i = 1; strlen($out) < $len; $i++) {
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $out .= $t;
    }
    return substr($out, 0, $len);
}

// Create VAPID JWT (RFC 8292)
function wp_vapid_jwt($endpoint) {
    $url = parse_url($endpoint);
    $aud = $url['scheme'] . '://' . $url['host'];
    $h   = wp_b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $p   = wp_b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => VAPID_SUBJECT]));
    $si  = "$h.$p";
    $priv = wp_b64u_dec(VAPID_PRIVATE_KEY);
    $pub  = wp_b64u_dec(VAPID_PUBLIC_KEY);
    $pem  = wp_ec_priv_pem($priv, $pub);
    $pkey = openssl_pkey_get_private($pem);
    if (!$pkey) return null;
    openssl_sign($si, $sig, $pkey, OPENSSL_ALGO_SHA256);
    return $si . '.' . wp_b64u_enc(wp_der_to_raw64($sig));
}

// Encrypt payload (RFC 8291 / RFC 8188 aes128gcm)
function wp_encrypt($message, $auth_b64, $p256dh_b64) {
    $auth_bytes   = wp_b64u_dec($auth_b64);
    $p256dh_bytes = wp_b64u_dec($p256dh_b64);

    // Ephemeral EC key pair
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $eph_d = openssl_pkey_get_details($eph);
    $eph_pub = "\x04"
             . str_pad($eph_d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
             . str_pad($eph_d['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    // ECDH shared secret (requires PHP 8.1+)
    $recv_pub = openssl_pkey_get_public(wp_ec_pub_pem($p256dh_bytes));
    $shared   = openssl_pkey_derive($recv_pub, $eph, 32);
    $shared   = str_pad($shared, 32, "\x00", STR_PAD_LEFT);

    $salt = random_bytes(16);

    // RFC 8291 key derivation
    $prk_key  = hash_hmac('sha256', $shared, $auth_bytes, true); // HKDF-Extract(auth, shared)
    $key_info = "WebPush: info\x00" . $p256dh_bytes . $eph_pub;
    $ikm      = wp_hkdf_expand($prk_key, $key_info, 32);         // HKDF-Expand

    $prk   = hash_hmac('sha256', $ikm, $salt, true);              // HKDF-Extract(salt, ikm)
    $cek   = wp_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = wp_hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);

    // AES-128-GCM encrypt
    $tag = '';
    $ct  = openssl_encrypt($message . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

    // RFC 8188 content encoding header: salt(16) + rs(4) + idlen(1) + key_id(65) + ciphertext + tag
    return $salt . pack('N', 4096) . chr(65) . $eph_pub . $ct . $tag;
}

// Send a Web Push notification
function send_web_push(string $endpoint, string $p256dh, string $auth, string $payload): array {
    $body = wp_encrypt($payload, $auth, $p256dh);
    if ($body === false) return ['code' => 0, 'err' => 'encryption failed'];

    $jwt      = wp_vapid_jwt($endpoint);
    $vapid_pk = VAPID_PUBLIC_KEY;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: normal',
            'Authorization: vapid t=' . $jwt . ',k=' . $vapid_pk,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'resp' => $resp, 'err' => $err];
}
