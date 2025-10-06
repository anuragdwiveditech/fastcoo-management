<?php
namespace Fastcoo\Management\Plugin;

use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class RewriteEditLink
{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        UrlInterface $urlBuilder,
        LoggerInterface $logger = null
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    /**
     * After prepareDataSource for any actions column — force edit href to fastcoo route
     *
     * @param \Magento\Ui\Component\Listing\Columns\Column $subject
     * @param array $result
     * @return array
     */
    public function afterPrepareDataSource(
        \Magento\Ui\Component\Listing\Columns\Column $subject,
        $result
    ) {
        try {
            if (!isset($result['data']['items']) || !is_array($result['data']['items'])) {
                return $result;
            }

            $columnName = $subject->getData('name'); // usually 'actions' or custom column name

            foreach ($result['data']['items'] as &$item) {
                if (!is_array($item)) {
                    // if row isn't array, skip it (defensive)
                    continue;
                }
                if (!isset($item['entity_id'])) {
                    continue;
                }

                // Ensure the actions column is an array we can safely set into
                if (!isset($item[$columnName]) || !is_array($item[$columnName])) {
                    // If it's a string or object, overwrite safely with an array
                    $item[$columnName] = [];
                }

                // Set the edit action — keep keys consistent with Magento actions structure
                $item[$columnName]['edit'] = [
                    'href' => $this->urlBuilder->getUrl('fastcoo/product/edit', ['id' => $item['entity_id']]),
                    'label' => __('Edit'),
                    'hidden' => false,
                ];
            }
        } catch (\Throwable $e) {
            // don't break the grid — log the error if logger available
            if ($this->logger) {
                $this->logger->critical('Fastcoo RewriteEditLink error: ' . $e->getMessage());
            }
        }

        return $result;
    }
}
