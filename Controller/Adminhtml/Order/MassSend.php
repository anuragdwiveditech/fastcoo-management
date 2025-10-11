<?php
namespace Fastcoo\Management\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

class MassSend extends Action
{
    const ADMIN_RESOURCE = 'Magento_Sales::actions';

    private $orderRepository;
    private $curl;
    private $logger;
    private $scopeConfig;
    private $resourceConnection;

    public function __construct(Action\Context $context)
    {
        parent::__construct($context);

        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->orderRepository = $om->get(OrderRepositoryInterface::class);
        $this->curl = $om->get(Curl::class);
        $this->logger = $om->get(LoggerInterface::class);
        $this->scopeConfig = $om->get(ScopeConfigInterface::class);
        $this->resourceConnection = $om->get(ResourceConnection::class);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $isAjax = $request->isXmlHttpRequest();

        // Raw selected param can be many forms: array, comma-string, single, or unexpected values like 'on'
        $selectedRaw = $request->getParam('selected', []);
        $selectedCandidates = [];

        if (is_array($selectedRaw)) {
            $selectedCandidates = $selectedRaw;
        } elseif ($selectedRaw === null || $selectedRaw === '' || $selectedRaw === 'false') {
            $selectedCandidates = [];
        } elseif (is_string($selectedRaw)) {
            // If comma-separated, split; else single value
            if (strpos($selectedRaw, ',') !== false) {
                $selectedCandidates = array_map('trim', explode(',', $selectedRaw));
            } else {
                $selectedCandidates = [trim($selectedRaw)];
            }
        } else {
            // fallback
            $selectedCandidates = (array)$selectedRaw;
        }

        // FILTER: keep only valid identifiers: "123" or "123|something"
        $validSelected = [];
        foreach ($selectedCandidates as $val) {
            if (!is_scalar($val)) continue;
            $v = trim((string)$val);
            if ($v === '' || strtolower($v) === 'on' || strtolower($v) === 'false') continue;

            // Accept patterns: digits OR digits|anything
            if (preg_match('/^\d+(\|.*)?$/', $v)) {
                $validSelected[] = $v;
            }
        }

        // If nothing valid selected — respond with friendly message
        if (empty($validSelected)) {
            $msg = __('No orders selected.');
            if ($isAjax) {
                /** @var \Magento\Framework\Controller\Result\Json $jsonResult */
                $jsonResult = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
                return $jsonResult->setData(['success' => false, 'message' => (string)$msg]);
            } else {
                $this->messageManager->addErrorMessage($msg);
                $resultRedirect = $this->resultRedirectFactory->create();
                $resultRedirect->setPath('fastcoo/order/index');
                return $resultRedirect;
            }
        }

        // proceed with validated selection
        $selected = $validSelected;
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('fastcoo/order/index');

        $messages = [];
        $messagesError = [];

        // Fetch settings from DB
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $connection->getTableName('fastcoo_settings');
            $settingsData = $connection->fetchRow("SELECT * FROM {$tableName} WHERE settings_id = 1");
        } catch (\Throwable $e) {
            $this->logger->error('Fastcoo MassSend - DB error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Settings not found or DB error.'));
            return $resultRedirect;
        }

        if (!$settingsData) {
            $this->messageManager->addErrorMessage(__('Settings not found in database.'));
            return $resultRedirect;
        }

        // === NEW: check status field in fastcoo_settings ===
        $status = isset($settingsData['status']) ? (string)$settingsData['status'] : '';
        if ($status !== '1') {
            $msg = __('Fastcoo integration is disabled. Please enable Fastcoo settings.');
            if ($isAjax) {
                $jsonResult = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
                return $jsonResult->setData(['success' => false, 'message' => (string)$msg]);
            } else {
                $this->messageManager->addErrorMessage($msg);
                return $resultRedirect;
            }
        }
        // === END status check ===

        $settings = [
            'system_type'  => $settingsData['system_type'] ?? 'fm',
            'endpoint_url' => $settingsData['endpoint_url'] ?? '',
            'sign'         => $settingsData['sign'] ?? '',
            'secret_key'   => $settingsData['secret_key'] ?? '',
            'customer_id'  => $settingsData['customer_id'] ?? ''
        ];

        $systemType = strtolower($settings['system_type']);
        $fm_url = !empty($settings['endpoint_url']) ? rtrim($settings['endpoint_url'], '/') . '/API/createOrder' : '';
        $lm_url = !empty($settings['endpoint_url']) ? rtrim($settings['endpoint_url'], '/') . '/API_v2/CreateOrder' : '';

        foreach ($selected as $orderItem) {
            // support both "123" and "123|AWB" formats
            $parts = explode('|', $orderItem, 2);
            $orderIdRaw = isset($parts[0]) ? $parts[0] : '';
            $awb_no = isset($parts[1]) ? $parts[1] : '';

            $orderId = (int)$orderIdRaw;
            if (!$orderId) {
                $messagesError[] = "Invalid order identifier: {$orderItem}";
                continue;
            }

            try {
                $order = $this->orderRepository->get($orderId);
            } catch (\Exception $e) {
                $messagesError[] = "Order #{$orderId} not found";
                continue;
            }

            // ----- DUPLICATE CHECK: अगर fastcoo_orders में पहले से है तो SKIP कर दो -----
            try {
                $conn = $this->resourceConnection->getConnection();
                $tableOrders = $this->resourceConnection->getTableName('fastcoo_orders');
                $sql = "SELECT 1 FROM {$tableOrders} WHERE order_id = :order_id LIMIT 1";
                $exists = $conn->fetchOne($sql, ['order_id' => $orderId]);

                if ($exists) {
                    // पहले से बना हुआ — skip this order
                    $messagesError[] = "Order #{$orderId} skipped - already created previously (fastcoo_orders).";
                    continue;
                }
            } catch (\Throwable $e) {
                // DB check failed — log and skip to be safe
                $this->logger->error("Fastcoo duplicate-check failed for order {$orderId}: " . $e->getMessage());
                $messagesError[] = "Order #{$orderId} skipped - duplicate-check DB error.";
                continue;
            }
            // ----- END DUPLICATE CHECK -----

            // Build product details
            $items = $order->getItems();
            $totalPieces = 0;
            $totalWeight = 0;
            $skudetails = [];

            foreach ($items as $item) {
                if ($item->getParentItemId()) continue;
                $qty = (int)$item->getQtyOrdered();
                $weightPer = (float)$item->getWeight() ?: 1.0;
                $totalPieces += $qty;
                $totalWeight += $qty * $weightPer;
                $skudetails[] = [
                    'sku' => $item->getSku(),
                    'description' => $item->getName(),
                    'cod' => stripos((string)$order->getPayment()->getMethod(), 'cod') !== false ? (float)$item->getRowTotalInclTax() : 0,
                    'piece' => $qty,
                    'weight' => $qty * $weightPer
                ];
            }

            $bookingMode = stripos((string)$order->getPayment()->getMethod(), 'cod') !== false ? 'COD' : 'CC';
            $origin = $this->scopeConfig->getValue('general/store_information/city') ?: '';
            $destination = $order->getShippingAddress() ? $order->getShippingAddress()->getCity() : '';
            $reference_id = $orderId;

             // --- Build receiver address safely ---
            $receiverAddress = '';
            $shipping = $order->getShippingAddress();
            if ($shipping) {
                // street can be array or string
                $street = $shipping->getStreet();
                if (is_array($street)) {
                    $streetStr = implode(', ', array_filter(array_map('trim', $street)));
                } else {
                    $streetStr = trim((string)$street);
                }
                $city = trim((string)$shipping->getCity());
                $postcode = trim((string)$shipping->getPostcode());
                $region = trim((string)$shipping->getRegion());
                $country = trim((string)$shipping->getCountryId());

                // assemble parts and filter empty ones
                $addressParts = array_filter([$streetStr, $city, $region, $postcode, $country], function($v){
                    return $v !== '' && $v !== null;
                });
                $receiverAddress = implode(', ', $addressParts);
            }


            $payload = [
                'sign' => 'AAA821B373076B320B6150EC81F62DD2',
                'format' => 'json',
                'signMethod' => 'md5',
                'param' => [
                    'sender_name' => $this->scopeConfig->getValue('general/store_information/name'),
                    'receiver_name' => $order->getShippingAddress() ? $order->getShippingAddress()->getFirstname() . ' ' . $order->getShippingAddress()->getLastname() : '',
                    'receiver_email' => $order->getCustomerEmail(),
                    'receiver_phone' => $order->getShippingAddress() ? $order->getShippingAddress()->getTelephone() : '',
                    'receiver_address' => $receiverAddress,
                    'origin' => $origin,
                    'destination' => $destination,
                    'BookingMode' => $bookingMode,
                    'pieces' => (string)$totalPieces,
                    'service' => 'Express',
                    'weight' => (string)$totalWeight,
                    'skudetails' => $skudetails,
                    'reference_id' => $reference_id,
                    'productType' => 'parcel',
                ],
                'method' => 'createOrder',
                'customerId' => $settings['customer_id'],
                 'secret_key' => $settings['secret_key']
            ];

            $url = $systemType === 'lm' ? $lm_url : $fm_url;

            try {
                $this->curl->setOption(CURLOPT_TIMEOUT, 30);
                $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
                $this->curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
                $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);
                $this->curl->addHeader('Content-Type', 'application/json');
                $this->curl->post($url, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                $response = $this->curl->getBody();

                $result = json_decode($response, true);

                $success = isset($result['status']) && ($result['status'] == 200 || $result['status'] === true);

                if ($success) {
                    $awb_no = $result['awb_no'] ?? $result['awb'] ?? '';
                    $messages[] = "Order #{$orderId} create successfully. AWB: " . ($awb_no ?: 'N/A');

                    // Insert into fastcoo_orders table (safe try/catch)
                    $tableOrders = $this->resourceConnection->getTableName('fastcoo_orders');
                    try {
                        $this->resourceConnection->getConnection()->insert(
                            $tableOrders,
                            [
                                'order_id' => $orderId,
                                'awb_no' => $awb_no
                            ]
                        );
                    } catch (\Throwable $e) {
                        $this->logger->warning("Could not insert fastcoo_orders for order {$orderId}: " . $e->getMessage());
                    }

                } else {
                    $errMsg = $result['message'] ?? $result['error'] ?? json_encode($result);
                    $messagesError[] = "Order #{$orderId} failed - {$errMsg}";
                }
            } catch (\Exception $e) {
                $messagesError[] = "Order #{$orderId} exception: " . $e->getMessage();
                $this->logger->error($e->getMessage());
            }
        }

        if (!empty($messagesError)) $this->messageManager->addErrorMessage(implode('<br>', $messagesError));
        if (!empty($messages)) $this->messageManager->addSuccessMessage(implode('<br>', $messages));

        return $resultRedirect;
    }
}
