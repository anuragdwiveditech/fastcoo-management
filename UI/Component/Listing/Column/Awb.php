<?php
namespace Fastcoo\Management\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;

class Awb extends Column
{
    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * Awb constructor.
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param ResourceConnection $resource
     * @param array $components
     * @param array $data
     */
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
     * Prepare dataSource: inject awb_no for each row if exists.
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items']) || empty($dataSource['data']['items'])) {
            return $dataSource;
        }

        $connection = $this->resource->getConnection();
        $fastcooTable = $this->resource->getTableName('fastcoo_orders');

        // collect entity_ids of current page rows
        $ids = [];
        foreach ($dataSource['data']['items'] as $item) {
            if (!empty($item['entity_id'])) {
                $ids[] = (int)$item['entity_id'];
            }
        }
        if (empty($ids)) {
            // nothing to do
            foreach ($dataSource['data']['items'] as &$item) {
                $item[$this->getData('name')] = '';
            }
            return $dataSource;
        }

        // fetch AWBs in a single query
        $select = $connection->select()
            ->from($fastcooTable, ['order_id', 'awb_no'])
            ->where('order_id IN(?)', $ids);

        $rows = $connection->fetchAll($select);

        // map order_id => awb_no
        $map = [];
        foreach ($rows as $r) {
            // if multiple rows exist for same order, keep last/first (here last)
            $map[(int)$r['order_id']] = $r['awb_no'];
        }

        // inject AWB values
        foreach ($dataSource['data']['items'] as &$item) {
            $orderId = isset($item['entity_id']) ? (int)$item['entity_id'] : 0;
            $item[$this->getData('name')] = isset($map[$orderId]) && $map[$orderId] !== null
                ? $map[$orderId]
                : ''; // or 'N/A'
        }

        return $dataSource;
    }
}
