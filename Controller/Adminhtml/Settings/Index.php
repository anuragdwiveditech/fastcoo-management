<?php
namespace Fastcoo\Management\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'Fastcoo_Management::settings';

    /** @var PageFactory */
    protected $resultPageFactory;

    public function __construct(Action\Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {   
        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Fastcoo - Settings'));
          $page->setActiveMenu('Fastcoo_Management::fastcoo');
        return $page;
    }
}
