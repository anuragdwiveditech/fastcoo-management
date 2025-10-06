<?php
namespace Fastcoo\Management\Controller\Adminhtml\Order\Create;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Start extends Action
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        // Get admin quote session
        $session = $this->_objectManager->get(\Magento\Backend\Model\Session\Quote::class);
        $session->clearStorage();

        $params = [];
        if ($this->getRequest()->getParam('customer_id')) {
            $params['customer_id'] = $this->getRequest()->getParam('customer_id');
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('fastcoo/order_create/index', $params);
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Sales::create');
    }
}
