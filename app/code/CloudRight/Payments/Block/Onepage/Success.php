<?php
/**
 * CloudRight Payments
 *
 * Custom Magento Order Received / Thank You page block.
 */
declare(strict_types=1);

namespace CloudRight\Payments\Block\Onepage;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Payment;

class Success extends Template
{
    private CheckoutSession $checkoutSession;

    private ?Order $order = null;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Get the completed order.
     */
    public function getOrder(): ?Order
    {
        if ($this->order !== null) {
            return $this->order;
        }

        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            return null;
        }

        $this->order = $order;

        return $this->order;
    }

    /**
     * Get order increment number.
     */
    public function getOrderNumber(): string
    {
        $order = $this->getOrder();

        return $order
            ? (string)$order->getIncrementId()
            : '';
    }

    /**
     * Get customer display name.
     */
    public function getCustomerName(): string
    {
        $order = $this->getOrder();

        if (!$order) {
            return 'Customer';
        }

        $firstName = trim((string)$order->getCustomerFirstname());
        $lastName = trim((string)$order->getCustomerLastname());

        $name = trim($firstName . ' ' . $lastName);

        if ($name !== '') {
            return $name;
        }

        $billingAddress = $order->getBillingAddress();

        if ($billingAddress) {
            $name = trim(
                (string)$billingAddress->getFirstname()
                . ' '
                . (string)$billingAddress->getLastname()
            );
        }

        return $name !== '' ? $name : 'Customer';
    }

    /**
     * Get confirmation email.
     */
    public function getCustomerEmail(): string
    {
        $order = $this->getOrder();

        return $order
            ? (string)$order->getCustomerEmail()
            : '';
    }

    /**
     * Get formatted order date.
     */
    public function getOrderDate(): string
    {
        $order = $this->getOrder();

        if (!$order || !$order->getCreatedAt()) {
            return '';
        }

        return $this->formatDate(
            $order->getCreatedAt(),
            \IntlDateFormatter::LONG
        );
    }

    /**
     * Get order items.
     *
     * @return Item[]
     */
    public function getOrderItems(): array
    {
        $order = $this->getOrder();

        if (!$order) {
            return [];
        }

        return $order->getAllVisibleItems();
    }

    /**
     * Get item product name.
     */
    public function getItemName(Item $item): string
    {
        return (string)$item->getName();
    }

    /**
     * Get item quantity.
     */
    public function getItemQty(Item $item): string
    {
        return number_format(
            (float)$item->getQtyOrdered(),
            0
        );
    }

    /**
     * Get item row total.
     */
    public function getItemPrice(Item $item): string
    {
        return $this->formatPrice(
            (float)$item->getRowTotalInclTax()
        );
    }

    /**
     * Get subtotal.
     */
    public function getSubtotal(): string
    {
        $order = $this->getOrder();

        if (!$order) {
            return $this->formatPrice(0);
        }

        return $this->formatPrice(
            (float)$order->getSubtotalInclTax()
        );
    }

    /**
     * Get grand total.
     */
    public function getGrandTotal(): string
    {
        $order = $this->getOrder();

        if (!$order) {
            return $this->formatPrice(0);
        }

        return $this->formatPrice(
            (float)$order->getGrandTotal()
        );
    }

    /**
     * Get payment method title.
     */
    public function getPaymentMethodTitle(): string
    {
        $order = $this->getOrder();

        if (!$order) {
            return 'CloudRight';
        }

        $payment = $order->getPayment();

        if (!$payment instanceof Payment) {
            return 'CloudRight';
        }

        $title = (string)$payment->getMethodInstance()->getTitle();

        return $title !== '' ? $title : 'CloudRight';
    }

    /**
     * Get continue shopping URL.
     */
    public function getContinueUrl(): string
    {
        return $this->getUrl('');
    }

    /**
     * Format a price using the order currency.
     */
    private function formatPrice(float $amount): string
    {
        $order = $this->getOrder();

        if (!$order) {
            return number_format($amount, 2);
        }

        return $this->formatPriceByCurrency(
            $amount,
            (string)$order->getOrderCurrencyCode()
        );
    }

    /**
     * Format amount with currency symbol.
     */
    private function formatPriceByCurrency(
        float $amount,
        string $currencyCode
    ): string {
        $currency = $this->_storeManager
            ->getStore()
            ->getBaseCurrency();

        if ($currencyCode !== '') {
            $currency = $this->_storeManager
                ->getStore()
                ->getCurrentCurrency();
        }

        return $currency->format(
            $amount,
            [
                'display' => \Magento\Framework\Pricing\PriceCurrencyInterface::DEFAULT_PRECISION
            ],
            false
        );
    }
}

