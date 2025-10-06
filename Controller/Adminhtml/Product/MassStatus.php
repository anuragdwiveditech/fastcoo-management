<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Fastcoo MassStatus controller — mirrors core behavior but redirects to fastcoo area.
 */
class MassStatus extends Action
{
    /**
     * @var \Magento\Catalog\Model\Indexer\Product\Price\Processor
     */
    protected $productPriceIndexerProcessor;

    /**
     * @var Filter
     */
    protected $filter;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var \Magento\Catalog\Model\Product\Action
     */
    private $productAction;

    /**
     * @var \Magento\Catalog\Helper\Product\Edit\Action\Attribute
     */
    private $attributeHelper;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        LoggerInterface $logger = null
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
        // lazy-get heavy services to avoid DI signature mismatches
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->productPriceIndexerProcessor = $om->get(\Magento\Catalog\Model\Indexer\Product\Price\Processor::class);
        $this->productAction = $om->get(\Magento\Catalog\Model\Product\Action::class);
        $this->attributeHelper = $om->get(\Magento\Catalog\Helper\Product\Edit\Action\Attribute::class);
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Catalog::products')
            || $this->_authorization->isAllowed('Fastcoo_Management::products');
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $productIds = $collection->getAllIds();
        $this->attributeHelper->setProductIds($productIds);

        $requestStoreId = $storeId = $this->getRequest()->getParam('store', null);
        $filterRequest = $this->getRequest()->getParam('filters', null);
        $status = (int)$this->getRequest()->getParam('status');

        if (null === $storeId && null !== $filterRequest) {
            $storeId = (isset($filterRequest['store_id'])) ? (int)$filterRequest['store_id'] : 0;
        }

        try {
            // validate, update attributes and reindex
            if ($status == \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
                if (!$this->_objectManager->create(\Magento\Catalog\Model\Product::class)->isProductsHasSku($productIds)) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Please make sure to define SKU values for all processed products.')
                    );
                }
            }

            $this->productAction->updateAttributes($productIds, ['status' => $status], (int) $storeId);

            $this->messageManager->addSuccessMessage(
                __('A total of %1 record(s) have been updated.', count($productIds))
            );

            // reindex price on changed products
            $this->productPriceIndexerProcessor->reindexList($productIds);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->critical($e);
            } else {
                $this->_objectManager->get(\Psr\Log\LoggerInterface::class)->critical($e);
            }
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while updating the product(s) status.')
            );
        }

        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        // redirect to fastcoo listing (preserve store)
        return $resultRedirect->setPath('fastcoo/product/index', ['store' => $requestStoreId]);
    }
}
