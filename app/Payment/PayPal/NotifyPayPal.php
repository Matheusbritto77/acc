<?php
/**
 * NotifyPayPal Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Payment\PayPal;

use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use App\Payment\PaymentCreditService;

class NotifyPayPal {

    public static function ReturnPayPal()
    {
        $paymentId = filter_input(INPUT_GET, 'paymentId', FILTER_SANITIZE_STRING);
        $payerId = filter_input(INPUT_GET, 'PayerID', FILTER_SANITIZE_STRING);

        $payment = Payment::get($paymentId, ApiPayPal::apiContext());

        $execution = new PaymentExecution();
        if (!empty($payerId)) {
            $execution->setPayerId($payerId);
        }
        $response = $payment->execute($execution, ApiPayPal::apiContext());
        $arrayResponse = $response->toArray();

        if (($arrayResponse['status'] ?? null) === 'PAID') {
            $invoiceNumber = $arrayResponse['transactions'][0]['invoice_number'] ?? null;
            if ($invoiceNumber) {
                PaymentCreditService::markPaymentAsPaid('reference', $invoiceNumber);
            }
        }

        return $arrayResponse;
    }
}
