<?php
namespace Fastcoo\Management\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Settings extends AbstractDb
{
    protected function _construct()
    {
        // table name and primary key field
        $this->_init('fastcoo_settings', 'settings_id');
    }
}
