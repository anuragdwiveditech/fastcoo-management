<?php
namespace Fastcoo\Management\Controller\Adminhtml\OrderCreate;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\Sales\Controller\Adminhtml\Order\Create as CoreOrderCreate;

class Index extends CoreOrderCreate implements HttpGetActionInterface
{
    /**
     * Index page
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    { print_r('glow1'); die();
        // reuse core behaviour
        $this->_initSession();

        // Clear existing order in session when creating a new order for a customer
        if ($this->getRequest()->getParam('customer_id')) {
            $this->_getSession()->setOrderId(null);
        }

        $this->_getOrderCreateModel()->initRuleData();

        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();

        // Optionally set same active menu/title as core so UI looks identical
        $resultPage->setActiveMenu('Magento_Sales::sales_order');
        $resultPage->getConfig()->getTitle()->prepend(__('Orders'));
        $resultPage->getConfig()->getTitle()->prepend(__('New Order'));

        return $resultPage;
    }

    /**
     * ACL - reuse sales create permission
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Sales::create');
    }
}
