<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;

class Send extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->productRepository = $productRepository;
    }

    protected function _isAllowed()
    {
        // for development/testing return true; change to ACL resource for production
        return true;
        // return $this->_authorization->isAllowed('Fastcoo_Management::product_send');
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $selected = $this->getRequest()->getParam('selected', []);
        if (!is_array($selected)) {
            $selected = array_filter(explode(',', (string)$selected));
        }

        if (empty($selected)) {
            return $result->setData(['success' => false, 'message' => __('No products selected.')]);
        }

        $processed = [];
        $errors = [];

        foreach ($selected as $id) {
            try {
                $id = (int)$id;
                if (!$id) {
                    continue;
                }
                $product = $this->productRepository->getById($id);
                // TODO: implement your send logic here (API call, flag, queue, etc.)
                $processed[] = $product->getSku();
            } catch (\Exception $e) {
                $errors[] = __('ID %1: %2', $id, $e->getMessage());
            }
        }

        if ($errors) {
            return $result->setData(['success' => false, 'message' => __('Some products failed: %1', implode('; ', $errors))]);
        }

        return $result->setData(['success' => true, 'message' => __('%1 product(s) processed: %2', count($processed), implode(', ', $processed))]);
    }
}
