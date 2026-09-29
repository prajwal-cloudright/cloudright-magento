<?php
/**
 * CloudRight checkout fallback controller.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * GET /cloudright/checkout
 *
 * The CloudRight checkout experience is a modal overlay rendered directly
 * on Magento's own cart page (see etc/frontend/routes.xml,
 * view/frontend/layout/checkout_cart_index.xml) - there is no separate
 * CloudRight checkout page. This controller exists only so the
 * Controller/Checkout route is present and behaves predictably (it sends
 * the customer to the cart page, where the CloudRight modal opens) if it
 * is ever linked to directly.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @var RedirectFactory
     */
    private RedirectFactory $redirectFactory;

    /**
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(RedirectFactory $redirectFactory)
    {
        $this->redirectFactory = $redirectFactory;
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
