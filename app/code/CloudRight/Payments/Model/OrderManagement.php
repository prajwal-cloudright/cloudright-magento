<?php

namespace CloudRight\Payments\Model;

use CloudRight\Payments\Api\Data\OrderResultInterface;
use CloudRight\Payments\Api\Data\OrderResultInterfaceFactory;
use CloudRight\Payments\Api\OrderManagementInterface;
use CloudRight\Payments\Api\PaymentInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Validator\EmailAddress as EmailAddressValidator;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class OrderManagement implements OrderManagementInterface
{
    private const DEFAULT_ADDRESS_FIRSTNAME = 'CloudRight';
    private const DEFAULT_ADDRESS_LASTNAME = 'Customer';
    private const DEFAULT_ADDRESS_TELEPHONE = '0000000000';
    private const DEFAULT_ADDRESS_POSTCODE = '00000';
    private const DEFAULT_ADDRESS_CITY = 'Bengaluru';
    private const DEFAULT_ADDRESS_STREET = 'N/A';

    private CheckoutSession $checkoutSession;
    private CartRepositoryInterface $quoteRepository;
    private QuoteManagement $quoteManagement;
    private OrderRepositoryInterface $orderRepository;
    private PaymentInterface $paymentService;
    private OrderResultInterfaceFactory $orderResultFactory;
    private EmailAddressValidator $emailValidator;
    private \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    private RegionCollectionFactory $regionCollectionFactory;
    private InvoiceService $invoiceService;
    private TransactionFactory $transactionFactory;
    private CustomerRepositoryInterface $customerRepository;
    private StoreManagerInterface $storeManager;
    private ResourceConnection $resourceConnection;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        QuoteManagement $quoteManagement,
        OrderRepositoryInterface $orderRepository,
        PaymentInterface $paymentService,
        OrderResultInterfaceFactory $orderResultFactory,
        EmailAddressValidator $emailValidator,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        RegionCollectionFactory $regionCollectionFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->orderRepository = $orderRepository;
        $this->paymentService = $paymentService;
        $this->orderResultFactory = $orderResultFactory;
        $this->emailValidator = $emailValidator;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->regionCollectionFactory = $regionCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Existing storefront checkout flow.
     *
     * This method continues to use the Magento checkout session.
     */
    public function createOrder(
        string $email,
        string $transactionId
    ): OrderResultInterface {
        $email = trim($email);
        $transactionId = trim($transactionId);

        $this->validateEmail($email);
        $this->validateTransactionId($transactionId);

        $quote = $this->getActiveQuote();
        $this->validateQuoteHasItems($quote);

        $quote->setCustomerEmail($email);

        /*
         * Link the quote to an existing Magento customer.
         *
         * If a customer with this email exists for the current
         * website, assign that customer to the quote.
         *
         * If no customer exists, preserve guest checkout.
         */
        if (!$quote->getCustomerId()) {
            try {
                $customer = $this->customerRepository->get(
                    $email,
                    (int)$this->storeManager
                        ->getStore()
                        ->getWebsiteId()
                );

                $quote->assignCustomer($customer);
                $quote->setCustomerIsGuest(false);
                $quote->setCustomerEmail(
                    (string)$customer->getEmail()
                );
            } catch (NoSuchEntityException $e) {
                $quote->setCustomerIsGuest(true);
            }
        } else {
            $quote->setCustomerIsGuest(false);
        }

        $this->prepareAddresses($quote, $email);
        $this->assignPaymentMethod($quote);
        $quote->collectTotals();

        try {
            $this->quoteRepository->save($quote);

            $orderId = $this->quoteManagement->placeOrder(
                $quote->getId()
            );
        } catch (LocalizedException $e) {
            $this->logger->error(
                'CloudRight: failed to place order.',
                ['exception' => $e]
            );

            throw new CouldNotSaveException(
                __(
                    'CloudRight was unable to create the order: %1',
                    $e->getMessage()
                )
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'CloudRight: unexpected error while placing order.',
                ['exception' => $e]
            );

            throw new CouldNotSaveException(
                __(
                    'CloudRight was unable to create the order due to an unexpected error.'
                )
            );
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            throw new CouldNotSaveException(
                __('CloudRight order was placed but could not be reloaded.')
            );
        }

        $paymentStatus = $this->paymentService->processPayment(
            (int)$orderId,
            $transactionId
        );

        $order->setData(
            'cloudright_transaction_id',
            $transactionId
        );

        $order->setData(
            'cloudright_payment_status',
            $paymentStatus
        );

        /*
         * CloudRight test payment was successful.
         *
         * Create and capture the Magento invoice.
         */
        if ($paymentStatus === PaymentInterface::STATUS_PAID) {
            try {
                if (!$order->canInvoice()) {
                    throw new LocalizedException(
                        __('The CloudRight order cannot be invoiced.')
                    );
                }

                $invoice = $this->invoiceService->prepareInvoice($order);

                if (!$invoice->getTotalQty()) {
                    throw new LocalizedException(
                        __(
                            'The CloudRight invoice could not be created because the order has no items.'
                        )
                    );
                }

                $invoice->setRequestedCaptureCase(
                    Invoice::CAPTURE_ONLINE
                );

                $invoice->register();

                $invoice->getOrder()->setIsInProcess(true);

                $transaction = $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());

                $transaction->save();

                $order = $invoice->getOrder();

                $this->logger->info(
                    'CloudRight: Magento invoice created and captured.',
                    [
                        'order_id' => $orderId,
                        'invoice_id' => $invoice->getId(),
                        'transaction_id' => $transactionId
                    ]
                );
            } catch (LocalizedException $e) {
                $this->logger->error(
                    'CloudRight: payment was successful but invoice creation failed.',
                    [
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'exception' => $e
                    ]
                );

                throw new CouldNotSaveException(
                    __(
                        'CloudRight payment succeeded, but the Magento invoice could not be created: %1',
                        $e->getMessage()
                    )
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'CloudRight: unexpected error while creating invoice.',
                    [
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'exception' => $e
                    ]
                );

                throw new CouldNotSaveException(
                    __(
                        'CloudRight payment succeeded, but Magento could not complete the invoice.'
                    )
                );
            }
        }

        $this->orderRepository->save($order);

        /*
         * Preserve the existing storefront success-page session behavior.
         */
        $this->checkoutSession->clearStorage();

        if ($paymentStatus === PaymentInterface::STATUS_PAID) {
            $this->checkoutSession->setLastQuoteId(
                (int)$quote->getId()
            );

            $this->checkoutSession->setLastSuccessQuoteId(
                (int)$quote->getId()
            );

            $this->checkoutSession->setLastOrderId(
                (int)$order->getId()
            );

            $this->checkoutSession->setLastRealOrderId(
                (string)$order->getIncrementId()
            );

            $this->logger->info(
                'CloudRight: checkout success session prepared.',
                [
                    'quote_id' => $quote->getId(),
                    'order_id' => $order->getId(),
                    'order_increment_id' => $order->getIncrementId()
                ]
            );
        }

        return $this->orderResultFactory->create()
            ->setSuccess(
                $paymentStatus === PaymentInterface::STATUS_PAID
            )
            ->setOrderId(
                (int)$orderId
            )
            ->setOrderIncrementId(
                $order->getIncrementId()
            )
            ->setTransactionId(
                $transactionId
            )
            ->setMessage(
                $paymentStatus === PaymentInterface::STATUS_PAID
                    ? (string)__(
                        'CloudRight order created successfully.'
                    )
                    : (string)__(
                        'CloudRight order was created but payment is pending review.'
                    )
            );
    }

    /**
     * Create an order from a specific masked Magento cart ID.
     *
     * This method is used by the authenticated REST API.
     *
     * Unlike createOrder(), this method does not depend on the
     * browser checkout session.
     */
    public function createOrderForCart(
        string $cartId,
        string $email,
        string $transactionId
    ): OrderResultInterface {
        $cartId = trim($cartId);
        $email = trim($email);
        $transactionId = trim($transactionId);

        if ($cartId === '') {
            throw new LocalizedException(
                __('A valid cart id is required.')
            );
        }

        $this->validateEmail($email);
        $this->validateTransactionId($transactionId);

        /*
         * Resolve Magento's masked cart ID to the internal quote ID.
         */
        $connection = $this->resourceConnection->getConnection();

        $quoteIdMaskTable = $this->resourceConnection->getTableName(
            'quote_id_mask'
        );

        $select = $connection->select()
            ->from(
                $quoteIdMaskTable,
                ['quote_id']
            )
            ->where(
                'masked_id = ?',
                $cartId
            )
            ->limit(1);

        $quoteId = $connection->fetchOne($select);

        if (!$quoteId) {
            throw new LocalizedException(
                __('The specified cart could not be found.')
            );
        }

        /*
         * Load the quote directly from Magento's quote repository.
         */
        try {
            $quote = $this->quoteRepository->get(
                (int)$quoteId
            );
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(
                __('The specified cart could not be found.')
            );
        }

        if (!$quote->getId()) {
            throw new LocalizedException(
                __('The specified cart could not be found.')
            );
        }

        if (!$quote->getIsActive()) {
            throw new LocalizedException(
                __('The specified cart is no longer active.')
            );
        }

        $this->validateQuoteHasItems($quote);

        /*
         * Set the customer email.
         */
        $quote->setCustomerEmail($email);

        /*
         * Link the quote to the Magento customer if the customer
         * already exists for the current website.
         */
        if (!$quote->getCustomerId()) {
            try {
                $customer = $this->customerRepository->get(
                    $email,
                    (int)$this->storeManager
                        ->getStore()
                        ->getWebsiteId()
                );

                $quote->assignCustomer($customer);
                $quote->setCustomerIsGuest(false);
                $quote->setCustomerEmail(
                    (string)$customer->getEmail()
                );
            } catch (NoSuchEntityException $e) {
                /*
                 * If the customer does not exist, preserve guest order behavior.
                 */
                $quote->setCustomerIsGuest(true);
            }
        } else {
            $quote->setCustomerIsGuest(false);
        }

        /*
         * Prepare the same address/payment information used
         * by the existing storefront checkout.
         */
        $this->prepareAddresses(
            $quote,
            $email
        );

        $this->assignPaymentMethod($quote);

        $quote->collectTotals();

        /*
         * Save the quote and create the Magento order.
         */
        try {
            $this->quoteRepository->save($quote);

            $orderId = $this->quoteManagement->placeOrder(
                $quote->getId()
            );
        } catch (LocalizedException $e) {
            $this->logger->error(
                'CloudRight: failed to place REST API order.',
                [
                    'cart_id' => $cartId,
                    'quote_id' => $quote->getId(),
                    'exception' => $e
                ]
            );

            throw new CouldNotSaveException(
                __(
                    'CloudRight was unable to create the order: %1',
                    $e->getMessage()
                )
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'CloudRight: unexpected error while placing REST API order.',
                [
                    'cart_id' => $cartId,
                    'quote_id' => $quote->getId(),
                    'exception' => $e
                ]
            );

            throw new CouldNotSaveException(
                __(
                    'CloudRight was unable to create the order due to an unexpected error.'
                )
            );
        }

        /*
         * Reload the newly created order.
         */
        try {
            $order = $this->orderRepository->get(
                $orderId
            );
        } catch (NoSuchEntityException $e) {
            throw new CouldNotSaveException(
                __('CloudRight order was placed but could not be reloaded.')
            );
        }

        /*
         * Process the CloudRight payment.
         */
        $paymentStatus = $this->paymentService->processPayment(
            (int)$orderId,
            $transactionId
        );

        $order->setData(
            'cloudright_transaction_id',
            $transactionId
        );

        $order->setData(
            'cloudright_payment_status',
            $paymentStatus
        );

        /*
         * Create and capture Magento invoice when payment succeeds.
         */
        if ($paymentStatus === PaymentInterface::STATUS_PAID) {
            try {
                if (!$order->canInvoice()) {
                    throw new LocalizedException(
                        __('The CloudRight order cannot be invoiced.')
                    );
                }

                $invoice = $this->invoiceService->prepareInvoice(
                    $order
                );

                if (!$invoice->getTotalQty()) {
                    throw new LocalizedException(
                        __(
                            'The CloudRight invoice could not be created because the order has no items.'
                        )
                    );
                }

                $invoice->setRequestedCaptureCase(
                    Invoice::CAPTURE_ONLINE
                );

                $invoice->register();

                $invoice->getOrder()->setIsInProcess(true);

                $transaction = $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());

                $transaction->save();

                $order = $invoice->getOrder();

                $this->logger->info(
                    'CloudRight: REST API invoice created and captured.',
                    [
                        'cart_id' => $cartId,
                        'quote_id' => $quote->getId(),
                        'order_id' => $orderId,
                        'invoice_id' => $invoice->getId(),
                        'transaction_id' => $transactionId
                    ]
                );
            } catch (LocalizedException $e) {
                $this->logger->error(
                    'CloudRight: REST API payment succeeded but invoice creation failed.',
                    [
                        'cart_id' => $cartId,
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'exception' => $e
                    ]
                );

                throw new CouldNotSaveException(
                    __(
                        'CloudRight payment succeeded, but the Magento invoice could not be created: %1',
                        $e->getMessage()
                    )
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'CloudRight: unexpected REST API invoice error.',
                    [
                        'cart_id' => $cartId,
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'exception' => $e
                    ]
                );

                throw new CouldNotSaveException(
                    __(
                        'CloudRight payment succeeded, but Magento could not complete the invoice.'
                    )
                );
            }
        }

        /*
         * Save the final order state.
         *
         * We intentionally do NOT modify checkoutSession here.
         * This is an authenticated REST API request and does not
         * depend on the browser checkout session.
         */
        $this->orderRepository->save($order);

        return $this->orderResultFactory->create()
            ->setSuccess(
                $paymentStatus === PaymentInterface::STATUS_PAID
            )
            ->setOrderId(
                (int)$orderId
            )
            ->setOrderIncrementId(
                $order->getIncrementId()
            )
            ->setTransactionId(
                $transactionId
            )
            ->setMessage(
                $paymentStatus === PaymentInterface::STATUS_PAID
                    ? (string)__(
                        'CloudRight order created successfully.'
                    )
                    : (string)__(
                        'CloudRight order was created but payment is pending review.'
                    )
            );
    }

    private function validateEmail(string $email): void
    {
        if (
            $email === ''
            || !$this->emailValidator->isValid($email)
        ) {
            throw new LocalizedException(
                __('Please provide a valid email address.')
            );
        }
    }

    private function validateTransactionId(
        string $transactionId
    ): void {
        if (
            $transactionId === ''
            || !$this->paymentService->isValidTransactionId(
                $transactionId
            )
        ) {
            throw new LocalizedException(
                __('A valid CloudRight transaction id is required.')
            );
        }
    }

    private function getActiveQuote(): Quote
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            throw new LocalizedException(
                __('Unable to load the current cart.')
            );
        }

        if (!$quote || !$quote->getId()) {
            throw new LocalizedException(
                __('Your cart could not be found.')
            );
        }

        return $quote;
    }

    private function validateQuoteHasItems(Quote $quote): void
    {
        if ((int)$quote->getItemsCount() <= 0) {
            throw new LocalizedException(
                __('Your cart is empty.')
            );
        }
    }

    private function prepareAddresses(
        Quote $quote,
        string $email
    ): void {
        $countryId = (string)$this->scopeConfig->getValue(
            DirectoryHelper::XML_PATH_DEFAULT_COUNTRY,
            ScopeInterface::SCOPE_STORE
        );

        if ($countryId === '') {
            $countryId = 'IN';
        }

        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->getShippingAddress();

        if ($billingAddress) {
            $this->completeAddress(
                $billingAddress,
                $email,
                $countryId
            );
        }

        if ($quote->isVirtual() || !$shippingAddress) {
            return;
        }

        $this->completeAddress(
            $shippingAddress,
            $email,
            $countryId
        );

        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();

        if (!$shippingAddress->getShippingMethod()) {
            $methodCode = $this->resolveFirstAvailableShippingMethod(
                $shippingAddress
            );

            if ($methodCode) {
                $shippingAddress->setShippingMethod(
                    $methodCode
                );
            }
        }
    }

    private function completeAddress(
        $address,
        string $email,
        string $defaultCountryId
    ): void {
        $countryId = (string)$address->getCountryId();

        if ($countryId === '') {
            $countryId = $defaultCountryId;
            $address->setCountryId($countryId);
        }

        if (!$address->getFirstname()) {
            $address->setFirstname(
                self::DEFAULT_ADDRESS_FIRSTNAME
            );
        }

        if (!$address->getLastname()) {
            $address->setLastname(
                self::DEFAULT_ADDRESS_LASTNAME
            );
        }

        if (!$address->getStreetLine(1)) {
            $address->setStreet([
                self::DEFAULT_ADDRESS_STREET
            ]);
        }

        if (!$address->getCity()) {
            $address->setCity(
                self::DEFAULT_ADDRESS_CITY
            );
        }

        if (!$address->getTelephone()) {
            $address->setTelephone(
                self::DEFAULT_ADDRESS_TELEPHONE
            );
        }

        if (!$address->getPostcode()) {
            $address->setPostcode(
                self::DEFAULT_ADDRESS_POSTCODE
            );
        }

        if (!$address->getEmail()) {
            $address->setEmail($email);
        }

        if (!$address->getRegionId()) {
            $region = $this->regionCollectionFactory->create()
                ->addCountryFilter($countryId)
                ->setOrder('region_id', 'ASC')
                ->getFirstItem();

            if ($region->getId()) {
                $address->setRegionId(
                    (int)$region->getId()
                );

                $address->setRegion(
                    (string)$region->getDefaultName()
                );
            }
        }
    }

    private function resolveFirstAvailableShippingMethod(
        $shippingAddress
    ): ?string {
        foreach (
            $shippingAddress->getGroupedAllShippingRates()
            as $carrierRates
        ) {
            foreach ($carrierRates as $rate) {
                if ($rate->getCarrier() && $rate->getMethod()) {
                    return $rate->getCarrier()
                        . '_'
                        . $rate->getMethod();
                }
            }
        }

        return null;
    }

    private function assignPaymentMethod(Quote $quote): void
    {
        $quote->getPayment()->importData([
            'method' => PaymentMethod::CODE
        ]);
    }
}

