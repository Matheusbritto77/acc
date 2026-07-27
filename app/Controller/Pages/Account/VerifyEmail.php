<?php
/**
 * VerifyEmail Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages\Account;

use App\Controller\Pages\Base;
use App\Model\Entity\Account as EntityAccount;
use App\Utils\View;

class VerifyEmail extends Base{

    public static function verifyEmail($request, $token)
    {
        $filterToken = preg_replace('/[^a-f0-9]/i', '', (string) $token);
        if ($filterToken === '') {
            $content = View::render('pages/account/verifyemail', [
                'status' => true,
                'status_message' => 'Invalid verification token.',
                'verified' => false,
            ]);
            return parent::getBase('Email Verification', $content, 'account');
        }

        $verification = EntityAccount::getEmailVerification(['token' => $filterToken])->fetchObject();
        if (empty($verification)) {
            $content = View::render('pages/account/verifyemail', [
                'status' => true,
                'status_message' => 'This verification link is invalid or has already been used.',
                'verified' => false,
            ]);
            return parent::getBase('Email Verification', $content, 'account');
        }

        if ((int) $verification->status === 1) {
            $account = EntityAccount::getAccount(['id' => $verification->account_id])->fetchObject();
            $content = View::render('pages/account/verifyemail', [
                'status' => false,
                'verified' => true,
                'account_name' => $account?->name ?? $account?->email ?? 'your account',
            ]);
            return parent::getBase('Email Verification', $content, 'account');
        }

        EntityAccount::updateEmailVerification(['id' => $verification->id], [
            'status' => 1,
            'verified_at' => time(),
        ]);

        $account = EntityAccount::getAccount(['id' => $verification->account_id])->fetchObject();
        $content = View::render('pages/account/verifyemail', [
            'status' => false,
            'verified' => true,
            'account_name' => $account?->name ?? $account?->email ?? 'your account',
        ]);
        return parent::getBase('Email Verification', $content, 'account');
    }

}
