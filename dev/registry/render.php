<?php

declare(strict_types=1);

$root = getenv('MAGENTO_ROOT');
if (!$root) {
    throw new RuntimeException('Set MAGENTO_ROOT.');
}
require $root . '/app/bootstrap.php';
$o = Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$o->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$o->get(Magento\Framework\App\AreaList::class)->getArea('adminhtml')->load(Magento\Framework\App\Area::PART_CONFIG);
if (($argv[3] ?? '') === 'editable') {
    class CatalogRegistryRenderAuthorization implements Magento\Framework\AuthorizationInterface
    {
        public function isAllowed($resource, $privilege = null): bool
        {
            return in_array($resource, [
                'GraphCommerce_CatalogStorefrontAdminhtml::views',
                'GraphCommerce_CatalogStorefrontAdminhtml::manage',
                'GraphCommerce_CatalogStorefrontAdminhtml::delete',
            ], true);
        }
    }
    $o->configure(['preferences' => [Magento\Framework\AuthorizationInterface::class => CatalogRegistryRenderAuthorization::class]]);
}

$o->get(Magento\Framework\View\DesignInterface::class)->setDesignTheme('Magento/backend', 'adminhtml');
$kind = $argv[1] ?? 'overview';
$id = (int)($argv[2] ?? 0);
$request = $o->get(Magento\Framework\App\Request\Http::class);
$request->setRouteName('catalog_storefront')->setControllerName($kind === 'overview' ? 'views' : 'resource')->setActionName($kind === 'overview' ? 'index' : 'edit')->setParam('kind', $kind)->setParam('id', $id);
$page = $o->get(Magento\Framework\View\Result\PageFactory::class)->create(false, ['isIsolated' => true]);
$layout = $page->getLayout();
$layout->publicBuild();
$output = $layout->renderElement($kind === 'overview' ? 'catalog.storefront.overview' : 'catalog_storefront_resource_form');
if (!str_contains($output, $kind === 'overview' ? 'catalog_storefront_views_listing' : 'catalog_storefront_resource_form')) {
    throw new RuntimeException('Expected UI component absent: ' . json_encode(['handle' => $request->getFullActionName(),'handles' => $layout->getUpdate()->getHandles(),'ui' => array_map(static fn($x)=>(string)$x['name'], $layout->getUpdate()->asSimplexml()->xpath('//uiComponent')),'bytes' => strlen($output)]));
}
// Only structural facts leave the renderer; URLs, form keys and session data do not.
$components = [];
if ($kind === 'overview') {
    foreach (['views','sources','books','stocks','layers','policies'] as $k) {
        $provider = $o->create(GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider\RegistryListing::class, ['name' => 'catalog_storefront_' . $k . '_listing_data_source','primaryFieldName' => 'id','requestFieldName' => 'id']);
        $data = $provider->getData();
        $components[$k] = ['records' => $data['totalRecords'],'types' => array_values(array_unique(array_column($data['items'], 'type')))];
    }
} else {
    $provider = $o->create(GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider\ResourceForm::class, ['name' => 'resource_form_data_source','primaryFieldName' => 'id','requestFieldName' => 'id']);
    $meta = $provider->getMeta();
    $formData = $provider->getData();
    $values = reset($formData);
    foreach ($meta['general']['children'] as $field => $definition) {
        $config = $definition['arguments']['data']['config'];
        if (($config['formElement'] ?? '') === 'multiselect') {
            $optionValues = array_column($config['options'], 'value');
            foreach ($values[$field] ?? [] as $value) {
                if (!in_array($value, $optionValues, true)) throw new RuntimeException('Native multiselect value type mismatch: ' . $field);
            }
        }
    }

    $components = ['fields' => array_keys($meta['general']['children']),'dataRows' => count($provider->getData()), 'multiselectBindingsMatch' => true, 'editable' => ($argv[3] ?? '') === 'editable'];
}
echo json_encode(['rendered' => true,'kind' => $kind,'bytes' => strlen($output),'components' => $components], JSON_PRETTY_PRINT),"\n";
