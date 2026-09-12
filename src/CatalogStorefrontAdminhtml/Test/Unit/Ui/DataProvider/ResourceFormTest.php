<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Ui\DataProvider;

use GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider\ResourceForm;
use GraphCommerce\CatalogStorefront\Model\Registry\{Definition, Options, Platform};
use GraphCommerce\CatalogStorefrontApi\Service\{ConfigurationInterface, SourceMetadataInterface};
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Backend\Model\UrlInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ResourceFormTest extends TestCase
{
    #[DataProvider('bindingFields')]
    public function testProducerBindingsUseExistingGenericOnlyNativeFields(string $kind, array $fields): void
    {
        $form = $this->form($kind); $meta = $form->getMeta()['general'];
        self::assertSame('GraphCommerce_CatalogStorefrontAdminhtml/js/form/resource-fieldset', $meta['arguments']['data']['config']['component']);
        $rules = $meta['children']['type']['arguments']['data']['config']['switcherConfig']['rules'];
        foreach ($fields as $field) {
            $config = $meta['children'][$field]['arguments']['data']['config'];
            self::assertSame($field, $config['dataScope']); self::assertFalse($config['disabled']); self::assertNotSame('', $config['notice']);
            foreach ($rules as $rule) foreach ($rule['actions'] as $action) if (str_ends_with($action['target'], '.' . $field)) self::assertSame([$rule['value'] === 'generic'], $action['params']);
        }
    }

    public static function bindingFields(): array
    {
        return [['sources', ['identity_namespace']], ['books', ['price_producer', 'identity_namespace']],
            ['stocks', ['stock_producer', 'identity_namespace']], ['layers', ['source_id', 'layer_producer', 'identity_namespace']]];
    }

    public function testLayerSourcePickerUsesRegistryMetadataWithoutNativeAttributeReads(): void
    {
        $meta = $this->form('layers')->getMeta()['general']['children'];
        $source = $meta['source_id']['arguments']['data']['config'];
        self::assertSame('GraphCommerce_CatalogStorefrontAdminhtml/js/form/resource-picker', $source['component']);
        self::assertSame('ui/grid/filters/elements/ui-select', $source['elementTmpl']);
        self::assertSame('sources', $source['resourceFilter']);
        self::assertSame('products', $source['sourceRoster'][0]['identityNamespace']);
        self::assertSame('catalog_storefront_resource_form.resource_form_data_source:data.identity_namespace', $source['imports']['namespaceSelection']);
        self::assertSame('dynamicRows', $meta['fields']['arguments']['data']['config']['componentType']);
        $operation = $meta['fields']['children']['record']['children']['operation']['arguments']['data']['config'];
        self::assertSame('GraphCommerce_CatalogStorefrontAdminhtml/js/form/layer-operation', $operation['component']);
        self::assertSame([
            'sourceBinding' => 'catalog_storefront_resource_form.resource_form_data_source:data.source_id',
            'producerBinding' => 'catalog_storefront_resource_form.resource_form_data_source:data.layer_producer',
            'namespaceBinding' => 'catalog_storefront_resource_form.resource_form_data_source:data.identity_namespace',
        ], $operation['imports']);
        self::assertSame('input', $meta['fields']['children']['record']['children']['field']['arguments']['data']['config']['formElement']);
    }

    public function testViewLayerPickerReceivesOnlyPresentationRosterAndSourceBinding(): void
    {
        $meta = $this->form('views')->getMeta()['general']['children'];
        $picker = $meta['layer_ids']['children']['record']['children']['resource_id']['arguments']['data']['config'];
        self::assertSame('layers', $picker['resourceFilter']);
        self::assertSame('3', $picker['layerRoster'][0]['sourceId']);
        self::assertSame('catalog_storefront_resource_form.resource_form_data_source:data.source_id', $picker['imports']['sourceSelection']);
    }

    public function testReadOnlyCapabilityKeepsAllNewControlsDisabled(): void
    {
        $meta = $this->form('layers', false)->getMeta()['general']['children'];
        foreach (['source_id', 'layer_producer', 'identity_namespace'] as $field) self::assertTrue($meta[$field]['arguments']['data']['config']['disabled']);
        self::assertTrue($meta['fields']['children']['record']['children']['operation']['arguments']['data']['config']['disabled']);
    }

    public function testNewResourceDefaultsUseTheMagentoFormEmptyRequestKey(): void
    {
        $data = $this->form('layers')->getData();
        self::assertSame([''], array_keys($data)); self::assertSame('generic', $data['']['type']); self::assertSame(1, $data['']['enabled']);
        self::assertSame('', $data['']['source_id']); self::assertSame('', $data['']['identity_namespace']);
    }

    #[DataProvider('dynamicRows')]
    public function testDynamicRowsInheritParentScopeWithoutRepeatingTheirIndex(string $kind, string $field, string $column): void
    {
        $node = $this->form($kind)->getMeta()['general']['children'][$field];
        $config = $node['arguments']['data']['config'];
        self::assertSame('Magento_Ui/js/dynamic-rows/dynamic-rows', $config['component']);
        // Core links recordData to provider:parentScope.index and creates index.row records.
        self::assertSame('', $config['dataScope']);
        self::assertSame('', $node['children']['record']['arguments']['data']['config']['dataScope']);
        self::assertSame($column, $node['children']['record']['children'][$column]['arguments']['data']['config']['dataScope']);
    }

    public static function dynamicRows(): array
    {
        return [['layers', 'fields', 'operation'], ['views', 'layer_ids', 'resource_id'], ['stocks', 'locations', 'code']];
    }

    private function form(string $kind, bool $editable = true): ResourceForm
    {
        $repository = $this->createStub(ConfigurationInterface::class);
        $repository->method('capabilities')->willReturn(['can_manage' => $editable]);
        $repository->method('all')->willReturnCallback(static fn($kind) => match ($kind) {
            'sources' => [['id' => 3, 'name' => 'External', 'type' => 'generic', 'enabled' => 1, 'identity_namespace' => 'products', 'locale' => 'en_US']],
            'layers' => [['id' => 4, 'name' => 'Content', 'type' => 'generic', 'enabled' => 1, 'source_id' => 3, 'identity_namespace' => 'products']], default => [],
        });
        $platform = $this->createStub(Platform::class); $platform->method('name')->willReturn('Magento');
        $options = $this->createStub(Options::class); $options->method('get')->willReturn([]);
        $request = $this->createStub(RequestInterface::class); $request->method('getParam')->willReturnCallback(static fn($name, $default = null) => $name === 'kind' ? $kind : $default);
        $authorization = $this->createStub(AuthorizationInterface::class); $authorization->method('isAllowed')->willReturn(true);
        $url = $this->createStub(UrlInterface::class); $url->method('getUrl')->willReturn('/metadata');
        $metadata = $this->createMock(SourceMetadataInterface::class); $metadata->expects(self::never())->method('describe');
        return new ResourceForm('resource_form_data_source', 'id', 'id', $repository, new Definition($platform), $options, $request,
            $this->createStub(DataPersistorInterface::class), $authorization, $url, $metadata);
    }
}
