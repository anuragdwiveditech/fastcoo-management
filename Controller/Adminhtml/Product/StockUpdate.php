<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;

class StockUpdate extends Action
{
    /**
     * Minimal constructor — only Context to avoid DI ordering issues.
     */
    public function __construct(
        Action\Context $context
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Fastcoo_Management::sync');
    }

    /**
     * Read settings from fastcoo_settings table (settings_id = 1).
     * If settings row does not exist or required fields are missing, throw an exception.
     *
     * @return array
     * @throws \RuntimeException
     */
    protected function getFastcooSettings()
    {
        $om = \Magento\Framework\App\ObjectManager::getInstance();

        /** @var \Magento\Framework\App\ResourceConnection $resource */
        $resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
        if (!$resource) {
            throw new \RuntimeException('ResourceConnection not available.');
        }

        $connection = $resource->getConnection();
        if (!$connection) {
            throw new \RuntimeException('Database connection not available.');
        }

        $settingsTable = $resource->getTableName('fastcoo_settings');
        if (!$settingsTable) {
            throw new \RuntimeException('Settings table name could not be resolved.');
        }

        // fetch single row (use parameter binding)
        try {
            $row = $connection->fetchRow("SELECT * FROM `{$settingsTable}` WHERE settings_id = :id", ['id' => 1]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database query failed while reading Fastcoo settings: ' . $e->getMessage());
        }

        if (!$row || !is_array($row)) {
            throw new \RuntimeException('Fastcoo settings not found. Please configure Fastcoo settings (settings_id = 1).');
        }

        // required keys: status, endpoint_url, customer_id
        $status = isset($row['status']) ? (string)$row['status'] : '';
        $endpoint = isset($row['endpoint_url']) ? trim((string)$row['endpoint_url']) : '';
        $customerId = isset($row['customer_id']) ? trim((string)$row['customer_id']) : '';

        if ($status !== '1') {
            throw new \RuntimeException('Fastcoo integration is disabled in settings.');
        }

        if ($endpoint === '') {
            throw new \RuntimeException('Fastcoo endpoint_url is not configured. Please set endpoint_url in Fastcoo settings.');
        }

        if ($customerId === '') {
            throw new \RuntimeException('Fastcoo customer_id is not configured. Please set customer_id in Fastcoo settings.');
        }

        // return sanitized settings (only keys we need)
        return [
            'status' => $status,
            'endpoint_url' => $endpoint,
            'customer_id' => $customerId,
            'secret_key' => isset($row['secret_key']) ? (string)$row['secret_key'] : '',
            'system_type' => isset($row['system_type']) ? (string)$row['system_type'] : ''
        ];
    }

    public function execute()
    {
        // lazy get services to avoid DI changes
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $resultJsonFactory = $om->get(\Magento\Framework\Controller\Result\JsonFactory::class);
        $logger = null;
        try {
            $logger = $om->get(\Psr\Log\LoggerInterface::class);
        } catch (\Throwable $t) {
            // logger optional — continue without it
        }

        $result = $resultJsonFactory->create();

        // get selected ids
        $ids = $this->getRequest()->getParam('ids', []);
        if (!is_array($ids)) {
            if ($ids === null || $ids === '') {
                $ids = [];
            } else {
                $ids = [$ids];
            }
        }

        if (empty($ids)) {
            return $result->setData(['success' => false, 'message' => 'Please select at least one product.']);
        }

        // *** IMPORTANT: Load and validate settings first. If settings disabled/missing, return immediately. ***
        try {
            $settings = $this->getFastcooSettings();
        } catch (\Throwable $e) {
            if ($logger) {
                $logger->warning('Fastcoo StockUpdate aborted: ' . $e->getMessage());
            }
            return $result->setData([
                'success' => false,
                // clear Hindi-friendly message as requested:
                'message' => 'Fastcoo settings disabled / misconfigured. Please enable Fastcoo settings in configuration.'
            ]);
        }

        // lazy load services needed for stock update
        try {
            $curl = $om->get(\Magento\Framework\HTTP\Client\Curl::class);
            $productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            $stockRegistry = $om->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);
            $stockItemRepository = $om->get(\Magento\CatalogInventory\Api\StockItemRepositoryInterface::class);
        } catch (\Exception $e) {
            if ($logger) { $logger->error('Fastcoo StockUpdate: service init failed: '.$e->getMessage()); }
            return $result->setData(['success' => false, 'message' => 'Failed to initialize services.']);
        }

        // prepare API request
        $endpoint = rtrim($settings['endpoint_url'], '/') . '/Product/ItemDetails';
        $payload = [
            "format" => "json",
            "signMethod" => "md5",
            "param" => ["sku" => ""],
            "customerId" => (string)$settings['customer_id']
        ];

        try {
            // call API
            $curl->addHeader("Content-Type", "application/json");
            // DEV only: disable SSL verification if necessary — remove in production
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);
            $curl->post($endpoint, json_encode($payload, JSON_UNESCAPED_SLASHES));

            $raw = $curl->getBody();
            $json = json_decode($raw, true);

            if (!is_array($json) || empty($json['item_data']) || !is_array($json['item_data'])) {
                if ($logger) { $logger->warning('Fastcoo StockUpdate: invalid API response', ['raw' => substr((string)$raw, 0, 500)]); }
                return $result->setData(['success' => false, 'message' => 'Failed to update quantity (invalid API response).']);
            }

            // build sku->qty map
            $map = [];
            foreach ($json['item_data'] as $item) {
                if (!is_array($item)) continue;
                if (isset($item['sku']) && isset($item['qty'])) {
                    $map[strtolower(trim($item['sku']))] = (int)$item['qty'];
                }
            }

            $updated = 0;
            foreach ($ids as $id) {
                try {
                    $product = $productRepository->getById((int)$id);
                    $sku = strtolower((string)$product->getSku());
                    if ($sku === '') continue;
                    if (!isset($map[$sku])) continue;

                    $qty = (int)$map[$sku];
                    $stockItem = $stockRegistry->getStockItem((int)$id);
                    $stockItem->setQty($qty);
                    $stockItem->setIsInStock($qty > 0 ? 1 : 0);
                    $stockItemRepository->save($stockItem);

                    $updated++;
                } catch (\Exception $e) {
                    if ($logger) { $logger->error('Fastcoo stock update error', ['id' => $id, 'err' => $e->getMessage()]); }
                    continue;
                }
            }

            if ($updated > 0) {
                // Collect updated and missing SKUs
                $updatedSkus = [];
                $missingSkus = [];

                foreach ($ids as $id) {
                    try {
                        $product = $productRepository->getById((int)$id);
                        $sku = strtolower((string)$product->getSku());
                        if ($sku === '') continue;

                        if (isset($map[$sku])) {
                            $qty = (int)$map[$sku];
                            $stockItem = $stockRegistry->getStockItem((int)$id);
                            $stockItem->setQty($qty);
                            $stockItem->setIsInStock($qty > 0 ? 1 : 0);
                            $stockItemRepository->save($stockItem);

                            $updatedSkus[] = (string)$product->getSku();
                        } else {
                            $missingSkus[] = (string)$product->getSku();
                        }

                    } catch (\Exception $e) {
                        if ($logger) { $logger->error('Fastcoo stock update error', ['id'=>$id,'err'=>$e->getMessage()]); }
                        continue;
                    }
                }

                return $result->setData([
                    'success' => count($updatedSkus) > 0,
                    'message' => 'Stock update completed.',
                    'updatedProducts' => $updatedSkus,
                    'missingProducts' => $missingSkus
                ]);
            } else {
                return $result->setData([
                    'success' => false,
                    'message' => 'No products were updated (SKUs not found in Fastcoo response).'
                ]);
            }
        } catch (\Exception $e) {
            if ($logger) { $logger->critical('Fastcoo StockUpdate exception: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]); }
            return $result->setData(['success' => false, 'message' => 'Failed to update quantity due to exception.']);
        }
    }
}
