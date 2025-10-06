<?php
namespace Fastcoo\Management\Block\Adminhtml;


use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use Magento\Catalog\Model\Product\TypeFactory;
use Magento\Catalog\Model\ProductFactory;

class FastcooButton extends Container
{
    /**
     * @var TypeFactory
     */
    protected $_typeFactory;

    /**
     * @var ProductFactory
     */
    protected $_productFactory;

    public function __construct(
        Context $context,
        TypeFactory $typeFactory,
        ProductFactory $productFactory,
        array $data = []
    ) {
        $this->_typeFactory = $typeFactory;
        $this->_productFactory = $productFactory;
        parent::__construct($context, $data);
    }

    protected function _prepareLayout()
    {
        $addButtonProps = [
            'id' => 'fastcoo_add_new_product',
            'label' => __('Add Product (Fastcoo)'),
            'class' => 'add',
            'button_class' => '',
            'class_name' => \Magento\Backend\Block\Widget\Button\SplitButton::class,
            'options' => $this->_getAddProductButtonOptions(),
            'dropdown_button_aria_label' => __('Add product of type'),
        ];

        $this->buttonList->add('fastcoo_add_new', $addButtonProps);

        return parent::_prepareLayout();
    }

    protected function _getAddProductButtonOptions()
    {
        $splitButtonOptions = [];
        $types = $this->_typeFactory->create()->getTypes();

        uasort($types, function ($a, $b) {
            return ($a['sort_order'] < $b['sort_order']) ? -1 : 1;
        });

        $defaultSetId = $this->_productFactory->create()->getDefaultAttributeSetId();

        foreach ($types as $typeId => $type) {
            $splitButtonOptions[$typeId] = [
                'label' => __($type['label']),
                // IMPORTANT: change route to your module route here
                'onclick' => "setLocation('" . $this->getUrl('fastcoo/product/new', ['set' => $defaultSetId, 'type' => $typeId]) . "')",
                'default' => \Magento\Catalog\Model\Product\Type::DEFAULT_TYPE == $typeId,
            ];
        }

        return $splitButtonOptions;
    }
}
