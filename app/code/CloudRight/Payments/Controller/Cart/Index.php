<?php
/**
 * CloudRight cart JSON endpoint.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Controller\Cart;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;

/**
 * GET /cloudright/cart
 *
 * Returns the current Magento quote (guest or logged-in) as JSON for the
 * CloudRight checkout modal. Reads exclusively through Magento's own
 * checkout session / quote services - no direct database access.
 */
class Index implements HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var JsonFactory
     */
    private JsonFactory $jsonFactory;

    /**
     * @var PricingHelper
     */
    private PricingHelper $pricingHelper;

    /**
     * @var CatalogImageHelper
     */
    private CatalogImageHelper $imageHelper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param CheckoutSession $checkoutSession
     * @param JsonFactory $jsonFactory
     * @param PricingHelper $pricingHelper
     * @param CatalogImageHelper $imageHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        JsonFactory $jsonFactory,
        PricingHelper $pricingHelper,
        CatalogImageHelper $imageHelper,
        LoggerInterface $logger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->jsonFactory = $jsonFactory;
        $this->pricingHelper = $pricingHelper;
        $this->imageHelper = $imageHelper;
        $this->logger = $logger;
    }

    /**
     * @return Json
     */
    public function execute()
    {
        /** @var Json $result */
        $result = $this->jsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();

            return $result->setData($this->buildCartPayload($quote));
        } catch (\Exception $e) {
            $this->logger->error('CloudRight: failed to build cart payload.', ['exception' => $e]);

            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => __('Unable to load your cart right now. Please try again.'),
            ]);
        }
    }

    /**
     * @param CartInterface $quote
     * @return array
     */
    private function buildCartPayload(CartInterface $quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = $this->buildItemPayload($item);
        }

        $subtotal = (float)$quote->getSubtotal();
        $shippingAmount = 0.0;
        $shippingAddress = $quote->isVirtual() ? null : $quote->getShippingAddress();
        if ($shippingAddress) {
            $shippingAmount = (float)$shippingAddress->getShippingAmount();
        }
        $grandTotal = (float)$quote->getGrandTotal();

        return [
            'success' => true,
            'items' => $items,
            'item_count' => count($items),
            'totals' => [
                'subtotal' => $this->formatMoney($subtotal),
                'shipping' => $this->formatMoney($shippingAmount),
                'grand_total' => $this->formatMoney($grandTotal),
            ],
        ];
    }

    /**
     * @param QuoteItem $item
     * @return array
     */
    private function buildItemPayload(QuoteItem $item): array
    {
        $imageUrl = '';
        try {
            $product = $item->getProduct();
            if ($product) {
                $imageUrl = (string)$this->imageHelper
                    ->init($product, 'cart_page_product_thumbnail')
                    ->getUrl();
            }
        } catch (\Exception $e) {
            $imageUrl = '';
        }

        return [
            'item_id' => (int)$item->getItemId(),
            'product_id' => (int)$item->getProductId(),
            'sku' => (string)$item->getSku(),
            'name' => (string)$item->getName(),
            'qty' => (float)$item->getQty(),
            'price' => $this->formatMoney((float)$item->getPrice()),
            'row_total' => $this->formatMoney((float)$item->getRowTotal()),
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @param float $amount
     * @return array
     */
    private function formatMoney(float $amount): array
    {
        return [
            'value' => round($amount, 2),
            'formatted' => $this->pricingHelper->currency($amount, true, false),
        ];
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
