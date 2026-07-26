<?php
/**
 * NotifyPagSeguro Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Payment\PagSeguro;

use PagSeguro\Configuration\Configure;
use PagSeguro\Services\Transactions\Notification;
use App\Payment\PaymentCreditService;

class NotifyPagSeguro {

    public static function ReturnPagSeguro()
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $filter_type = filter_input(INPUT_POST, 'notificationType', FILTER_SANITIZE_SPECIAL_CHARS);
            if ($filter_type === 'transaction') {
                $credentials = Configure::getAccountCredentials();
                $transaction = Notification::check($credentials);

                $reference = $transaction->getReference();
                $transaction_status = $transaction->getStatus()->getTypeFromValue();
                
                if ($transaction_status == 'PAID') {
                    PaymentCreditService::markPaymentAsPaid('reference', $reference);
                }
            }
        }
    }

}
