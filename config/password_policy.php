<?php
declare(strict_types=1);

const SAGIPBRO_PASSWORD_MIN_LENGTH = 8;
// PASSWORD_DEFAULT currently uses bcrypt. Reject longer values instead of silently truncating.
const SAGIPBRO_PASSWORD_MAX_BYTES = 72;

class PasswordScreeningUnavailable extends RuntimeException {}

/** Fetch a padded hash range; never send a password, full hash, or account details. */
function sagipbroPasswordRange(string $prefix): string
{
    if (!preg_match('/\A[A-F0-9]{5}\z/', $prefix) || !function_exists('curl_init')) {
        throw new PasswordScreeningUnavailable('Password safety check is unavailable. Please try again later.');
    }
    $request = curl_init('https://api.pwnedpasswords.com/range/' . $prefix);
    $body = '';
    curl_setopt_array($request, [
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'SAGIPBRO-PasswordPolicy',
        CURLOPT_HTTPHEADER => ['Add-Padding: true', 'Accept: text/plain'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1048576) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    if ($success === false || $status !== 200) {
        throw new PasswordScreeningUnavailable('Password safety check is unavailable. Please try again later.');
    }
    return $body;
}

/** Return a user-facing rejection, or null for an acceptable new password. */
function sagipbroPasswordPolicyError($password, ?callable $rangeLookup = null): ?string
{
    if (!is_string($password) || !preg_match('//u', $password) || str_contains($password, "\0")) {
        return 'Enter a valid password.';
    }
    $length = preg_match_all('/./us', $password);
    if ($length < SAGIPBRO_PASSWORD_MIN_LENGTH) return 'Password must contain at least ' . SAGIPBRO_PASSWORD_MIN_LENGTH . ' characters. Spaces and passphrases are welcome.';
    if (strlen($password) > SAGIPBRO_PASSWORD_MAX_BYTES) return 'Password is too long. Use at most 72 bytes (72 characters for plain English text).';
    if (trim($password) === '' || preg_match('/\A(.{1,4})\1+\z/us', $password)
        || preg_match('/\A(?:password|admin|administrator|sagipbro|qwerty|letmein|welcome|barangay|1234567890)[\d\W_]*\z/iu', $password)) {
        return 'Choose a less common password or a longer, unique passphrase.';
    }

    // SHA-1 is used only for the range lookup, never for stored credentials.
    $hash = strtoupper(sha1($password));
    $range = ($rangeLookup ?? 'sagipbroPasswordRange')(substr($hash, 0, 5));
    if (!is_string($range) || trim($range) === '') {
        throw new PasswordScreeningUnavailable('Password safety check is unavailable. Please try again later.');
    }
    $breached = false;
    foreach (preg_split('/\r\n|\n|\r/', rtrim($range, "\r\n")) as $line) {
        if (!preg_match('/\A([A-F0-9]{35}):([0-9]+)\z/i', $line, $entry)) {
            throw new PasswordScreeningUnavailable('Password safety check is unavailable. Please try again later.');
        }
        if ((int) $entry[2] > 0 && hash_equals(substr($hash, 5), strtoupper($entry[1]))) $breached = true;
    }
    return $breached ? 'This password has appeared in a known data breach. Choose a different, unique password.' : null;
}
