<?php
/**
 * CloudRight order result data model.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Model\Data;

use CloudRight\Payments\Api\Data\OrderResultInterface;
use Magento\Framework\DataObject;

/**
 * Simple, serializable data object returned by the order management
 * webapi endpoint (POST /V1/cloudright/order).
 */
class OrderResult extends DataObject implements OrderResultInterface
{
    /**
     * @inheritDoc
     */
    public function getSuccess(): bool
    {
        return (bool)$this->getData(self::SUCCESS);
    }

    /**
     * @inheritDoc
     */
    public function setSuccess(bool $success): OrderResultInterface
    {
        return $this->setData(self::SUCCESS, $success);
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): ?int
    {
        $value = $this->getData(self::ORDER_ID);

        return $value !== null ? (int)$value : null;
    }

    /**
     * @inheritDoc
     */
    public function setOrderId(?int $orderId): OrderResultInterface
    {
        return $this->setData(self::ORDER_ID, $orderId);
    }

    /**
     * @inheritDoc
     */
    public function getOrderIncrementId(): ?string
    {
        return $this->getData(self::ORDER_INCREMENT_ID);
    }

    /**
     * @inheritDoc
     */
    public function setOrderIncrementId(?string $orderIncrementId): OrderResultInterface
    {
        return $this->setData(self::ORDER_INCREMENT_ID, $orderIncrementId);
    }

    /**
     * @inheritDoc
     */
    public function getTransactionId(): ?string
    {
        return $this->getData(self::TRANSACTION_ID);
    }

    /**
     * @inheritDoc
     */
    public function setTransactionId(?string $transactionId): OrderResultInterface
    {
        return $this->setData(self::TRANSACTION_ID, $transactionId);
    }

    /**
     * @inheritDoc
     */
    public function getMessage(): string
    {
        return (string)$this->getData(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function setMessage(string $message): OrderResultInterface
    {
        return $this->setData(self::MESSAGE, $message);
    }
}
