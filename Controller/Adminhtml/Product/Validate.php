<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;

class Validate extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        // implement custom validation if needed; else proxy to core validator if you want.
        $data = ['error' => false, 'messages' => []];
        return $result->setData($data);
    }
}
