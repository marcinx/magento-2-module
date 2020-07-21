<?php

namespace Coinqvest\PaymentGateway\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session\Proxy;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Order;

class Webhook extends Action
{
    private $checkoutSession;
    private $file;
    private $jsonResultFactory;
    private $scopeConfig;
    private $searchCriteriaBuilder;
    private $orderRepository;
    private $orderCollectionFactory;

    public function __construct(
        Context $context,
        Proxy $checkoutSession,
        File $file,
        JsonFactory $jsonResultFactory,
        ScopeConfigInterface $scopeConfig,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository,
        CollectionFactory $orderCollectionFactory
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->file = $file;
        $this->jsonResultFactory = $jsonResultFactory;
        $this->scopeConfig = $scopeConfig;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;

        $this->execute();
    }

    public function execute()
    {
        try {

            $payload = $this->file->read('php://input');

            /**
             * Get request headers and validate
             */

            $request_headers = array_change_key_case($this->get_request_headers(), CASE_UPPER);

            if (!$this->validate_webhook($request_headers, $payload))
            {
                $result = $this->jsonResultFactory->create();
                $result->setHttpResponseCode(401);
                $result->setData(['success' => false, 'message' => __('Webhook validation failed.')]);
                return $result;
            }

            $payloadArray = json_decode($payload, true);

            /**
             * Find Magento order by Coinqvest checkout id
             */

            $order = $this->getOrder($payloadArray['checkoutId']);

            if (!$order)
            {
                $result = $this->jsonResultFactory->create();
                $result->setHttpResponseCode(400);
                $result->setData(['success' => false, 'message' => __('Could not find matching order.')]);
                return $result;
            }

            /**
             * Update Magento payment with Coinqvest transaction id
             */

            $payment = $order->getPayment();
            $payment->setCoinqvestTxId($payloadArray['id']);
            $payment->save();

            /**
             * Update Magento order
             */

            $order->addStatusHistoryComment('Order PAID via COINQVEST. See payment details <a href="https://www.coinqvest.com/en/payment/' . $payloadArray['id'] . '" target="_blank">here</a>.');
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
            $order->save();

            $result = $this->jsonResultFactory->create();
            $result->setHttpResponseCode(200);
            $result->setData(['success' => true]);

            return $result;

        } catch (\Exception $e) {

            $result = $this->jsonResultFactory->create();
            $result->setHttpResponseCode(400);
            $result->setData(['error_message' => __('Webhook receive error.')]);
            return $result;

        }

    }


    /**
     * Gets the incoming request headers. Some servers are not using
     * Apache and "getallheaders()" will not work so we may need to
     * build our own headers.
     */
    public function get_request_headers()
    {
        if (!function_exists('getallheaders'))
        {
            $headers = array();
            foreach ($_SERVER as $name => $value ) {
                if ('HTTP_' === substr($name, 0, 5)) {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
            return $headers;
        } else {
            return getallheaders();
        }
    }


    /**
     * Validate the webhook request
     */
    private function validate_webhook($request_headers, $payload)
    {
        if (!isset($request_headers['X-WEBHOOK-AUTH'])) {
            return false;
        }

        $sig = $request_headers['X-WEBHOOK-AUTH'];

        $api_secret = $this->scopeConfig->getValue('payment/coinqvest_paymentgateway/api_secret');

        $sig2 = hash('sha256', $api_secret . $payload);

        if ($sig === $sig2) {
            return true;
        }

        return false;
    }


    /**
     * Get order by Coinqvest checkout id
     */
    private function getOrder($checkoutId)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('coinqvest_checkout_id', $checkoutId, 'eq')->create();

        $orderList = $this->orderRepository->getList($searchCriteria)->getItems();

        $order = reset($orderList) ? reset($orderList) : null;

        return $order;
    }

}