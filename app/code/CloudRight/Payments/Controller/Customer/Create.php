<?php
/**
 * CloudRight customer creation endpoint.
 */
declare(strict_types=1);

namespace CloudRight\Payments\Controller\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\AccountManagement;

class Create implements HttpPostActionInterface
{
    private JsonFactory $jsonFactory;
    private CustomerInterfaceFactory $customerFactory;
    private CustomerRepositoryInterface $customerRepository;
    private CustomerCollectionFactory $customerCollectionFactory;
    private StoreManagerInterface $storeManager;
    private Random $random;
    private AccountManagement $accountManagement;
    private Request $request;

    public function __construct(
        JsonFactory $jsonFactory,
        CustomerInterfaceFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository,
        CustomerCollectionFactory $customerCollectionFactory,
        StoreManagerInterface $storeManager,
        Random $random,
        AccountManagement $accountManagement,
        Request $request
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->storeManager = $storeManager;
        $this->random = $random;
        $this->accountManagement = $accountManagement;
        $this->request = $request;
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $data = $this->request->getBodyParams();

            $email = trim((string)($data['email'] ?? ''));
            $firstname = trim((string)($data['firstname'] ?? ''));
            $lastname = trim((string)($data['lastname'] ?? ''));

            if ($email === '') {
                throw new LocalizedException(
                    __('Email address is required.')
                );
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new LocalizedException(
                    __('Please provide a valid email address.')
                );
            }

            /*
             * Check whether the customer already exists.
             */
            try {
                $existingCustomer =
                    $this->customerRepository->get(
                        $email,
                        $this->storeManager
                            ->getStore()
                            ->getWebsiteId()
                    );

                return $result->setData([
                    'success' => true,
                    'existing' => true,
                    'customer' => $this->customerData(
                        $existingCustomer
                    ),
                ]);
            } catch (NoSuchEntityException $e) {
                /*
                 * Customer does not exist.
                 * Continue with creation.
                 */
            }

            if ($firstname === '') {
                $firstname = 'CloudRight';
            }

            if ($lastname === '') {
                $lastname = 'Customer';
            }

            /*
             * Generate a temporary password.
             *
             * The CloudRight flow currently uses email-based
             * checkout, so the password is not exposed to the
             * customer through this endpoint.
             */
            $password =
                $this->random->getRandomString(
                    20
                );

            $customer =
                $this->customerFactory->create();

            $customer->setWebsiteId(
                $this->storeManager
                    ->getStore()
                    ->getWebsiteId()
            );

            $customer->setEmail($email);
            $customer->setFirstname($firstname);
            $customer->setLastname($lastname);

            $createdCustomer =
                $this->accountManagement->createAccount(
                    $customer,
                    $password
                );

            return $result->setData([
                'success' => true,
                'existing' => false,
                'customer' => $this->customerData(
                    $createdCustomer
                ),
            ]);

        } catch (LocalizedException $e) {

            return $result
                ->setHttpResponseCode(400)
                ->setData([
                    'success' => false,
                    'message' => $e->getMessage(),
                ]);

        } catch (\Throwable $e) {

            return $result
                ->setHttpResponseCode(500)
                ->setData([
                    'success' => false,
                    'message' => __(
                        'Unable to create the customer account.'
                    ),
                ]);
        }
    }

    private function customerData(
        \Magento\Customer\Api\Data\CustomerInterface $customer
    ): array {
        return [
            'id' =>
                (int)$customer->getId(),

            'email' =>
                (string)$customer->getEmail(),

            'firstname' =>
                (string)$customer->getFirstname(),

            'lastname' =>
                (string)$customer->getLastname(),

            'name' =>
                trim(
                    (string)$customer->getFirstname() .
                    ' ' .
                    (string)$customer->getLastname()
                ),
        ];
    }
}