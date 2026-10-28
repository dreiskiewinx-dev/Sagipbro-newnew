<?php
declare(strict_types=1);

/** php tests/password_policy.php; optional real range API check: SAGIPBRO_PASSWORD_LIVE_TEST=1. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../config/password_policy.php';
$checks = 0;
function policyCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}
try {
    $unexpectedLookup = static function (string $prefix): string { throw new LogicException('Invalid passwords must be rejected before network access.'); };
    foreach (['', 'short', 'moss sk', str_repeat('a', 15), str_repeat('ab', 8), str_repeat(' ', 15), 'Password123456789!', 'SAGIPBRO123456789!', "valid password\0oops", "invalid utf8 \xff long", str_repeat('a', 73), [], null, 123456789012345] as $invalid) {
        policyCheck(sagipbroPasswordPolicyError($invalid, $unexpectedLookup) !== null, 'Reject invalid, short, common, repeated, and oversized passwords locally.');
    }
    $safeRange = str_repeat('F', 35) . ":3\r\n" . str_repeat('0', 35) . ":0\r\n";
    foreach (['moss sky', 'fourteen-chars', 'moss lantern sky', 'orchid meadow copper lantern', '  glacier maple river  ', 'café meadow velvet', str_repeat('é', 7) . 'x' . ' oak river', str_repeat('good-', 14) . 'xy'] as $valid) {
        $seen = [];
        $lookup = static function (string $prefix) use (&$seen, $safeRange): string { $seen[] = $prefix; return $safeRange; };
        policyCheck(sagipbroPasswordPolicyError($valid, $lookup) === null, 'Accept safe passphrases, whitespace, Unicode, and the byte boundary.');
        policyCheck($seen === [substr(strtoupper(sha1($valid)), 0, 5)], 'Only the five-character hash prefix reaches the lookup.');
        $stored = password_hash($valid, PASSWORD_DEFAULT);
        policyCheck(password_verify($valid, $stored), 'Accepted passwords round-trip without trimming or truncation.');
    }
    policyCheck(sagipbroPasswordPolicyError("\u{e9}\u{e0}\u{e7}\u{ee}\u{f6}\u{f9}\u{f1}", $unexpectedLookup) !== null, 'Seven Unicode characters remain below the minimum even when their byte length exceeds eight.');
    $phrase = 'orchid meadow copper lantern';
    $suffix = substr(strtoupper(sha1($phrase)), 5);
    policyCheck(sagipbroPasswordPolicyError($phrase, static fn () => $suffix . ':17') !== null, 'Reject a full hash match reported as breached.');
    policyCheck(sagipbroPasswordPolicyError($phrase, static fn () => strtolower($suffix) . ':17') !== null, 'Handle case-insensitive hash responses.');
    policyCheck(sagipbroPasswordPolicyError($phrase, static fn () => $suffix . ':0') === null, 'Padding entries with zero count are not breaches.');
    foreach (['', "\r\n", '<html>unavailable</html>', $suffix . ':17' . "\r\n" . 'malformed', $suffix . ':-1'] as $invalidRange) {
        try {
            sagipbroPasswordPolicyError($phrase, static fn () => $invalidRange);
            throw new LogicException('Unavailable or malformed range responses cannot approve a password.');
        } catch (PasswordScreeningUnavailable $e) { policyCheck(!str_contains($e->getMessage(), $phrase), 'Screening errors do not expose the password.'); }
    }
    try {
        sagipbroPasswordPolicyError($phrase, static function (): string { throw new PasswordScreeningUnavailable('Password safety check is unavailable. Please try again later.'); });
        throw new LogicException('Network failure must prevent a password write.');
    } catch (PasswordScreeningUnavailable $e) { policyCheck(true, 'Network failures are propagated safely.'); }
    if (getenv('SAGIPBRO_PASSWORD_LIVE_TEST') === '1') {
        policyCheck(sagipbroPasswordPolicyError('correct horse battery staple') !== null, 'The real service rejects a known published passphrase.');
        policyCheck(sagipbroPasswordPolicyError('Test-only-' . bin2hex(random_bytes(20))) === null, 'The real service accepts a newly generated test password.');
    }
    fwrite(STDOUT, 'PASS: ' . $checks . " password policy assertions.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . ($e instanceof LogicException || $e instanceof PasswordScreeningUnavailable ? $e->getMessage() : get_class($e)) . "\n");
    exit(1);
}
