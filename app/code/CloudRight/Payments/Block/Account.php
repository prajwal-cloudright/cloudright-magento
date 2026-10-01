<?php
/**
 * CloudRight customer account block.
 */
declare(strict_types=1);

namespace CloudRight\Payments\Block;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;

class Account extends Template
{
    private CustomerSession $customerSession;

    public function __construct(
        Template\Context $context,
        CustomerSession $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->customerSession = $customerSession;
    }

    public function isLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    public function getCustomerEmail(): string
    {
        if (!$this->isLoggedIn()) {
            return '';
        }

        return (string)$this->customerSession
            ->getCustomer()
            ->getEmail();
    }

    public function getCustomerName(): string
    {
        if (!$this->isLoggedIn()) {
            return '';
        }

        $customer = $this->customerSession->getCustomer();

        return trim(
            (string)$customer->getFirstname() .
            ' ' .
            (string)$customer->getLastname()
        );
    }

    public function getLogoutUrl(): string
    {
        return $this->getUrl('customer/account/logout');
    }

    public function getLoginUrl(): string
    {
        return $this->getUrl('customer/account/login');
    }
}

