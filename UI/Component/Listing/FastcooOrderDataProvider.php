<?php
namespace Fastcoo\Management\Ui\Component\Listing;

use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Ui\Component\Listing\Columns\Price;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

class FastcooOrderDataProvider extends DataProvider
{
    protected $collection;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData()
    {
        $items = $this->collection->getData();
        return [
            'totalRecords' => $this->collection->getSize(),
            'items' => $items,
        ];
    }
}
