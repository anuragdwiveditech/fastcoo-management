<?php
namespace Fastcoo\Management\Ui\Component\Listing\Columns;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Ui\Component\Listing\Columns\Column;

class CustomQty extends Column
{
    protected $stockRegistry;

    public function __construct(
        \Magento\Framework\View\Element\UIComponent\ContextInterface $context,
        \Magento\Framework\View\Element\UiComponentFactory $uiComponentFactory,
        StockRegistryInterface $stockRegistry,
        array $components = [],
        array $data = []
    ) {
        $this->stockRegistry = $stockRegistry;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                try {
                    $stockItem = $this->stockRegistry->getStockItem($item['entity_id']);
                    $item[$this->getData('name')] = $stockItem->getQty();
                } catch (\Exception $e) {
                    $item[$this->getData('name')] = 0;
                }
            }
        }
        return $dataSource;
    }
}
