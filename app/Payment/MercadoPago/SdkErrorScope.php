<?php

namespace App\Payment\MercadoPago;

final class SdkErrorScope
{
    public static function withoutDeprecations(callable $callback)
    {
        $previousReporting = error_reporting();
        error_reporting($previousReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            return $callback();
        } finally {
            error_reporting($previousReporting);
        }
    }
}
