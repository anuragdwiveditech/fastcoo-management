<?php
namespace Fastcoo\Management\Ui\Component\Listing\Columns;

use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\Ui\Component\Listing\Columns\Column;

class SalableQty extends Column
{
    protected $getSalableQty;

    public function __construct(
        \Magento\Framework\View\Element\UIComponent\ContextInterface $context,
        \Magento\Framework\View\Element\UiComponentFactory $uiComponentFactory,
        GetProductSalableQtyInterface $getSalableQty,
        array $components = [],
        array $data = []
    ) {
        $this->getSalableQty = $getSalableQty;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as & $item) {
            try {
                $stockId = 1; // Default Stock
                $salableQty = $this->getSalableQty->execute($item['sku'], $stockId);

                // HTML string: Default Stock bold
                $item[$this->getData('name')] = '<strong>Default Stock</strong>: ' . $salableQty;
            } catch (\Exception $e) {
                $item[$this->getData('name')] = '<strong>Default Stock</strong>: 0';
            }
        }

        return $dataSource;
    }
}
