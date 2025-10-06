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
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(UrlInterface $urlBuilder, LoggerInterface $logger)
    {
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    /**
     * After core Save execute — change redirect URL to fastcoo if it points to catalog
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
                $url = $result->getUrl();
                if ($url && strpos($url, '/catalog/') !== false) {
                    $new = preg_replace('#/catalog/#', '/fastcoo/', $url, 1);
                    $result->setUrl($new);
                    return $result;
                }

                // fallback: build edit path using request id
                $id = $subject->getRequest()->getParam('id') ?: ($subject->getRequest()->getParam('product')['entity_id'] ?? null);
                if ($id) {
                    $result->setUrl($this->urlBuilder->getUrl('fastcoo/product/edit', ['id' => $id]));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Fastcoo SaveRedirectPlugin error: ' . $e->getMessage());
        }

        return $result;
    }
}
