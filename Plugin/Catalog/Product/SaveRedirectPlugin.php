<?php
namespace Fastcoo\Management\Plugin\Catalog\Product;

use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class SaveRedirectPlugin
{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(UrlInterface $urlBuilder, LoggerInterface $logger = null)
    {
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    /**
     * After plugin for Catalog Save controller execute()
     *
     * @param \Magento\Catalog\Controller\Adminhtml\Product\Save $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterExecute(
        \Magento\Catalog\Controller\Adminhtml\Product\Save $subject,
        $result
    ) {
        try {
            if ($result instanceof Redirect) {
                // Prefer request params (more reliable)
                $request = $subject->getRequest();
                $id = $request->getParam('id') ?: ($request->getParam('product')['entity_id'] ?? null);
                $store = $request->getParam('store');
                $set = $request->getParam('set');
                $type = $request->getParam('type');
                $back = $request->getParam('back');

                // If core already returned a URL containing /catalog/, replace frontName only
                $url = $result->getUrl();
                if ($url && strpos($url, '/catalog/') !== false) {
                    $new = preg_replace('#/catalog/#', '/fastcoo/', $url, 1);
                    $result->setUrl($new);
                    return $result;
                }

                // Otherwise, if we have an id, build fastcoo edit url preserving params
                if ($id) {
                    $params = ['id' => $id];
                    if ($store !== null) { $params['store'] = $store; }
                    if ($set !== null)   { $params['set'] = $set; }
                    if ($type !== null)  { $params['type'] = $type; }
                    if ($back !== null)  { $params['back'] = $back; }

                    $result->setUrl($this->urlBuilder->getUrl('fastcoo/product/edit', $params));
                    return $result;
                }

                // fallback: go to fastcoo listing (keep store if present)
                $fallbackParams = [];
                if ($store !== null) { $fallbackParams['store'] = $store; }
                $result->setUrl($this->urlBuilder->getUrl('fastcoo/product/index', $fallbackParams));
            }
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Fastcoo SaveRedirectPlugin error: ' . $e->getMessage());
            }
            // do not break core: return original result
        }

        return $result;
    }
}
