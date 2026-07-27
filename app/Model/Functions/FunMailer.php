<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Model\Functions;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use RuntimeException;

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

    public static function envMailFuncWebsite($emailTo = null, $subject = null, $text = null, $html = null)
    {
        $mailer = self::connectMail();
        $emailFrom = trim(runtime_env_value('MAIL_WEB', getenv('MAIL_WEB') ?: ''));
        if ($emailFrom === '') {
            throw new RuntimeException('Mail sender is not configured.');
        }

        $email = (new Email())
            ->from($emailFrom)
            ->to($emailTo)
            ->subject($subject)
            ->text($text)
            ->html($html);

        $mailer->send($email);
    }

}
