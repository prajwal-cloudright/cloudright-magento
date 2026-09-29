<?php

namespace CloudRight\Payments\Model;

use CloudRight\Payments\Api\Data\OrderResultInterface;
use CloudRight\Payments\Api\Data\OrderResultInterfaceFactory;
use CloudRight\Payments\Api\OrderManagementInterface;
use CloudRight\Payments\Api\PaymentInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
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
        TransactionFactory $transactionFactory
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
    }

    public function createOrder(string $email, string $transactionId): OrderResultInterface
    {
        $email = trim($email);
        $transactionId = trim($transactionId);

        $this->validateEmail($email);
        $this->validateTransactionId($transactionId);

        $quote = $this->getActiveQuote();
        $this->validateQuoteHasItems($quote);

        $quote->setCustomerEmail($email);

        if (!$quote->getCustomerId()) {
            $quote->setCustomerIsGuest(true);
        }

        $this->prepareAddresses($quote, $email);
        $this->assignPaymentMethod($quote);
        $quote->collectTotals();

        try {
            $this->quoteRepository->save($quote);
            $orderId = $this->quoteManagement->placeOrder($quote->getId());
        } catch (LocalizedException $e) {
            $this->logger->error(
                'CloudRight: failed to place order.',
                ['exception' => $e]
            );

            throw new CouldNotSaveException(
                __('CloudRight was unable to create the order: %1', $e->getMessage())
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'CloudRight: unexpected error while placing order.',
                ['exception' => $e]
            );

            throw new CouldNotSaveException(
                __('CloudRight was unable to create the order due to an unexpected error.')
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
         * Create and capture the Magento invoice so that Magento records:
         * - Total Paid
         * - Invoice
         * - Total Due
         *
         * The custom payment method's capture() method handles the
         * development/test capture operation.
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
                        __('The CloudRight invoice could not be created because the order has no items.')
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
                    __('CloudRight payment succeeded, but Magento could not complete the invoice.')
                );
            }
        }

        $this->orderRepository->save($order);

        /*
         * Clear the old checkout storage before preparing the
         * standard Magento success-page session values.
         *
         * clearStorage() can remove checkout session values,
         * so it must happen before the success values are written.
         */
        $this->checkoutSession->clearStorage();

        /*
         * Store the completed checkout information in Magento's
         * checkout session.
         *
         * The standard Magento Order Received / Success page uses
         * these session values to identify the completed order.
         */
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
                    ? (string)__('CloudRight order created successfully.')
                    : (string)__('CloudRight order was created but payment is pending review.')
            );
    }

    private function validateEmail(string $email): void
    {
        if ($email === '' || !$this->emailValidator->isValid($email)) {
            throw new LocalizedException(
                __('Please provide a valid email address.')
            );
        }
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (
            $transactionId === ''
            || !$this->paymentService->isValidTransactionId($transactionId)
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
