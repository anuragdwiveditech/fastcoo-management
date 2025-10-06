<?php
namespace Fastcoo\Management\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;

class AwbAction extends Column
{
    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * Fallback endpoint
     *
     * @var string
     */
    protected $defaultEndpoint = 'https://api.fastcoo-tech.com';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        ResourceConnection $resource,
        array $components = [],
        array $data = []
    ) {
        $this->resource = $resource;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare dataSource rows with printable AWB icon link
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (empty($dataSource['data']['items'])) {
            return $dataSource;
        }

        $items = &$dataSource['data']['items'];

        // Determine if we need DB lookup for awb_no
        $needDbLookup = false;
        foreach ($items as $it) {
            if (!isset($it['awb_no']) || $it['awb_no'] === '') {
                $needDbLookup = true;
                break;
            }
        }

        // Get endpoint from DB
        $endpointBase = $this->getEndpointFromDb();
        if (!$endpointBase) {
            $endpointBase = $this->defaultEndpoint;
        }
        $endpointBase = rtrim($endpointBase, '/');

        $map = [];
        if ($needDbLookup) {
            $ids = [];
            foreach ($items as $it) {
                if (!empty($it['entity_id'])) {
                    $ids[] = (int)$it['entity_id'];
                }
            }
            if (!empty($ids)) {
                $conn = $this->resource->getConnection();
                $fastcooTable = $this->resource->getTableName('fastcoo_orders');
                $select = $conn->select()
                    ->from($fastcooTable, ['order_id', 'awb_no'])
                    ->where('order_id IN(?)', $ids);
                $rows = $conn->fetchAll($select);
                foreach ($rows as $r) {
                    $map[(int)$r['order_id']] = $r['awb_no'];
                }
            }
        }

        // Inline SVG printer icon (simple)
        $printerSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 8h-1V3H6v5H5c-1.1 0-2 .9-2 2v6h4v4h10v-4h4v-6c0-1.1-.9-2-2-2zM8 5h8v3H8V5zm8 14H8v-4h8v4z"/></svg>';

        foreach ($items as &$row) {
            $orderId = isset($row['entity_id']) ? (int)$row['entity_id'] : 0;
            $awb = '';
            if (!empty($row['awb_no'])) {
                $awb = $row['awb_no'];
            } elseif (isset($map[$orderId])) {
                $awb = $map[$orderId];
            }

            if ($awb) {
                // build print url using endpoint (smart)
                $lowerEndpoint = strtolower($endpointBase);
                if (strpos($lowerEndpoint, '/api/print') !== false) {
                    $printBase = $endpointBase;
                } else {
                    $printBase = $endpointBase . '/API/Print';
                }
                $printUrl = rtrim($printBase, '/') . '/' . rawurlencode($awb);

                // escape for attribute
                $printUrlAttr = htmlspecialchars($printUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $awbLabel = htmlspecialchars($awb, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                // anchor with explicit onclick window.open (helps when target/_blank blocked)
                $html = '<a href="' . $printUrlAttr . '" title="Print AWB ' . $awbLabel . '" ' .
                    'onclick="window.open(\'' . $printUrlAttr . '\', \'_blank\'); return false;" ' .
                    'style="display:inline-flex; align-items:center; gap:6px; text-decoration:none;">' .
                    '<span aria-hidden="true" style="line-height:0;">' . $printerSvg . '</span>' .
                    '<span class="fastcoo-awb-text" style="font-size:12px;color:inherit;padding-left:4px;">' . $awbLabel . '</span>' .
                    '</a>';
            } else {
                // no AWB — show disabled icon (lighter)
                $html = '<span style="opacity:.35;">' . $printerSvg . '</span>';
            }

            $row[$this->getData('name')] = $html;
        }

        return $dataSource;
    }

    /**
     * Return endpoint_url from fastcoo_settings row id = 1
     *
     * @return string
     */
    protected function getEndpointFromDb()
    {
        try {
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('fastcoo_settings');
            $select = $conn->select()
                ->from($table, ['endpoint_url'])
                ->where('settings_id = ?', 1)
                ->limit(1);
            $row = $conn->fetchOne($select);
            if ($row && is_string($row)) {
                return trim($row);
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }
        return '';
    }
}
