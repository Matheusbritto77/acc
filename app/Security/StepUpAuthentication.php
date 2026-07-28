<?php

namespace App\Security;

use App\Model\Entity\Account;
use App\Session\Admin\Login as SessionAdminLogin;
use App\Utils\Argon;
use PragmaRX\Google2FA\Google2FA;

class StepUpAuthentication
{
    private const SESSION_KEY = 'char_bazaar_step_up';
    private const DEFAULT_TTL_SECONDS = 900;

    public static function isFresh(int $accountId): bool
    {
        SessionAdminLogin::init();
        $stepUp = $_SESSION['account'][self::SESSION_KEY] ?? null;
        if (!is_array($stepUp)) {
            return false;
        }

        if (($stepUp['account_id'] ?? null) !== $accountId) {
            return false;
        }

        $verifiedAt = (int) ($stepUp['verified_at'] ?? 0);
        if ($verifiedAt <= 0) {
            return false;
        }

        return $verifiedAt >= (time() - self::getTtlSeconds());
    }

    public static function getRemainingSeconds(int $accountId): int
    {
        SessionAdminLogin::init();
        $stepUp = $_SESSION['account'][self::SESSION_KEY] ?? null;
        if (!is_array($stepUp) || ($stepUp['account_id'] ?? null) !== $accountId) {
            return 0;
        }

        $verifiedAt = (int) ($stepUp['verified_at'] ?? 0);
        if ($verifiedAt <= 0) {
            return 0;
        }

        return max(0, ($verifiedAt + self::getTtlSeconds()) - time());
    }

    public static function verifyForAccount(int $accountId, string $password, ?string $token): ?string
    {
        $password = trim($password);
        $token = is_string($token) ? trim($token) : '';

        if ($password === '') {
            return 'Enter your password to continue.';
        }

        $account = Account::getAccount(['id' => $accountId])->fetchObject();
        if (empty($account)) {
            return 'Account not found.';
        }

        if (!Argon::checkPassword($password, (string) $account->password, (int) $account->id)) {
            return 'The password you entered is invalid.';
        }

        $authentication = Account::getAuthentication(['account_id' => $accountId])->fetchObject();
        if (!empty($authentication) && (int) $authentication->status === 1) {
            if ($token === '') {
                return 'Enter your authenticator token to continue.';
            }

            $google2fa = new Google2FA();
            if ($google2fa->verifyKey((string) $authentication->secret, $token) != 1) {
                return 'The authenticator token is invalid.';
            }
        }

        self::markFresh($accountId);

        return null;
    }

    public static function clear(): void
    {
        SessionAdminLogin::init();
        unset($_SESSION['account'][self::SESSION_KEY]);
    }

    private static function markFresh(int $accountId): void
    {
        SessionAdminLogin::init();
        $_SESSION['account'][self::SESSION_KEY] = [
            'account_id' => $accountId,
            'verified_at' => time(),
        ];
    }

    private static function getTtlSeconds(): int
    {
        $value = (int) ($_ENV['CHAR_BAZAAR_STEP_UP_TTL_SECONDS'] ?? self::DEFAULT_TTL_SECONDS);

        return $value > 0 ? $value : self::DEFAULT_TTL_SECONDS;
    }
}
