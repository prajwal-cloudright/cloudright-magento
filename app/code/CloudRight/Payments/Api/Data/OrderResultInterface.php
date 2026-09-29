<?php
/**
 * CloudRight order result data contract.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Api\Data;

/**
 * Read-only data contract describing the outcome of an order creation
 * request made through CloudRight\Payments\Api\OrderManagementInterface.
 */
interface OrderResultInterface
{
    public const SUCCESS = 'success';
    public const ORDER_ID = 'order_id';
    public const ORDER_INCREMENT_ID = 'order_increment_id';
    public const TRANSACTION_ID = 'transaction_id';
    public const MESSAGE = 'message';

    /**
     * Whether the order was created successfully.
     *
     * @return bool
     */
    public function getSuccess(): bool;

    /**
     * @param bool $success
     * @return $this
     */
    public function setSuccess(bool $success): OrderResultInterface;

    /**
     * Internal Magento order entity id.
     *
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * @param int|null $orderId
     * @return $this
     */
    public function setOrderId(?int $orderId): OrderResultInterface;

    /**
     * Customer-facing order increment id (order number).
     *
     * @return string|null
     */
    public function getOrderIncrementId(): ?string;

    /**
     * @param string|null $orderIncrementId
     * @return $this
     */
    public function setOrderIncrementId(?string $orderIncrementId): OrderResultInterface;

    /**
     * CloudRight transaction id associated with the order.
     *
     * @return string|null
     */
    public function getTransactionId(): ?string;

    /**
     * @param string|null $transactionId
     * @return $this
     */
    public function setTransactionId(?string $transactionId): OrderResultInterface;

    /**
     * Human readable result message.
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message): OrderResultInterface;
}
