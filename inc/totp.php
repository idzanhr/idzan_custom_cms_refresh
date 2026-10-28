<?php
declare(strict_types=1);

/**
 * Time-based one-time passwords (RFC 6238: SHA-1, 6 digits, 30 s) — the standard that
 * Proton Authenticator, Aegis, 2FAS, Google/Microsoft Authenticator etc. all support.
 */
const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20)); // 160-bit, 32 characters
}

function base32_encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $b32): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('~[^A-Za-z2-7]~', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_at(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $o = ord($hash[19]) & 0x0F;
    $num = (unpack('N', substr($hash, $o, 4))[1] & 0x7FFFFFFF) % (10 ** TOTP_DIGITS);
    return str_pad((string)$num, TOTP_DIGITS, '0', STR_PAD_LEFT);
}

function totp_step(?int $time = null): int
{
    return intdiv($time ?? time(), TOTP_PERIOD);
}

/**
 * Check a code, allowing one period of clock drift either way. Returns the matched
 * time step, or null. Pass $lastStep to reject a code that was already used.
 */
function totp_verify(string $secret, string $code, int $lastStep = 0): ?int
{
    $code = preg_replace('~\D~', '', $code);
    if (strlen($code) !== TOTP_DIGITS) return null;
    $now = totp_step();
    foreach ([0, -1, 1] as $off) {
        $step = $now + $off;
        if ($step > $lastStep && hash_equals(totp_at($secret, $step), $code)) return $step;
    }
    return null;
}

function totp_uri(string $secret, string $account, string $issuer): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}
