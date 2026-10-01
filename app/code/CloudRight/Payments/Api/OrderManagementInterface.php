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
 * Service contract responsible for turning CloudRight checkout
 * information into a Magento order.
 */
interface OrderManagementInterface
{
    /**
     * Create a Magento order for the current storefront quote/cart.
     *
     * This method is used by the existing CloudRight storefront
     * checkout and relies on the Magento checkout session.
     *
     * @param string $email
     * @param string $transactionId
     * @return \CloudRight\Payments\Api\Data\OrderResultInterface
     * @throws LocalizedException
     * @throws CouldNotSaveException
     */
    public function createOrder(
        string $email,
        string $transactionId
    ): OrderResultInterface;

    /**
     * Create a Magento order for a specific masked cart.
     *
     * This method is intended for authenticated REST API clients.
     * The cartId is Magento's masked cart identifier and does not
     * expose the internal quote ID.
     *
     * @param string $cartId
     * @param string $email
     * @param string $transactionId
     * @return \CloudRight\Payments\Api\Data\OrderResultInterface
     * @throws LocalizedException
     * @throws CouldNotSaveException
     */
    public function createOrderForCart(
        string $cartId,
        string $email,
        string $transactionId
    ): OrderResultInterface;
}