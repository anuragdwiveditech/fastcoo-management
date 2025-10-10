<?php 
namespace Fastcoo\Management\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\Redirect;

class Sync extends Action
{
    const ADMIN_RESOURCE = 'Magento_Sales::actions';

    public function __construct(Action\Context $context)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('fastcoo/order/index');

        $request = $this->getRequest();

        // Normalize selected param
        $selectedRaw = $request->getParam('selected', []);
        $selected = [];

        if (is_array($selectedRaw)) {
            $selected = $selectedRaw;
        } elseif ($selectedRaw === null || $selectedRaw === '' || $selectedRaw === 'false') {
            $selected = [];
        } elseif (is_string($selectedRaw)) {
            if (strpos($selectedRaw, ',') !== false) {
                $selected = array_filter(array_map('trim', explode(',', $selectedRaw)));
            } else {
                $selected = [trim($selectedRaw)];
            }
        } else {
            $selected = (array)$selectedRaw;
        }

        // Check toolbar ids param too
        if (empty($selected) && $request->getParam('ids')) {
            $idsParam = $request->getParam('ids');
            if (is_string($idsParam)) {
                $selected = array_filter(array_map('trim', explode(',', $idsParam)));
            } elseif (is_array($idsParam)) {
                $selected = $idsParam;
            }
        }

        // filter invalids
        $filtered = [];
        foreach ($selected as $v) {
            if (!is_scalar($v)) continue;
            $s = trim((string)$v);
            if ($s === '' || strtolower($s) === 'on' || strtolower($s) === 'false') continue;
            $filtered[] = $s;
        }
        $selected = array_values(array_unique($filtered));

        if (empty($selected)) {
            $this->messageManager->addErrorMessage(__('No orders selected!'));
            return $resultRedirect;
        }

        $om = \Magento\Framework\App\ObjectManager::getInstance();
        /** @var \Magento\Framework\App\ResourceConnection $resource */
        $resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
        $connection = $resource->getConnection();

        // read settings
        $settingsTable = $resource->getTableName('fastcoo_settings');
        try {
            $settingsData = $connection->fetchRow("SELECT * FROM {$settingsTable} WHERE settings_id = 1");
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Fastcoo settings not found in DB.'));
            return $resultRedirect;
        }

        if (!$settingsData) {
            $this->messageManager->addErrorMessage(__('Fastcoo settings not found in DB.'));
            return $resultRedirect;
        }

        $statusFlag = isset($settingsData['status']) ? (string)$settingsData['status'] : '';
        if ($statusFlag !== '1') {
            $this->messageManager->addErrorMessage(__('Fastcoo integration is disabled in settings. Please enable it.'));
            return $resultRedirect;
        }

        $systemType = isset($settingsData['system_type']) ? strtolower($settingsData['system_type']) : 'fm';
        $endpoint = isset($settingsData['endpoint_url']) ? rtrim($settingsData['endpoint_url'], '/') : '';

        $lm_url = $endpoint ? $endpoint . '/Order/Track/GetDetailsLM' : '';
        $fm_url = $endpoint ? $endpoint . '/Order/Track/GetDetailsFM' : '';

        /** @var \Magento\Sales\Api\OrderRepositoryInterface $orderRepository */
        $orderRepository = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
        /** @var \Magento\Framework\HTTP\Client\Curl $curlClient */
        $curlClient = $om->get(\Magento\Framework\HTTP\Client\Curl::class);
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $om->get(\Psr\Log\LoggerInterface::class);

        $messages = [];
        $messagesError = [];

        $statusLabelTable = $resource->getTableName('sales_order_status_label');

        // Explicit mapping (fast-path)
        $explicitCodeToStatus = [
            'OG'     => 'processing',
            'IT'     => 'processing',
            'B'      => 'processing', // Booked -> processing
            'POD'    => 'complete',
            'CLOSED' => 'closed',
            'UNHOLD' => 'unhold',
            'OH'     => 'holded', // <- new: OH -> holded (Hold for pickup)
        ];

        // code -> candidate label names
        $codeToNames = [
            'OC'    => ['Processing'],
            'B'     => ['Processing'],
            'OD'    => ['Processing'],
            'PG'    => ['Processing'],
            'AP'    => ['Processing'],
            'PK'    => ['Shipped','Processed'],
            'DL'    => ['Shipped'],
            'POD'   => ['Complete','Delivered'],
            'RTC'   => ['Processed','Refunded','Returned'],
            'C'     => ['Canceled'],
            'FWD'   => ['Shipped'],
            'OG'    => ['Processing'],
            'IT'    => ['Processing'],
            'CLOSED'=> ['Closed'],
            'UNHOLD'=> ['Unhold'],
            'OH'    => ['On Hold','Hold for pickup','Hold'], // helpful candidates
        ];

        foreach ($selected as $row) {
            // allow formats "123|AWB" or "123"
            $parts = explode('|', $row . '|');
            $orderId = isset($parts[0]) ? (int)$parts[0] : 0;
            $awb_no = isset($parts[1]) ? trim($parts[1]) : '';

            if (!$orderId) {
                $messagesError[] = __("Invalid order id: %1", $row);
                continue;
            }

            // fetch awb if missing
            if ($awb_no === '') {
                $fastcooOrdersTable = $resource->getTableName('fastcoo_orders');
                try {
                    $awb_no = $connection->fetchOne(
                        "SELECT awb_no FROM {$fastcooOrdersTable} WHERE order_id = ? LIMIT 1",
                        [$orderId]
                    );
                } catch (\Throwable $e) {
                    $logger->error("Fastcoo Sync DB error for order {$orderId}: " . $e->getMessage());
                    $messagesError[] = __("Order #%1 has no AWB in database, cannot sync.", $orderId);
                    continue;
                }

                if (!$awb_no) {
                    $messagesError[] = __("Order #%1 has no AWB in database, cannot sync.", $orderId);
                    continue;
                }
            }

            // choose endpoint
            if ($systemType === 'lm') {
                $url = $lm_url;
                $method = 'GetDetailsLM';
            } else {
                $url = $fm_url;
                $method = 'GetDetailsFM';
            }

            if (!$url) {
                $messagesError[] = __("API endpoint not configured for order #%1.", $orderId);
                continue;
            }

            $payload = [
                'format' => 'json',
                'signMethod' => 'md5',
                'param' => ['awb_no' => $awb_no],
                'method' => $method,
                'customerId' => $settingsData['customer_id'] ?? ''
            ];

            try {
                $curlClient->addHeader('Content-Type', 'application/json');
                $curlClient->setOption(CURLOPT_TIMEOUT, 30);
                $curlClient->setOption(CURLOPT_CONNECTTIMEOUT, 10);
                $curlClient->setOption(CURLOPT_SSL_VERIFYHOST, 0);
                $curlClient->setOption(CURLOPT_SSL_VERIFYPEER, 0);

                $curlClient->post($url, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $response = $curlClient->getBody();

            } catch (\Throwable $e) {
                $messagesError[] = __("AWB %1 sync HTTP error: %2", $awb_no, $e->getMessage());
                $logger->error("Fastcoo Sync HTTP error for AWB {$awb_no}: " . $e->getMessage());
                continue;
            }

            $result = json_decode($response, true);
            if (!$result || !is_array($result)) {
                $messagesError[] = __("AWB %1 sync failed: invalid response.", $awb_no);
                $logger->error("Fastcoo Sync invalid response for AWB {$awb_no}: " . $response);
                continue;
            }

            // primary values
            $code = isset($result['shipment_data']['code']) ? trim($result['shipment_data']['code']) : '';
            $statusText = isset($result['shipment_data']['status']) ? trim($result['shipment_data']['status']) : '';

            // if travel_history contains more recent entries, prefer last activity status/code
            if (isset($result['travel_history']) && is_array($result['travel_history']) && count($result['travel_history']) > 0) {
                // take last element
                $last = end($result['travel_history']);
                if (isset($last['code']) && trim($last['code']) !== '') {
                    $code = trim($last['code']);
                }
                if (isset($last['new_status']) && trim($last['new_status']) !== '') {
                    $statusText = trim($last['new_status']);
                }
                // reset pointer (optional)
                reset($result['travel_history']);
            }

            $mappedStatusCode = null;

            // 0) explicit mapping by code (resolve to DB status if possible)
            if ($code !== '') {
                $uc = strtoupper($code);
                if (isset($explicitCodeToStatus[$uc]) && $explicitCodeToStatus[$uc]) {
                    $candidate = $explicitCodeToStatus[$uc];
                    $found = $connection->fetchOne(
                        "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                        [strtolower($candidate)]
                    );
                    if ($found) {
                        $mappedStatusCode = $found;
                    } else {
                        $mappedStatusCode = $candidate;
                    }
                }
            }

            // QUICK-RULE: treat "booked", "pickup", "scheduled" in statusText as processing
            if ($mappedStatusCode === null && $statusText !== '') {
                $lowerStatus = strtolower($statusText);
                if (
                    stripos($lowerStatus, 'booked') !== false ||
                    stripos($lowerStatus, 'pickup') !== false ||
                    stripos($lowerStatus, 'scheduled') !== false ||
                    stripos($lowerStatus, 'booked-pickup') !== false
                ) {
                    $found = $connection->fetchOne(
                        "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                        ['processing']
                    );
                    if ($found) {
                        $mappedStatusCode = $found;
                    } else {
                        $mappedStatusCode = 'processing';
                    }
                }
            }

            // quick checks for 'on hold' / 'closed' / 'unhold' / 'hold for pickup' in status text
            if ($mappedStatusCode === null && $statusText !== '') {
                $lowerStatus = strtolower($statusText);

                // Hold for pickup / On Hold => holded
                if (stripos($lowerStatus, 'hold for pickup') !== false
                    || stripos($lowerStatus, 'hold for') !== false
                    || stripos($lowerStatus, 'on hold') !== false
                    || stripos($lowerStatus, 'hold') !== false
                ) {
                    $found = $connection->fetchOne(
                        "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) IN (?,?) LIMIT 1",
                        ['holded', 'on hold']
                    );
                    $mappedStatusCode = $found ?: 'holded';
                }

                // closed => closed
                if ($mappedStatusCode === null && stripos($lowerStatus, 'closed') !== false) {
                    $found = $connection->fetchOne(
                        "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                        ['closed']
                    );
                    $mappedStatusCode = $found ?: 'closed';
                }

                // unhold / release => unhold
                if ($mappedStatusCode === null && (stripos($lowerStatus, 'unhold') !== false || stripos($lowerStatus, 'release') !== false || stripos($lowerStatus, 'released') !== false)) {
                    $found = $connection->fetchOne(
                        "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                        ['unhold']
                    );
                    $mappedStatusCode = $found ?: 'unhold';
                }
            }

            // 1) try code -> candidate names -> map to magento status code
            if ($mappedStatusCode === null && $code !== '') {
                $uc = strtoupper($code);
                if (isset($codeToNames[$uc]) && is_array($codeToNames[$uc])) {
                    foreach ($codeToNames[$uc] as $candName) {
                        $bind = [strtolower($candName)];
                        $found = $connection->fetchOne(
                            "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                            $bind
                        );
                        if ($found) {
                            $mappedStatusCode = $found;
                            break;
                        }
                    }
                }
            }

            // 2) exact match of returned status text
            if ($mappedStatusCode === null && $statusText !== '') {
                $bind = [strtolower($statusText)];
                $found = $connection->fetchOne(
                    "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                    $bind
                );
                if ($found) $mappedStatusCode = $found;
            }

            // 3) partial match (LIKE) on label
            if ($mappedStatusCode === null && $statusText !== '') {
                $found = $connection->fetchOne(
                    "SELECT status FROM {$statusLabelTable} WHERE label LIKE ? LIMIT 1",
                    ['%' . $statusText . '%']
                );
                if ($found) $mappedStatusCode = $found;
            }

            // 4) fallback keywords mapping (use statusText)
            if ($mappedStatusCode === null && $statusText !== '') {
                $fallbackKeywords = ['Processing','Shipped','Complete','Pending','Canceled','Refunded','Returned','Failed','Denied','Expired','Processed','Voided','Delivered','Closed','Unhold','Hold'];
                foreach ($fallbackKeywords as $kw) {
                    if (stripos($statusText, $kw) !== false) {
                        $found = $connection->fetchOne(
                            "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                            [strtolower($kw)]
                        );
                        if ($found) {
                            $mappedStatusCode = $found;
                            break;
                        }
                    }
                }
            }

            // 5) Additional custom quick rules (repeat booked-pickup check as extra safety)
            if ($mappedStatusCode === null && $statusText !== '') {
                $lowerStatus = strtolower($statusText);
                if (stripos($lowerStatus, 'booked') !== false
                    || stripos($lowerStatus, 'pickup') !== false
                    || stripos($lowerStatus, 'scheduled') !== false
                    || stripos($lowerStatus, 'booked-pickup') !== false
                ) {
                    $found = $connection->fetchOne(
                        "SELECT status FROM {$statusLabelTable} WHERE LOWER(label) = ? LIMIT 1",
                        ['processing']
                    );
                    if ($found) {
                        $mappedStatusCode = $found;
                    } else {
                        $mappedStatusCode = 'processing';
                    }
                }
            }

            if ($mappedStatusCode === null) {
                $messagesError[] = __("AWB %1 synced, but status/code not mapped. (code: %2, status: %3)", $awb_no, $code, $statusText);
                $logger->warning("Fastcoo Sync: unmapped AWB {$awb_no} (code={$code}, status='{$statusText}')");
                continue;
            }

            // update Magento order status
            try {
                // get order via repository
                $order = $orderRepository->get($orderId);

                // load concrete order model to call unhold if needed
                /** @var \Magento\Sales\Model\Order $orderModel */
                $orderModel = $om->create(\Magento\Sales\Model\Order::class)->load($orderId);

                $comment = __("Fastcoo Sync: AWB %1 (code: %2) → %3", $awb_no, $code, $statusText);

                // If mapped status is literal 'unhold' or statusText contained unhold/release -> attempt unhold action
                $isUnholdTarget = (is_string($mappedStatusCode) && strtolower($mappedStatusCode) === 'unhold')
                    || (stripos($statusText, 'unhold') !== false)
                    || (stripos($statusText, 'release') !== false)
                    || (stripos($statusText, 'released') !== false);

                if ($isUnholdTarget && $orderModel) {
                    try {
                        if (method_exists($orderModel, 'canUnhold') && method_exists($orderModel, 'unhold')) {
                            if ($orderModel->canUnhold()) {
                                $orderModel->unhold();
                                // save concrete model
                                $orderRepository->save($orderModel);
                                // refresh $order from repository
                                $order = $orderRepository->get($orderId);
                                // add history about unhold
                                $order->addStatusToHistory($order->getStatus(), __("Fastcoo Sync: order unheld for AWB %1", $awb_no), false);
                            }
                        }
                    } catch (\Throwable $e) {
                        $logger->warning("Fastcoo Sync: unhold attempt failed for order {$orderId}: " . $e->getMessage());
                    }
                }

                // Finally set the mapped status (DB-resolved or literal)
                $order->addStatusToHistory($mappedStatusCode, $comment, false);
                $order->setStatus($mappedStatusCode);

                $orderRepository->save($order);
                $messages[] = __("AWB %1 synced successfully. Status updated to: %2", $awb_no, $statusText);
            } catch (\Throwable $e) {
                $messagesError[] = __("Failed to update order %1 status: %2", $orderId, $e->getMessage());
                $logger->error("Fastcoo Sync: failed to update order {$orderId}: " . $e->getMessage());
            }
        } // end foreach selected

        if (!empty($messagesError)) {
            $this->messageManager->addErrorMessage(implode('', $messagesError));
        }
        if (!empty($messages)) {
            $this->messageManager->addSuccessMessage(implode('', $messages));
        }

        return $resultRedirect;
    }
}
