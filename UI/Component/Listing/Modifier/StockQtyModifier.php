<?php
namespace Fastcoo\Management\Ui\Component\Listing\Modifier;

use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class StockQtyModifier implements ModifierInterface
{
    protected $stockRegistry;

    public function __construct(StockRegistryInterface $stockRegistry)
    {
        $this->stockRegistry = $stockRegistry;
    }

    public function modifyData(array $data)
    {
        if (isset($data['items'])) {
            foreach ($data['items'] as &$item) {
                $stockItem = $this->stockRegistry->getStockItem($item['entity_id']);
                $item['qty'] = $stockItem->getQty();
            }
        }
        return $data;
    }

    public function modifyMeta(array $meta)
    {
        return $meta;
    }
}
