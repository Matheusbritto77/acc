<?php
/**
 * ApiMercadoPago Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Payment\MercadoPago;

use MercadoPago\SDK;
use MercadoPago\Preference;
use MercadoPago\Item;

class ApiMercadoPago {
    private static function configureProduction()
    {
        SDK::setAccessToken($_ENV['MERCADOPAGO_TOKEN']);
        SDK::setPublicKey($_ENV['MERCADOPAGO_KEY']);
    }

    private static function request(string $method, string $endpoint, array $payload = [], array $headers = [])
    {
        $curl = curl_init('https://api.mercadopago.com' . $endpoint);
        $requestHeaders = array_merge([
            'Authorization: Bearer ' . $_ENV['MERCADOPAGO_TOKEN'],
            'Content-Type: application/json',
        ], $headers);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_TIMEOUT => 30,
        ]);

        if (!empty($payload)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($curl);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new \RuntimeException('Mercado Pago request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Mercado Pago API error: ' . $response);
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function createPayment($products = [], $email = null)
    {
        return SdkErrorScope::withoutDeprecations(function () use ($products, $email) {
            self::configureProduction();

            $preference = new Preference();
            $item = new Item();
            $item->title = $products['item']['title'];
            $item->description = $products['item']['title'];
            $item->quantity = $products['item']['quantity'];
            $item->currency_id = "BRL";
            $item->unit_price = $products['item']['amount']; 

            $preference->items = array($item);
            $preference->save();

            $response = array(
                'status' => $preference->status,
                'status_detail' => $preference->status_detail,
                'id' => $preference->id
            );

            return $response;
        });
    }

    public static function createPaymentSandbox($products = [], $email = null)
    {
        return self::createPaymentProduction($products, $email);
    }

    public static function createPaymentProduction($products = [], $email = null)
    {
        return SdkErrorScope::withoutDeprecations(function () use ($products, $email) {
            self::configureProduction();

            $preference = new Preference();
            $item = new Item();
            $item->title = $products['item']['title'];
            $item->description = $products['item']['title'];
            $item->quantity = $products['item']['quantity'];
            $item->currency_id = "BRL";
            $item->unit_price = $products['item']['amount']; 

            $preference->items = array($item);
            $preference->save();

            return $preference->init_point;
        });
    }

    public static function createPixPayment($products = [], $email = null)
    {
        return SdkErrorScope::withoutDeprecations(function () use ($products, $email) {
            $payerEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'comprador@example.com';
            $transactionAmount = round((float)$products['item']['amount'], 2);
            if ($transactionAmount < 1) {
                throw new \RuntimeException('Mercado Pago Pix requires transaction_amount greater than or equal to 1.00');
            }

            $payload = [
                'transaction_amount' => $transactionAmount,
                'description' => $products['item']['title'],
                'payment_method_id' => 'pix',
                'external_reference' => $products['reference'],
                'notification_url' => URL . '/payment/mercadopago/return',
                'payer' => [
                    'email' => $payerEmail,
                ],
            ];

            $payment = self::request('POST', '/v1/payments', $payload, [
                'X-Idempotency-Key: ' . $products['reference'],
            ]);

            $transactionData = $payment['point_of_interaction']['transaction_data'] ?? [];

            return [
                'id' => $payment['id'] ?? null,
                'status' => $payment['status'] ?? null,
                'external_reference' => $payment['external_reference'] ?? $products['reference'],
                'qr_code' => $transactionData['qr_code'] ?? null,
                'qr_code_base64' => $transactionData['qr_code_base64'] ?? null,
                'ticket_url' => $transactionData['ticket_url'] ?? null,
            ];
        });
    }

    public static function getPaymentById($paymentId)
    {
        return SdkErrorScope::withoutDeprecations(function () use ($paymentId) {
            return self::request('GET', '/v1/payments/' . urlencode((string)$paymentId));
        });
    }

    public function testFindPreferenceById($preference_id){  
        return SdkErrorScope::withoutDeprecations(function () use ($preference_id) {
            self::configureProduction();

            $preference = Preference::find_by_id($preference_id);
            return $preference->id;
        });
    }

}
