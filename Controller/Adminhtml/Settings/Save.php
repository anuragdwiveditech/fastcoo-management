<?php
namespace Fastcoo\Management\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Fastcoo\Management\Model\SettingsFactory;
use Fastcoo\Management\Model\ResourceModel\Settings as SettingsResource;
use Psr\Log\LoggerInterface;

class Save extends Action implements HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'Fastcoo_Management::settings';

    private $formKeyValidator;
    private $settingsFactory;
    private $settingsResource;
    private $logger;

    public function __construct(
        Action\Context $context,
        FormKeyValidator $formKeyValidator,
        SettingsFactory $settingsFactory,
        SettingsResource $settingsResource,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->formKeyValidator = $formKeyValidator;
        $this->settingsFactory = $settingsFactory;
        $this->settingsResource = $settingsResource;
        $this->logger = $logger;
    }

    public function execute()
{
    $request = $this->getRequest();

    // only accept POST
    if (!$request->isPost()) {
        $this->logger->warning('FASTCOO_SAVE: non-POST request', ['method' => $request->getMethod()]);
        $this->messageManager->addErrorMessage(__('Invalid request method.'));
        return $this->_redirect('*/*/index');
    }

    // validate CSRF / form key
    if (!$this->formKeyValidator->validate($request)) {
        $this->logger->warning('FASTCOO_SAVE: invalid form key');
        $this->messageManager->addErrorMessage(__('Invalid form key.'));
        return $this->_redirect('*/*/index');
    }

    $post = $request->getPostValue();

    // whitelist allowed keys to avoid unexpected input
    $allowed = ['endpoint_url', 'customer_id', 'secret_key', 'system_type'];
    $data = [];
    foreach ($allowed as $key) {
        if (isset($post[$key])) {
            $data[$key] = trim($post[$key]);
        }
    }

    try {
        $model = $this->settingsFactory->create();
        // load existing (id = 1) if present
        $this->settingsResource->load($model, 1);

        // if record exists, ensure id set so resource will do UPDATE
        if ($model->getId()) {
            $model->setId($model->getId()); // explicit, optional
        } else {
            // For first-time insert, setId may be omitted (resource will insert)
            // Optionally you can force settings_id = 1 if you require single-row with fixed id:
            // $data['settings_id'] = 1;
        }

        // merge data
        $model->addData($data);

        // save via resource model (single source of truth)
        $this->settingsResource->save($model);

        $this->logger->debug('FASTCOO_AFTER_SAVE', $model->getData());
        $this->messageManager->addSuccessMessage(__('Settings saved.'));
    } catch (\Throwable $e) {
        // log full trace on dev; lower verbosity in production
        $this->logger->critical('FASTCOO_SAVE_ERROR: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        $this->messageManager->addErrorMessage(__('Error saving settings: %1', $e->getMessage()));
    }

    return $this->_redirect('*/*/index');
}

}
