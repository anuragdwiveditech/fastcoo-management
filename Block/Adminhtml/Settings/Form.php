<?php
namespace Fastcoo\Management\Block\Adminhtml\Settings;

use Magento\Backend\Block\Template;
use Magento\Backend\Helper\Data as BackendHelper; // <-- important
use Fastcoo\Management\Model\SettingsFactory;
use Fastcoo\Management\Model\ResourceModel\Settings as SettingsResource;



class Form extends Template
{
    private $settingsFactory;
    private $settingsResource;
    private $backendHelper;

   public function __construct(
    Template\Context $context,
    \Fastcoo\Management\Model\SettingsFactory $settingsFactory,
    \Fastcoo\Management\Model\ResourceModel\Settings $settingsResource,
    BackendHelper $backendHelper,
    array $data = []
) {
    parent::__construct($context, $data);
    $this->settingsFactory = $settingsFactory;
    $this->settingsResource = $settingsResource;
    $this->backendHelper = $backendHelper;
}

public function getSaveUrl()
{
    return $this->backendHelper->getUrl('fastcoo/settings/save');
}
}
