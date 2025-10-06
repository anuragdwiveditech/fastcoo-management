<?php
namespace Fastcoo\Management\Plugin\Catalog\Ui\DataProvider\Product\Form\Modifier;

use Magento\Framework\UrlInterface;
use Magento\Framework\App\RequestInterface;

class SystemPlugin
{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var RequestInterface
     */
    private $request;

    public function __construct(
        UrlInterface $urlBuilder,
        RequestInterface $request
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->request = $request;
    }

    /**
     * After plugin to modify data conditionally for fastcoo admin route only.
     *
     * @param \Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\System $subject
     * @param array $result
     * @return array
     */
    public function afterModifyData(
        \Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\System $subject,
        array $result
    ) {
        // Check current admin route name (frontName). Only proceed for fastcoo.
        $routeName = $this->request->getRouteName(); // returns 'fastcoo' when frontName is fastcoo
        if ($routeName !== 'fastcoo') {
            return $result; // do nothing for core/catalog admin pages
        }

        // We are on fastcoo pages — replace URLs to fastcoo routes
        // Try to preserve parameters by building appropriate URLs.
        $id = $this->request->getParam('id');
        $type = $this->request->getParam('type') ?: null;
        $store = $this->request->getParam('store') ?: null;
        $set = $this->request->getParam('set') ?: null;

        $actionParams = [];
        if ($id !== null) {
            $actionParams['id'] = $id;
        }
        if ($type !== null) {
            $actionParams['type'] = $type;
        }
        if ($store !== null) {
            $actionParams['store'] = $store;
        }
        if ($set !== null) {
            $actionParams['set'] = $set;
        }

        // set new URLs
        $result = \array_replace_recursive(
            $result,
            [
                'config' => [
                    'submit_url' => $this->urlBuilder->getUrl('fastcoo/product/save', $actionParams),
                    'validate_url' => $this->urlBuilder->getUrl('fastcoo/product/validate', $actionParams),
                    'reloadUrl' => $this->urlBuilder->getUrl('fastcoo/product/reload', $actionParams),
                ]
            ]
        );

        return $result;
    }
}
