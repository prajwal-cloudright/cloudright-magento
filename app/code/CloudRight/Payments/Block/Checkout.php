<?php
/**
 * CloudRight checkout modal block.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * View block for the CloudRight checkout modal rendered on top of the
 * Magento cart page. Holds only presentation/wiring logic (API URLs,
 * static asset URL, module version) - no business logic. All business
 * logic lives in the CloudRight\Payments\Model and Api namespaces.
 */
class Checkout extends Template
{
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->storeManager = $storeManager;
    }

    /**
     * Frontend URL of the cart data endpoint (GET /cloudright/cart).
     *
     * @return string
     */
    public function getCartApiUrl(): string
    {
        return $this->getUrl(
            'cloudright/cart',
            ['_secure' => $this->_isSecure()]
        );
    }

    /**
     * Frontend URL of the order creation endpoint
     * (POST /cloudright/payment/process).
     *
     * @return string
     */
    public function getOrderApiUrl(): string
    {
        return $this->getUrl(
            'cloudright/payment/process',
            ['_secure' => $this->_isSecure()]
        );
    }

    /**
     * URL the customer returns to when clicking "Continue Shopping".
     *
     * @return string
     */
    public function getCartPageUrl(): string
    {
        return $this->getUrl(
            'checkout/cart',
            ['_secure' => $this->_isSecure()]
        );
    }

    /**
     * Magento standard Order Received / Thank You page URL.
     *
     * @return string
     */
    public function getSuccessPageUrl(): string
    {
        return $this->getUrl(
            'checkout/onepage/success',
            ['_secure' => $this->_isSecure()]
        );
    }

    /**
     * @return bool
     */
    private function _isSecure(): bool
    {
        try {
            return (bool)$this->storeManager->getStore()->isCurrentlySecure();
        } catch (\Exception $e) {
            return false;
        }
    }
}

