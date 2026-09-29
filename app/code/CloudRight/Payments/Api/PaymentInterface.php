<?php
/**
 * CloudRight payment processing service contract.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Api;

/**
 * Service contract responsible for evaluating/recording the result of a
 * CloudRight payment attempt for a given order.
 *
 * IMPORTANT: In this initial version there is no live gateway connection.
 * This contract exists so that a future implementation can call the real
 * CloudRight payment API without changing any of the code that depends on
 * this interface (Model\OrderManagement, the payment method, etc).
 */
interface PaymentInterface
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    /**
     * Process/record a CloudRight payment for the given order.
     *
     * @param int $orderId Magento order entity id.
     * @param string $transactionId CloudRight transaction id supplied by the storefront.
     * @return string One of self::STATUS_PAID, self::STATUS_PENDING, self::STATUS_FAILED.
     */
    public function processPayment(int $orderId, string $transactionId): string;

    /**
     * Determine whether a given CloudRight transaction id is well formed.
     *
     * @param string $transactionId
     * @return bool
     */
    public function isValidTransactionId(string $transactionId): bool;
}
