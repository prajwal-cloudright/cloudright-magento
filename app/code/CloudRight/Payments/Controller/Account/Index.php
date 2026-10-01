<?php
/**
 * CloudRight customer account page.
 *
 * This page reads the existing Magento customer session.
 * It does not modify Magento's native authentication.
 */
declare(strict_types=1);

namespace CloudRight\Payments\Controller\Account;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    private PageFactory $pageFactory;
    private CustomerSession $customerSession;

    public function __construct(
        PageFactory $pageFactory,
        CustomerSession $customerSession
    ) {
        $this->pageFactory = $pageFactory;
        $this->customerSession = $customerSession;
    }

    public function execute(): Page
    {
        return $this->pageFactory->create();
    }
}

