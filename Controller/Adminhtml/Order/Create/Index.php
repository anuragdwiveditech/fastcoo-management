<?php
namespace Fastcoo\Management\Controller\Adminhtml\Order\Create;

use Magento\Sales\Controller\Adminhtml\Order\Create as CoreCreate;
use Magento\Framework\App\Action\HttpGetActionInterface;

class Index extends CoreCreate implements HttpGetActionInterface
{
    public function execute()
    {
        $this->_initSession();

        if ($this->getRequest()->getParam('customer_id')) {
            $this->_getSession()->setOrderId(null);
        }

        $this->_getOrderCreateModel()->initRuleData();

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Magento_Sales::sales_order');
        $resultPage->getConfig()->getTitle()->prepend(__('Orders'));
        $resultPage->getConfig()->getTitle()->prepend(__('New Order'));

        return $resultPage;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Sales::create');
    }
}
