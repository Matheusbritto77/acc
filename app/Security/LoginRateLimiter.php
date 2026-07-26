<?php

namespace App\Security;

class LoginRateLimiter
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900;

    public static function getRetryAfter(string $scope, string $identifier, string $ipAddress): int
    {
        $record = self::readRecord($scope, $identifier, $ipAddress);
        $now = time();

        if (($record['lock_until'] ?? 0) > $now) {
            return (int) $record['lock_until'] - $now;
        }

        return 0;
    }

    public static function registerFailure(string $scope, string $identifier, string $ipAddress): int
    {
        $now = time();
        $record = self::readRecord($scope, $identifier, $ipAddress);

        $record['attempts'][] = $now;
        $record['attempts'] = self::pruneAttempts($record['attempts'], $now);

        if (count($record['attempts']) >= self::MAX_ATTEMPTS) {
            $record['lock_until'] = $now + self::LOCKOUT_SECONDS;
        }

        self::writeRecord($scope, $identifier, $ipAddress, $record);

        return max(0, (int) ($record['lock_until'] ?? 0) - $now);
    }

    public static function clear(string $scope, string $identifier, string $ipAddress): void
    {
        $filePath = self::getFilePath($scope, $identifier, $ipAddress);
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    public static function formatRetryMessage(int $retryAfter): string
    {
        $retryAfter = max(1, $retryAfter);
        $minutes = (int) ceil($retryAfter / 60);

        if ($minutes <= 1) {
            return 'Too many login attempts. Try again in 1 minute.';
        }

        return sprintf('Too many login attempts. Try again in %d minutes.', $minutes);
    }

    private static function readRecord(string $scope, string $identifier, string $ipAddress): array
    {
        $filePath = self::getFilePath($scope, $identifier, $ipAddress);
        if (!is_file($filePath)) {
            return [
                'attempts' => [],
                'lock_until' => 0,
            ];
        }

        $raw = file_get_contents($filePath);
        if ($raw === false || $raw === '') {
            return [
                'attempts' => [],
                'lock_until' => 0,
            ];
        }

        $record = json_decode($raw, true);
        if (!is_array($record)) {
            return [
                'attempts' => [],
                'lock_until' => 0,
            ];
        }

        $record['attempts'] = self::pruneAttempts($record['attempts'] ?? [], time());
        if (($record['lock_until'] ?? 0) <= time()) {
            $record['lock_until'] = 0;
        }

        return $record;
    }

    private static function writeRecord(string $scope, string $identifier, string $ipAddress, array $record): void
    {
        $directory = self::getStorageDirectory();
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            self::getFilePath($scope, $identifier, $ipAddress),
            json_encode($record, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private static function pruneAttempts(array $attempts, int $now): array
    {
        return array_values(array_filter(
            $attempts,
            static fn($attempt): bool => is_numeric($attempt) && ((int) $attempt) >= ($now - self::WINDOW_SECONDS)
        ));
    }

    private static function getFilePath(string $scope, string $identifier, string $ipAddress): string
    {
        $normalizedIdentifier = strtolower(trim($identifier));
        $normalizedIp = trim($ipAddress) !== '' ? trim($ipAddress) : 'unknown';
        $hash = hash('sha256', $scope . '|' . $normalizedIdentifier . '|' . $normalizedIp);

        return self::getStorageDirectory() . '/' . $hash . '.json';
    }

    private static function getStorageDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/astarot-rate-limit';
    }
}
