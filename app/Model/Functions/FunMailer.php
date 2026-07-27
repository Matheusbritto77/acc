<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Model\Functions;

use RuntimeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

class FunMailer{

    private static function resolveTransportDsn(): string
    {
        $resendApiKey = trim(runtime_env_value('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: ''));
        if ($resendApiKey !== '') {
            return sprintf(
                'smtps://resend:%s@smtp.resend.com:465',
                rawurlencode($resendApiKey)
            );
        }

        $smtp = trim(runtime_env_value('MAIL_SMTP', getenv('MAIL_SMTP') ?: ''));
        if ($smtp !== '') {
            return $smtp;
        }

        throw new RuntimeException('Mail transport is not configured.');
    }

    public static function connectMail()
    {
        $transport = Transport::fromDsn(self::resolveTransportDsn());
        $mailer = new Mailer($transport);
        return $mailer;
    }

    private static function senderAddress(): string
    {
        $emailFrom = trim(runtime_env_value('MAIL_WEB', getenv('MAIL_WEB') ?: ''));
        if ($emailFrom === '') {
            throw new RuntimeException('Mail sender is not configured.');
        }
        return $emailFrom;
    }

    private static function buildHtml(string $title, array $lines): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '';
        foreach ($lines as $line) {
            $body .= '<p style="margin:0 0 12px;line-height:1.6;">'
                . htmlspecialchars((string) $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p>';
        }

        return '<!doctype html><html><body style="margin:0;background:#111;color:#e8e1d3;font-family:Arial,sans-serif;">'
            . '<div style="max-width:640px;margin:0 auto;padding:32px 24px;background:#1b1b1b;border:1px solid #383838;">'
            . '<h2 style="margin:0 0 18px;color:#f1d38a;">' . $safeTitle . '</h2>'
            . $body
            . '</div></body></html>';
    }

    public static function sendMail($emailTo = null, $subject = null, $text = null, $html = null): bool
    {
        if (empty($emailTo) || empty($subject)) {
            return false;
        }

        try {
            $mailer = self::connectMail();
            $email = (new Email())
                ->from(self::senderAddress())
                ->to($emailTo)
                ->subject((string) $subject)
                ->text((string) ($text ?? ''))
                ->html((string) ($html ?? ''));

            $mailer->send($email);
            return true;
        } catch (\Throwable $error) {
            error_log('Mail send failed: ' . $error->getMessage());
            return false;
        }
    }

    public static function sendLoginNotification(string $emailTo, string $accountName, string $ipAddress): bool
    {
        $subject = 'New login detected';
        $text = "Hello {$accountName},\n\nA login was made to your account from IP {$ipAddress}.\nIf this was not you, change your password immediately.";
        $html = self::buildHtml($subject, [
            "Hello {$accountName},",
            "A login was made to your account from IP {$ipAddress}.",
            'If this was not you, change your password immediately.',
        ]);

        return self::sendMail($emailTo, $subject, $text, $html);
    }

    public static function sendAccountCreated(string $emailTo, string $accountName, string $characterName = '', string $worldName = ''): bool
    {
        $subject = 'Your account was created';
        $lines = [
            "Hello {$accountName},",
            'Your account was created successfully.',
        ];
        if ($characterName !== '') {
            $lines[] = "Main character: {$characterName}";
        }
        if ($worldName !== '') {
            $lines[] = "World: {$worldName}";
        }

        $text = implode("\n", $lines);
        $html = self::buildHtml($subject, $lines);

        return self::sendMail($emailTo, $subject, $text, $html);
    }

    public static function sendCharacterCreated(string $emailTo, string $accountName, string $characterName, string $worldName): bool
    {
        $subject = 'Character created';
        $lines = [
            "Hello {$accountName},",
            "Your new character {$characterName} was created successfully.",
            "World: {$worldName}",
        ];

        return self::sendMail($emailTo, $subject, implode("\n", $lines), self::buildHtml($subject, $lines));
    }

    public static function sendPasswordChanged(string $emailTo, string $accountName): bool
    {
        $subject = 'Password changed';
        $lines = [
            "Hello {$accountName},",
            'Your account password was changed successfully.',
            'If this was not you, contact support immediately.',
        ];

        return self::sendMail($emailTo, $subject, implode("\n", $lines), self::buildHtml($subject, $lines));
    }

    public static function sendEmailChanged(string $oldEmail, string $newEmail, string $accountName): bool
    {
        $subject = 'Email address changed';
        $lines = [
            "Hello {$accountName},",
            'The email address on your account was changed successfully.',
            "New email: {$newEmail}",
            'If this was not you, secure your account immediately.',
        ];

        $text = implode("\n", $lines);
        $html = self::buildHtml($subject, $lines);

        $sentNew = self::sendMail($newEmail, $subject, $text, $html);
        $sentOld = true;
        if ($oldEmail !== $newEmail) {
            $sentOld = self::sendMail($oldEmail, $subject, $text, $html);
        }

        return $sentNew || $sentOld;
    }

    public static function sendRecoverySuccess(string $emailTo, string $accountName): bool
    {
        $subject = 'Recovery completed';
        $lines = [
            "Hello {$accountName},",
            'Your account recovery completed successfully and the password was updated.',
            'If this was not you, contact support immediately.',
        ];

        return self::sendMail($emailTo, $subject, implode("\n", $lines), self::buildHtml($subject, $lines));
    }

    public static function sendTwoFactorEnabled(string $emailTo, string $accountName): bool
    {
        $subject = 'Two-factor authentication enabled';
        $lines = [
            "Hello {$accountName},",
            'Two-factor authentication was enabled for your account.',
            'If this was not you, secure your account immediately.',
        ];

        return self::sendMail($emailTo, $subject, implode("\n", $lines), self::buildHtml($subject, $lines));
    }

    public static function sendTwoFactorDisabled(string $emailTo, string $accountName): bool
    {
        $subject = 'Two-factor authentication disabled';
        $lines = [
            "Hello {$accountName},",
            'Two-factor authentication was removed from your account.',
            'If this was not you, secure your account immediately.',
        ];

        return self::sendMail($emailTo, $subject, implode("\n", $lines), self::buildHtml($subject, $lines));
    }

}
