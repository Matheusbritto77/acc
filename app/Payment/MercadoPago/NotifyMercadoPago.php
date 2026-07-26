<?php
/**
 * NotifyMercadoPago Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Payment\MercadoPago;

use App\Payment\PaymentCreditService;

class NotifyMercadoPago {

    public static function ReturnMercadoPago()
    {
        $paymentId = self::extractPaymentId();
        if (!$paymentId) {
            return 'OK';
        }

        try {
            $payment = ApiMercadoPago::getPaymentById($paymentId);
            self::updatePayment($payment);
        } catch (\Throwable $exception) {
            error_log($exception->getMessage());
        }

        return 'OK';
    }

    private static function extractPaymentId()
    {
        if (isset($_POST['type']) && $_POST['type'] === 'payment' && isset($_POST['data']['id'])) {
            return $_POST['data']['id'];
        }

        if (isset($_GET['type']) && $_GET['type'] === 'payment' && isset($_GET['data_id'])) {
            return $_GET['data_id'];
        }

        if (isset($_GET['topic']) && $_GET['topic'] === 'payment' && isset($_GET['id'])) {
            return $_GET['id'];
        }

        $body = file_get_contents('php://input');
        if (!empty($body)) {
            $payload = json_decode($body, true);
            if (is_array($payload)) {
                if (($payload['type'] ?? null) === 'payment' && isset($payload['data']['id'])) {
                    return $payload['data']['id'];
                }
                if (($payload['topic'] ?? null) === 'payment' && isset($payload['id'])) {
                    return $payload['id'];
                }
            }
        }

        return null;
    }

    public static function updatePayment($payment)
    {
        $status = is_array($payment) ? ($payment['status'] ?? null) : ($payment->status ?? null);
        if ($status !== 'approved') {
            return;
        }

        $reference = is_array($payment) ? ($payment['external_reference'] ?? null) : ($payment->external_reference ?? null);
        if (!$reference) {
            return;
        }

        PaymentCreditService::markPaymentAsPaid('reference', $reference, true);
    }

}
