<?php
namespace Fastcoo\Management\Model;

use Magento\Framework\Model\AbstractModel;

class Settings extends AbstractModel
{
    /**
     * Optional: explicitly declare id field name
     */
    protected $_idFieldName = 'settings_id';

    protected function _construct()
    {
        $this->_init(\Fastcoo\Management\Model\ResourceModel\Settings::class);
    }
}
