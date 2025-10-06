<?php
namespace Fastcoo\Management\Controller\Adminhtml\OrderCreate;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session\Quote as BackendSessionQuote;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Backend\App\Action;

class Start extends Action
{
    /**
     * @var BackendSessionQuote
     */
    protected $backendSessionQuote;

    /**
     * @var RedirectFactory
     */
    protected $resultRedirectFactory;

    public function __construct(
        Context $context,
        BackendSessionQuote $backendSessionQuote,
        RedirectFactory $resultRedirectFactory
    ) {
        parent::__construct($context);
        $this->backendSessionQuote = $backendSessionQuote;
        $this->resultRedirectFactory = $resultRedirectFactory;
    }

    /**
     * Start order create action
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    { print_r('sddsfds'); die();
        // Clear admin quote/session storage (same as core Start controller)
        try {
            $this->backendSessionQuote->clearStorage();
        } catch (\Throwable $e) {
            // ignore
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();

        // pass customer_id (if present) so Index can handle new order for customer
        $params = [];
        if ($this->getRequest()->getParam('customer_id')) {
            $params['customer_id'] = (int) $this->getRequest()->getParam('customer_id');
        }

        // Redirect to Fastcoo index (which will render core create-order UI)
        return $resultRedirect->setPath('fastcoo/order_create/index', $params);
    }

    /**
     * ACL - reuse sales create permission (matches your button aclResource)
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Sales::create');
    }
}
