<?php

namespace Fastcoo\Management\Controller\Adminhtml\Order;

class Index extends \Magento\Backend\App\Action
{
    protected $resultPageFactory;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Fastcoo_Management::order_list');
    }

    public function execute()
    { 
        $page = $this->resultPageFactory->create();
        //$page->setActiveMenu('Magento_Sales::sales'); 
          $page->setActiveMenu('Fastcoo_Management::fastcoo');
        $page->getConfig()->getTitle()->prepend(__('Fastcoo Order List'));
        return $page;
    }
}

?>
