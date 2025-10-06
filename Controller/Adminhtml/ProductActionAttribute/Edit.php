<?php
namespace Fastcoo\Management\Controller\Adminhtml\ProductActionAttribute;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;

/**
 * Proxy to core product_action_attribute/edit
 */
class Edit extends Action
{
    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(Context $context, LoggerInterface $logger = null)
    {
        parent::__construct($context);
        $this->logger = $logger;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Catalog::products')
            || $this->_authorization->isAllowed('Fastcoo_Management::products');
    }

    public function execute()
    {
        $om = ObjectManager::getInstance();
        try {
            /** @var \Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute\Edit $coreController */
            $coreController = $om->get(\Magento\Catalog\Controller\Adminhtml\Product\Action\Attribute\Edit::class);

            // Execute core controller and return its result (renders the same form)
            $result = $coreController->execute();

            // If core returned a redirect, adjust URL so browser shows fastcoo frontName (optional)
            if (method_exists($result, 'getUrl')) {
                $url = $result->getUrl();
                if ($url && strpos($url, '/catalog/') !== false) {
                    $result->setUrl(preg_replace('#/catalog/#', '/fastcoo/', $url, 1));
                }
            }

            return $result;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->critical($e);
            } else {
                error_log($e->getMessage());
            }
            $this->messageManager->addErrorMessage(__('Unable to open Update Attributes.'));
            return $this->resultRedirectFactory->create()->setPath('fastcoo/product/index');
        }
    }
}
