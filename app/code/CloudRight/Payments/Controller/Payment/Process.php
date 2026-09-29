<?php
/**
 * CloudRight payment/order processing endpoint.
 *
 * @copyright Copyright (c) CloudRight
 */
declare(strict_types=1);

namespace CloudRight\Payments\Controller\Payment;

use CloudRight\Payments\Api\OrderManagementInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * POST /cloudright/payment/process
 *
 * Storefront-facing endpoint used by cloudright-checkout.js to turn the
 * current cart into a real Magento order once the (test) CloudRight
 * transaction id has been generated. All actual business logic is
 * delegated to CloudRight\Payments\Api\OrderManagementInterface - this
 * controller only handles HTTP request/response concerns.
 *
 * This action must work for both guest and logged-in customers, so CSRF
 * form-key validation is bypassed the same way Magento's own AJAX guest
 * endpoints (e.g. add-to-cart) do; all input is still fully validated
 * server-side in the OrderManagement service.
 */
class Process implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var JsonFactory
     */
    private JsonFactory $jsonFactory;

    /**
     * @var OrderManagementInterface
     */
    private OrderManagementInterface $orderManagement;

    /**
     * @var JsonSerializer
     */
    private JsonSerializer $jsonSerializer;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param OrderManagementInterface $orderManagement
     * @param JsonSerializer $jsonSerializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        RequestInterface $request,
        JsonFactory $jsonFactory,
        OrderManagementInterface $orderManagement,
        JsonSerializer $jsonSerializer,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->orderManagement = $orderManagement;
        $this->jsonSerializer = $jsonSerializer;
        $this->logger = $logger;
    }

    /**
     * @return Json
     */
    public function execute()
    {
        /** @var Json $result */
        $result = $this->jsonFactory->create();

        $payload = $this->getJsonBody();
        $email = isset($payload['email']) ? (string)$payload['email'] : '';
        $transactionId = isset($payload['transactionId']) ? (string)$payload['transactionId'] : '';

        try {
            $orderResult = $this->orderManagement->createOrder($email, $transactionId);

            return $result->setData([
                'success' => $orderResult->getSuccess(),
                'order_id' => $orderResult->getOrderId(),
                'order_increment_id' => $orderResult->getOrderIncrementId(),
                'transaction_id' => $orderResult->getTransactionId(),
                'message' => $orderResult->getMessage(),
            ]);
        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CloudRight: unexpected error while processing payment.', ['exception' => $e]);

            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => __('An unexpected error occurred while creating your order. Please try again.'),
            ]);
        }
    }

    /**
     * Safely decode the JSON request body.
     *
     * @return array
     */
    private function getJsonBody(): array
    {
        $content = (string)$this->request->getContent();
        if ($content === '') {
            return [];
        }

        try {
            $data = $this->jsonSerializer->unserialize($content);
        } catch (\Exception $e) {
            return [];
        }

        return is_array($data) ? $data : [];
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
