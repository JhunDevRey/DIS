<?php
/**
 * includes/totp.php
 * Two-step sign-in codes from an authenticator app (Google Authenticator,
 * Microsoft Authenticator, Authy, 1Password…): standard 6-digit codes that
 * change every 30 seconds (RFC 6238, SHA-1, the setting every app supports).
 *
 * Secrets are stored encrypted (AES-256-GCM) with SECURITY_KEY from
 * config.php, so a copy of the database alone can't generate codes.
 * Two-step sign-in is unavailable until SECURITY_KEY is set:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *   define('SECURITY_KEY', '<that 64-character value>');
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_ISSUER = 'Duque Immigration Staff';

function twoFactorAvailable(): bool
{
    return defined('SECURITY_KEY') && is_string(SECURITY_KEY) && strlen(SECURITY_KEY) >= 32
        && function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
}

function base32Encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32Decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

/** A new random secret (160 bits), base32 as authenticator apps expect. */
function totpNewSecret(): string
{
    return base32Encode(random_bytes(20));
}

/** The code for a given 30-second step. */
function totpCode(string $secretB32, int $step): string
{
    $key = base32Decode($secretB32);
    $counter = pack('N*', 0, $step); // 64-bit big-endian (high word 0 until year 2106+)
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Checks a typed code against the current step and one either side (phone
 * clocks drift). Returns the matched step, or null. Steps at or before
 * $lastUsedStep are refused, so a code seen over someone's shoulder can't
 * be used a second time.
 */
function totpVerify(string $secretB32, string $code, ?int $lastUsedStep = null, ?int $now = null): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== TOTP_DIGITS) return null;
    $current = intdiv($now ?? time(), TOTP_PERIOD);
    foreach ([0, -1, 1] as $drift) {
        $step = $current + $drift;
        if ($lastUsedStep !== null && $step <= $lastUsedStep) continue;
        if (hash_equals(totpCode($secretB32, $step), $code)) return $step;
    }
    return null;
}

/** otpauth:// link the authenticator app reads from the QR code. */
function totpUri(string $secretB32, string $accountLabel): string
{
    $label = rawurlencode(TOTP_ISSUER . ':' . $accountLabel);
    return 'otpauth://totp/' . $label . '?secret=' . $secretB32 . '&issuer=' . rawurlencode(TOTP_ISSUER)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

function securityKeyBytes(): string
{
    return hash('sha256', 'dis-totp-v1|' . SECURITY_KEY, true);
}

function totpEncryptSecret(string $secretB32): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($secretB32, 'aes-256-gcm', securityKeyBytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Could not encrypt the authenticator secret.');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function totpDecryptSecret(?string $stored): ?string
{
    if (!$stored || !str_starts_with($stored, 'v1:') || !twoFactorAvailable()) return null;
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', securityKeyBytes(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/** Eight one-time recovery codes like "k7fq-2m9x", plus their hashes for storage. */
function newRecoveryCodes(): array
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return [$codes, array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes)];
}
