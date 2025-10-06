<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action\Context;
use Magento\Catalog\Controller\Adminhtml\Product as AbstractProduct;
use Magento\Catalog\Controller\Adminhtml\Product\Builder as ProductBuilder;
use Magento\Framework\View\Result\PageFactory;

/**
 * Fastcoo product new action — reuses core Product\Builder and core layout handle.
 */
class NewAction extends AbstractProduct
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var ProductBuilder
     */
    protected $productBuilder;

    /**
     * Constructor with optional DI fallback (avoids stale interceptor issues)
     *
     * @param Context $context
     * @param ProductBuilder|null $productBuilder
     * @param PageFactory|null $resultPageFactory
     */
    public function __construct(
        Context $context,
        ProductBuilder $productBuilder = null,
        PageFactory $resultPageFactory = null
    ) {
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->productBuilder = $productBuilder ?: $om->get(ProductBuilder::class);
        $this->resultPageFactory = $resultPageFactory ?: $om->get(PageFactory::class);

        // pass the builder to parent as expected
        parent::__construct($context, $this->productBuilder);
    }

    /**
     * Execute: call builder with request object and render core product-new layout.
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        // IMPORTANT: pass the RequestInterface object to build()
        $this->productBuilder->build($this->getRequest());

        // render core product-new handle under fastcoo URL
        $resultPage = $this->resultPageFactory->create();
        $resultPage->addHandle('fastcoo_product_new');
        $resultPage->getConfig()->getTitle()->prepend(__('Add Product (Fastcoo)'));

        return $resultPage;
    }
}
