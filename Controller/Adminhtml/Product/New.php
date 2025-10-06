<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Controller\Adminhtml\Product as AbstractProduct;
use Magento\Framework\View\Result\PageFactory;

/**
 * Fastcoo product new action — extends core abstract so Product\Builder is used.
 */
class NewAction extends AbstractProduct
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * Constructor.
     *
     * Note: productBuilder is optional here for backward compatibility with stale generated interceptors.
     *
     * @param Context $context
     * @param \Magento\Catalog\Controller\Adminhtml\Product\Builder|null $productBuilder
     * @param PageFactory|null $resultPageFactory
     */
    public function __construct(
        Context $context,
        \Magento\Catalog\Controller\Adminhtml\Product\Builder $productBuilder = null,
        PageFactory $resultPageFactory = null
    ) {
        $om = \Magento\Framework\App\ObjectManager::getInstance();

        $productBuilder = $productBuilder ?: $om->get(\Magento\Catalog\Controller\Adminhtml\Product\Builder::class);
        $this->resultPageFactory = $resultPageFactory ?: $om->get(PageFactory::class);

        parent::__construct($context, $productBuilder);
    }

    /**
     * Execute: build product (registers it) and render core product-new layout under fastcoo URL.
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        // Product\Builder reads request params ('set', 'type') and registers product
        $this->productBuilder->build();

        /** @var \Magento\Framework\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->addHandle('catalog_product_new'); // loads core product create UI
        $resultPage->getConfig()->getTitle()->prepend(__('Add Product (Fastcoo)'));

        return $resultPage;
    }
}
