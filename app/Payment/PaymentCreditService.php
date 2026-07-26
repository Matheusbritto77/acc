<?php

namespace App\Payment;

use App\DatabaseManager\Database;

class PaymentCreditService
{
    /**
     * Marca um pagamento como pago e credita coins de forma idempotente.
     *
     * @param string $lookupField Campo permitido: reference|preference
     * @param string $lookupValue Valor da referência externa do provedor
     * @param bool $creditTransferable Se true, também credita coins_transferable
     * @return bool True quando aplicou crédito; false quando já estava pago ou inválido
     */
    public static function markPaymentAsPaid(string $lookupField, string $lookupValue, bool $creditTransferable = false): bool
    {
        $allowedLookupFields = ['reference', 'preference'];
        if (!in_array($lookupField, $allowedLookupFields, true) || $lookupValue === '') {
            return false;
        }

        $db = new Database();

        try {
            $db->beginTransaction();

            $paymentStatement = $db->executeOrFail(
                sprintf(
                    'SELECT `id`, `account_id`, `total_coins`, `status` FROM `canary_payments` WHERE `%s` = ? LIMIT 1 FOR UPDATE',
                    $lookupField
                ),
                [$lookupValue]
            );
            $payment = $paymentStatement->fetchObject();

            if (!$payment || (int) $payment->status === 4) {
                $db->rollBack();
                return false;
            }

            $accountStatement = $db->executeOrFail(
                'SELECT `id`, `coins`, `coins_transferable` FROM `accounts` WHERE `id` = ? LIMIT 1 FOR UPDATE',
                [$payment->account_id]
            );
            $account = $accountStatement->fetchObject();

            if (!$account) {
                $db->rollBack();
                return false;
            }

            $totalCoins = (int) $payment->total_coins;
            $nextCoins = (int) $account->coins + $totalCoins;

            $paymentUpdate = $db->executeOrFail(
                'UPDATE `canary_payments` SET `status` = 4 WHERE `id` = ? AND `status` <> 4',
                [$payment->id]
            );

            if ($paymentUpdate->rowCount() !== 1) {
                $db->rollBack();
                return false;
            }

            if ($creditTransferable) {
                $nextTransferableCoins = (int) $account->coins_transferable + $totalCoins;
                $db->executeOrFail(
                    'UPDATE `accounts` SET `coins` = ?, `coins_transferable` = ? WHERE `id` = ?',
                    [$nextCoins, $nextTransferableCoins, $account->id]
                );
            } else {
                $db->executeOrFail(
                    'UPDATE `accounts` SET `coins` = ? WHERE `id` = ?',
                    [$nextCoins, $account->id]
                );
            }

            $db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }
}
