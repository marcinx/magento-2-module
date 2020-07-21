<?php

namespace Coinqvest\PaymentGateway\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session\Proxy;

class Cancel extends Action
{
    private $checkoutSession;

    public function __construct(
        Context $context,
        Proxy $checkoutSession
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
    }

    public function execute()
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if ($order->getId() && ! $order->isCanceled()) {
            $order->registerCancellation('Canceled by Customer')->save();
        }

        $this->checkoutSession->restoreQuote();
        $this->_redirect('checkout/cart');


    }

}