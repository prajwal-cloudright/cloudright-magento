<?php

namespace CloudRight\Payments\Model;

use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;

class PaymentMethod extends AbstractMethod
{
    public const CODE = 'cloudright';

    protected $_code = self::CODE;
    protected $_isOffline = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = false;
    protected $_canVoid = false;

    public function isAvailable(?CartInterface $quote = null): bool
    {
        return parent::isAvailable($quote);
    }

    /**
     * Local test authorization.
     * The dummy payment service handles the test transaction separately.
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * Local test capture.
     * Marks the Magento payment transaction as captured/closed.
     */
    public function capture(InfoInterface $payment, $amount)
    {
        $payment->setIsTransactionClosed(true);

        return $this;
    }
}

