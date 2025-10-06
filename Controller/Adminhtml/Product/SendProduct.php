<?php
namespace Fastcoo\Management\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * Admin controller to send selected products to Fastcoo endpoint
 */
class SendProduct extends Action
{
    /** @var Curl */
    protected $curl;

    /** @var StoreManagerInterface */
    protected $storeManager;

    /** @var RedirectFactory */
    protected $resultRedirectFactory;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    /** @var JsonFactory */
    protected $resultJsonFactory;

    /** @var ResourceConnection */
    protected $resource;

    public function __construct(
        Context $context,
        Curl $curl,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        JsonFactory $resultJsonFactory = null,
        ResourceConnection $resource = null
    ) {
        parent::__construct($context);
        $this->curl = $curl;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        if ($resultJsonFactory === null) {
            $resultJsonFactory = \Magento\Framework\App\ObjectManager::getInstance()->get(JsonFactory::class);
        }
        $this->resultJsonFactory = $resultJsonFactory;

        // ResourceConnection: prefer DI, fallback to ObjectManager for backward compatibility
        if ($resource === null) {
            $resource = \Magento\Framework\App\ObjectManager::getInstance()->get(ResourceConnection::class);
        }
        $this->resource = $resource;

        $this->resultRedirectFactory = $context->getResultRedirectFactory();
    }

    /**
     * Authorization ACL
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Fastcoo_Management::product_list');
    }

    /**
     * Execute
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultJsonFactory->create();

        // Get posted IDs (front sends ids[] via AJAX or form)
        $ids = $this->getRequest()->getParam('ids', []);

        if (!is_array($ids)) {
            // sometimes single value: convert to array
            if ($ids === null || $ids === '') {
                return $resultJson->setData([
                    'success' => false,
                    'message' => 'No products selected.',
                ]);
            }
            $ids = [$ids];
        }

        // Load module settings
        $settings = $this->getFastcooSettings();

        $status = isset($settings['status']) ? (string)$settings['status'] : '';

        if ($status !== '1') {
            return $resultJson->setData([
                'success' => false,
                'message' => 'Fastcoo integration is disabled in settings.'
            ]);
        }

        $endpointBase = rtrim($settings['endpoint_url'] ?? '', '/');
        if ($endpointBase === '') {
            return $resultJson->setData([
                'success' => false,
                'message' => 'Fastcoo endpoint is not configured.'
            ]);
        }

        $customerId = $settings['customer_id'] ?? '';
        if (empty($customerId)) {
            return $resultJson->setData([
                'success' => false,
                'message' => 'Please check Fastcoo setting — configure customer id.'
            ]);
        }

        $fastcooEndpoint = $endpointBase . '/Product/createProduct';

        $sent = 0;
        $results = []; // per-sku details
        $failed = [];

        foreach ($ids as $productId) {
            $productIdInt = (int)$productId;
            // Defensive: ignore/mark invalid ids (non-positive)
            if ($productIdInt <= 0) {
                $msg = "Invalid product id provided: '{$productId}'";
                $results[] = [
                    'product_id' => $productId,
                    'sku' => null,
                    'status' => 'error',
                    'message' => $msg
                ];
                $failed[] = $msg;
                continue;
            }

            try {
                $product = $this->productRepository->getById($productIdInt);
            } catch (\Exception $e) {
                $msg = "Product with ID {$productIdInt} was not found in store.";
                $results[] = [
                    'product_id' => $productIdInt,
                    'sku' => null,
                    'status' => 'error',
                    'message' => $msg
                ];
                $failed[] = $msg;
                continue;
            }

            // image url building
            $image = $product->getImage();
            $imageUrl = '';
            if ($image && $image !== 'no_selection') {
                $mediaBase = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
                $imgPath = ltrim($image, '/');
                $imageUrl = rtrim($mediaBase, '/') . '/fastcoo/product/' . $imgPath;
            }

            // description fallback
            $desc = $product->getMetaDescription();
            if (!$desc) {
                $desc = $product->getDescription() ? strip_tags($product->getDescription()) : '';
            }

            $length = (string) (float) $product->getData('length') ?: '0';
            $width  = (string) (float) $product->getData('width')  ?: '0';
            $height = (string) (float) $product->getData('height') ?: '0';
            $weight = (string) (float) $product->getWeight() ?: '0';

            $sku = trim((string)$product->getSku());
            if ($sku === '') {
                $msg = "Product ID {$productIdInt} has no SKU set. Please assign an SKU before sending.";
                $results[] = [
                    'product_id' => $productIdInt,
                    'sku' => null,
                    'status' => 'error',
                    'message' => $msg
                ];
                $failed[] = $msg;
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9]+$/', $sku)) {
                $suggested = preg_replace('/[^A-Za-z0-9]/', '', $sku);
                $msg = "Product ID {$productIdInt} has invalid SKU '{$sku}'. Allowed characters: letters and numbers only.";
                if ($suggested !== '') $msg .= " Suggested SKU: '{$suggested}'";
                $results[] = [
                    'product_id' => $productIdInt,
                    'sku' => $sku,
                    'status' => 'error',
                    'message' => $msg
                ];
                $failed[] = $msg;
                continue;
            }

            // build payload
            $payload = [
                'sign'       => 'AAA821B373076B320B6150EC81F62DD2',
                'format'     => 'json',
                'signMethod' => 'md5',
                'param'      => [
                    'sku'          => (string)$sku,
                    'sku_name'     => (string)$product->getName(),
                    'description'  => (string)$desc,
                    'length'       => $length,
                    'height'       => $height,
                    'width'        => $width,
                    'weight'       => $weight,
                    'product_path' => $imageUrl ?: ''
                ],
                'method'     => 'createProduct',
                'customerId' => $customerId
            ];

            try {
                $this->curl->addHeader('Content-Type', 'application/json');
                $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
                $this->curl->setOption(CURLOPT_TIMEOUT, 30);

                $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
                $this->curl->post($fastcooEndpoint, $jsonPayload);

                $response = $this->curl->getBody();
                $httpCode = $this->curl->getStatus();

                if ($response === false || $httpCode < 200 || $httpCode >= 300) {
                    $err = "SKU {$sku} failed (HTTP {$httpCode}).";
                    $results[] = [
                        'product_id' => $productIdInt,
                        'sku' => $sku,
                        'status' => 'error',
                        'message' => $err
                    ];
                    $failed[] = $err;
                    continue;
                }

                $json = json_decode($response, true);

                // collect api message (trim for safety)
                $apiMessage = '';
                if (is_array($json)) {
                    if (!empty($json['message'])) $apiMessage = (string)$json['message'];
                    elseif (!empty($json['msg'])) $apiMessage = (string)$json['msg'];
                    elseif (!empty($json['error'])) $apiMessage = is_string($json['error']) ? (string)$json['error'] : json_encode($json['error']);
                    else $apiMessage = substr(json_encode($json), 0, 300);
                } else {
                    $apiMessage = substr((string)$response, 0, 300);
                }

                // determine success/failure similar to prior logic
                $isFailure = false;
                if ($httpCode < 200 || $httpCode >= 300) $isFailure = true;
                if (is_array($json) && isset($json['status'])) {
                    $statusVal = (int)$json['status'];
                    if ($statusVal !== 200 && $statusVal !== 0) $isFailure = true;
                }
                if (is_array($json) && ((isset($json['success']) && !$json['success']) || (!empty($json['error']) && $json['error'] !== false && $json['error'] !== 0))) {
                    $isFailure = true;
                }

                if ($isFailure) {
                    $msg = "SKU {$sku} failed: " . $apiMessage;
                    $results[] = [
                        'product_id' => $productIdInt,
                        'sku' => $sku,
                        'status' => 'error',
                        'message' => $msg,
                        'raw' => $json
                    ];
                    $failed[] = $msg;
                    continue;
                }

                // success
                $sent++;
                $results[] = [
                    'product_id' => $productIdInt,
                    'sku' => $sku,
                    'status' => 'success',
                    'message' => (string)$apiMessage,
                    'raw' => $json
                ];

            } catch (\Exception $e) {
                $msg = "SKU {$sku} exception: " . $e->getMessage();
                $results[] = [
                    'product_id' => $productIdInt,
                    'sku' => $sku,
                    'status' => 'error',
                    'message' => $msg
                ];
                $failed[] = $msg;
                continue;
            }
        } // foreach ids

        // return only structured results (no confusing summary lines)
        $responseData = [
            'success' => ($sent > 0),
            'sent' => $sent,
            'failed_count' => count($failed),
            'results' => $results
        ];

        return $resultJson->setData($responseData);
    }

    /**
     * Get module settings from fastcoo_settings table (settings_id = 1)
     *
     * @return array
     */
    protected function getFastcooSettings()
    {
        $defaults = [
            'status' => '0',
            'endpoint_url' => '',
            'customer_id' => '',
            'secret_key' => '',
            'system_type' => ''
        ];

        try {
            $connection = $this->resource->getConnection();
            $settingsTable = $this->resource->getTableName('fastcoo_settings');

            // fetchRow returns false if no rows — use safe fallback
            $row = $connection->fetchRow("SELECT * FROM `{$settingsTable}` WHERE settings_id = :id", ['id' => 1]);

            if ($row && is_array($row)) {
                // map only expected keys (to avoid unexpected columns)
                $result = $defaults;
                if (isset($row['status'])) $result['status'] = (string)$row['status'];
                if (isset($row['endpoint_url'])) $result['endpoint_url'] = (string)$row['endpoint_url'];
                if (isset($row['customer_id'])) $result['customer_id'] = (string)$row['customer_id'];
                if (isset($row['secret_key'])) $result['secret_key'] = (string)$row['secret_key'];
                if (isset($row['system_type'])) $result['system_type'] = (string)$row['system_type'];
                return $result;
            }

            return $defaults;
        } catch (\Throwable $e) {
            // If DB error occurs, don't break execution — log if logger available
            try {
                $logger = \Magento\Framework\App\ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
                $logger->warning('Fastcoo: could not load settings from DB: ' . $e->getMessage());
            } catch (\Throwable $inner) {
                // ignore
            }
            return $defaults;
        }
    }
}
