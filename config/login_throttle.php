<?php
declare(strict_types=1);

/** Locks both buckets through password verification so parallel attempts cannot overshoot. */
final class LoginThrottle
{
    public const ACCOUNT_FAILURE_LIMIT = 5;
    public const IP_FAILURE_LIMIT = 30;
    public const WINDOW_SECONDS = 900;
    public const COOLDOWN_SECONDS = 900;

    private PDO $db;
    private bool $active = false;
    private array $buckets = [];
    private int $now = 0;

    public function __construct(PDO $db) { $this->db = $db; }

    /** The account identity is a stored user ID, or a normalized unknown username. */
    public function beginAttempt(string $accountIdentity, string $ip): bool
    {
        if ($this->active || $this->db->inTransaction()) throw new LogicException('A login attempt is already active.');
        $packedIp = @inet_pton($ip);
        $identities = ['account' => $accountIdentity, 'ip' => $packedIp === false ? $ip : bin2hex($packedIp)];
        $this->db->beginTransaction();
        $this->active = true;
        try {
            $insert = $this->db->prepare('INSERT INTO login_rate_limits (bucket_scope, bucket_key, failures, window_started_at)
                VALUES (?, ?, 0, UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE bucket_key = VALUES(bucket_key)');
            $select = $this->db->prepare('SELECT * FROM login_rate_limits WHERE bucket_scope = ? AND bucket_key = ? FOR UPDATE');
            // Always lock account, then IP, to keep lock ordering consistent.
            foreach ($identities as $scope => $identity) {
                $key = hash('sha256', $identity);
                $insert->execute([$scope, $key]);
                $select->execute([$scope, $key]);
                $this->buckets[$scope] = $select->fetch(PDO::FETCH_ASSOC);
            }
            $this->now = (int) $this->db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
            foreach ($this->buckets as $scope => $bucket) {
                if ((int) $bucket['blocked_until'] > $this->now) {
                    $this->cancelAttempt();
                    return false;
                }
                if ($bucket['blocked_until'] !== null || $this->now - (int) $bucket['window_started_at'] >= self::WINDOW_SECONDS) {
                    $this->buckets[$scope]['failures'] = 0;
                    $this->buckets[$scope]['window_started_at'] = $this->now;
                    $this->buckets[$scope]['blocked_until'] = null;
                }
            }
            return true;
        } catch (Throwable $e) {
            $this->cancelAttempt();
            throw $e;
        }
    }

    public function finishAttempt(bool $successful): void
    {
        if (!$this->active) throw new LogicException('No login attempt is active.');
        try {
            $update = $this->db->prepare('UPDATE login_rate_limits SET failures = ?, window_started_at = ?, blocked_until = ?
                WHERE bucket_scope = ? AND bucket_key = ?');
            foreach ($this->buckets as $scope => $bucket) {
                $failures = (int) $bucket['failures'];
                $blockedUntil = null;
                if ($successful && $scope === 'account') {
                    $failures = 0;
                    $bucket['window_started_at'] = $this->now;
                } elseif (!$successful) {
                    ++$failures;
                    $limit = $scope === 'account' ? self::ACCOUNT_FAILURE_LIMIT : self::IP_FAILURE_LIMIT;
                    if ($failures >= $limit) $blockedUntil = $this->now + self::COOLDOWN_SECONDS;
                }
                // A successful login never clears accumulated IP failures.
                $update->execute([$failures, (int) $bucket['window_started_at'], $blockedUntil, $scope, $bucket['bucket_key']]);
            }
            $this->db->commit();
            $this->active = false;
            $this->buckets = [];
        } catch (Throwable $e) {
            $this->cancelAttempt();
            throw $e;
        }
    }

    public function cancelAttempt(): void
    {
        if ($this->active && $this->db->inTransaction()) $this->db->rollBack();
        $this->active = false;
        $this->buckets = [];
    }
}
