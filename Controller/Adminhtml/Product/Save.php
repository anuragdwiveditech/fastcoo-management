<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;

/**
 * Fastcoo Save — force redirect to fastcoo edit page after save (no index fallback).
 */
class Save extends Action
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
        $objectManager = ObjectManager::getInstance();

        // productRepository for SKU fallback
        /** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

        // capture POST product data early
        $postData = $this->getRequest()->getPostValue();
        $postProduct = isset($postData['product']) && is_array($postData['product']) ? $postData['product'] : [];
        $postSku = $postProduct['sku'] ?? null;
        $postEntityId = $postProduct['entity_id'] ?? null;

        // 1) Run core save to perform standard save logic (we'll ignore its redirect)
        try {
            /** @var \Magento\Catalog\Controller\Adminhtml\Product\Save $coreSave */
            $coreSave = $objectManager->get(\Magento\Catalog\Controller\Adminhtml\Product\Save::class);
            $coreResult = $coreSave->execute();
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Fastcoo Save proxy error (core save failed): ' . $e->getMessage());
            } else {
                error_log('Fastcoo Save proxy error (core save failed): ' . $e->getMessage());
            }
            $this->messageManager->addErrorMessage(__('An error occurred while saving the product.'));
            // send back to referer (do not send to index)
            $referer = $this->getRequest()->getServer('HTTP_REFERER') ?: $this->_url->getUrl('fastcoo/product/index');
            return $this->resultRedirectFactory->create()->setUrl($referer);
        }

        // 2) Determine product ID (multiple strategies)
        $id = null;
        $request = $this->getRequest();

        // prefer explicit request param
        $id = $request->getParam('id') ?: null;

        // fallback: POST entity_id (create/edit may set it)
        if (!$id && !empty($postEntityId)) {
            $id = (int)$postEntityId;
        }

        // fallback: try to parse id from coreResult's URL (if it's a redirect)
        if (!$id && isset($coreResult) && is_object($coreResult)) {
            try {
                $coreUrl = method_exists($coreResult, 'getUrl') ? $coreResult->getUrl() : null;
                if ($coreUrl) {
                    if (preg_match('#/edit/id/(\d+)#', $coreUrl, $m)) {
                        $id = (int)$m[1];
                    } elseif (preg_match('#/id/(\d+)#', $coreUrl, $m2)) {
                        $id = (int)$m2[1];
                    }
                }
            } catch (\Throwable $e) {
                // ignore parsing errors
            }
        }

        // fallback: if SKU provided, load by SKU
        if (!$id && $postSku) {
            try {
                $prod = $productRepository->get($postSku);
                $id = (int)$prod->getId();
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->debug('Fastcoo Save: SKU lookup failed: ' . $e->getMessage());
                }
            }
        }

        // final attempt: collection lookup by SKU (best-effort)
        if (!$id && $postSku) {
            try {
                $collection = $objectManager->create(\Magento\Catalog\Model\ResourceModel\Product\Collection::class)
                    ->addAttributeToFilter('sku', $postSku)
                    ->setPageSize(1);
                $found = $collection->getFirstItem();
                if ($found && $found->getId()) {
                    $id = (int)$found->getId();
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // 3) If we have ID — ALWAYS redirect to fastcoo edit (preserve params)
        if ($id) {
            $params = ['id' => $id];
            $store = $request->getParam('store');
            $set = $request->getParam('set');
            $type = $request->getParam('type');
            $back = $request->getParam('back');

            if ($store !== null) { $params['store'] = $store; }
            if ($set !== null)   { $params['set'] = $set; }
            if ($type !== null)  { $params['type'] = $type; }
            if ($back !== null)  { $params['back'] = $back; }

            $redirect = $this->resultRedirectFactory->create();
            $redirect->setPath('fastcoo/product/edit', $params);
            return $redirect;
        }

        // 4) If ID could not be determined (very rare) — show error and return to referer (never index)
        $this->messageManager->addErrorMessage(__('Could not determine product ID after save. Please check product list.'));

        $referer = $this->getRequest()->getServer('HTTP_REFERER') ?: $this->_url->getUrl('adminhtml/dashboard/index');
        return $this->resultRedirectFactory->create()->setUrl($referer);
    }
}
