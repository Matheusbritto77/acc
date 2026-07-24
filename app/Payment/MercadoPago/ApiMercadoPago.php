<?php
/**
 * ApiMercadoPago Class
 *
 * @package   CanaryAAC
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 CanaryAAC
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

    public function testFindPreferenceById($preference_id){  
        return SdkErrorScope::withoutDeprecations(function () use ($preference_id) {
            self::configureProduction();

            $preference = Preference::find_by_id($preference_id);
            return $preference->id;
        });
    }

}
