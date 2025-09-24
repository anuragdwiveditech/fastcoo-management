<?php
namespace Fastcoo\Management\Model\ResourceModel\Settings;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Fastcoo\Management\Model\Settings as SettingsModel;
use Fastcoo\Management\Model\ResourceModel\Settings as SettingsResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(SettingsModel::class, SettingsResource::class);
    }
}
