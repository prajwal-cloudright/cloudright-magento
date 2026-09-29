<?php
/**
 * CloudRight order management service contract.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Api;

use CloudRight\Payments\Api\Data\OrderResultInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Service contract responsible for turning the current CloudRight
 * checkout (customer email + CloudRight transaction id) into a real
 * Magento order, using Magento's own quote/order APIs.
 */
interface OrderManagementInterface
{
    /**
     * Create a Magento order for the current quote/cart using the
     * CloudRight checkout data collected on the storefront.
     *
     * @param string $email Customer email captured in the CloudRight checkout modal.
     * @param string $transactionId CloudRight transaction identifier (test/dev transaction id for now).
     * @return \CloudRight\Payments\Api\Data\OrderResultInterface
     * @throws LocalizedException When the request is invalid (bad email, missing transaction id, empty cart).
     * @throws CouldNotSaveException When the order could not be created/saved.
     */
    public function createOrder(string $email, string $transactionId): OrderResultInterface;
}
