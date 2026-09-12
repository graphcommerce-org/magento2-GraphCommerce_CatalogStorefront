<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider;

use GraphCommerce\CatalogStorefront\Model\Registry\Definition;
use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface as Repository;
use GraphCommerce\CatalogStorefront\Model\Registry\Options;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class ResourceForm extends AbstractDataProvider
{
    private string $kind;
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        private readonly Repository $repository,
        private readonly Definition $definition,
        private readonly Options $options,
        private readonly RequestInterface $request,
        private readonly DataPersistorInterface $persistor,
        private readonly AuthorizationInterface $authorization,
        private readonly \Magento\Backend\Model\UrlInterface $url,
        private readonly \GraphCommerce\CatalogStorefrontApi\Service\SourceMetadataInterface $sourceMetadata,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->kind = (string)$request->getParam('kind');
        $this->definition->table($this->kind);
    }
    public function addFilter(\Magento\Framework\Api\Filter $filter): void
    {
    }
    public function getData(): array
    {
        $id = (int)$this->request->getParam('id', 0);
        $row = $id ? $this->repository->get($this->kind, $id) : ['id' => 0,'version' => 0,'enabled' => 1,'type' => 'generic'];
        foreach ($this->definition->fields($this->kind) as $field => $spec) {
            if (!array_key_exists($field, $row)) {
                $row[$field] = $spec['default'] ?? (in_array($spec['element'], ['ordered','fields','locations','multiselect'], true) ? [] : '');
            }
        }
        $key = 'catalog_resource_' . $this->kind . '_' . $id;
        $pending = $this->persistor->get($key);
        $this->persistor->clear($key);
        if (is_array($pending)) {
            $row = array_replace($row, $pending);
        }
        foreach ($this->definition->fields($this->kind) as $field => $spec) {
            if ($spec['element'] === 'multiselect' && is_array($row[$field] ?? null)) $row[$field] = array_map('strval', $row[$field]);
            if ($spec['element'] === 'select' && is_scalar($row[$field] ?? null)) $row[$field] = (string)$row[$field];
        }
        if ($this->kind === 'views' && !array_key_exists('book_id', (array)$pending)) $row['book_id'] = count($row['book_ids'] ?? []) === 1 ? (string)$row['book_ids'][0] : '';
        if ($this->kind === 'policies' && !array_key_exists('option_values', (array)$pending)) $row['option_values'] = $row['values'] === '' ? [] : preg_split('/\R/', $row['values']);
        $row['kind'] = $this->kind;
        // Magento Form uses the empty key when a new-resource route has no id.
        return [$this->request->getParam('id') ?? '' => $row];
    }
    public function getMeta(): array
    {
        $definitions = [
            'id' => ['element' => 'input','visible' => false], 'version' => ['element' => 'input','visible' => false], 'kind' => ['element' => 'input','visible' => false],
            'enabled' => ['label' => 'Status','element' => 'select','options' => ['1' => 'Enabled','0' => 'Disabled']],
            'name' => ['label' => 'Name','element' => 'input','required' => true],
            'code' => ['label' => 'Code','element' => 'input','required' => true,'notice' => 'Lowercase letters, digits, underscores and hyphens.'],
            'type' => ['label' => 'Type','element' => 'select','options' => $this->definition->types($this->kind),'required' => true],
        ] + $this->definition->fields($this->kind);
        if ($this->kind === 'views') {
            $definitions['book_id'] = ['label' => 'Price Book', 'element' => 'select', 'options' => 'books', 'required' => true];
            $definitions['book_ids']['required'] = true;
        }
        if ($this->kind === 'policies') {
            $definitions['attribute']['element'] = 'select';
            $definitions['attribute']['options'] = [];
            $definitions['option_values'] = ['label' => 'Values', 'element' => 'multiselect', 'options' => []];
            $id = (int)$this->request->getParam('id', 0);
            if ($id) {
                $policy = $this->repository->get('policies', $id);
                if (!empty($policy['source_id'])) {
                    try {
                        $metadata = $this->sourceMetadata->describe((int)$policy['source_id']);
                        $definitions['attribute']['options'] = array_column($metadata['attributes'], 'label', 'value');
                        $attribute = array_column($metadata['attributes'], null, 'value')[$policy['attribute']] ?? [];
                        $definitions['option_values']['options'] = array_column($attribute['options'] ?? [], 'label', 'value');
                    } catch (\Throwable) { /* The asynchronous feed request shows unavailable metadata without a local fallback. */ }
                }
            }
        }
        $children = [];
        $order = 0;
        $typeRules = [];
        // PHP-generated metadata disables JS template expansion; use the XML form's explicit provider.
        $formProvider = 'catalog_storefront_resource_form.resource_form_data_source';
        $sourceRoster = $layerRoster = [];
        if (in_array($this->kind, ['views', 'layers'], true)) {
            foreach ($this->repository->all('sources') as $source) $sourceRoster[] = ['value' => (string)$source['id'], 'label' => (string)$source['name'],
                'type' => $source['type'], 'enabled' => !empty($source['enabled']), 'identityNamespace' => $source['identity_namespace'] ?? '', 'locale' => $source['locale'] ?? ''];
        }
        if ($this->kind === 'views') {
            foreach ($this->repository->all('layers') as $layer) $layerRoster[] = ['value' => (string)$layer['id'], 'label' => (string)$layer['name'],
                'type' => $layer['type'], 'enabled' => !empty($layer['enabled']), 'sourceId' => (string)($layer['source_id'] ?? ''),
                'identityNamespace' => $layer['identity_namespace'] ?? '', 'locale' => $layer['locale'] ?? '', 'scope' => $layer['scope'] ?? 'view'];
        }
        $editable = $this->repository->capabilities()['can_manage'] && $this->authorization->isAllowed('GraphCommerce_CatalogStorefrontAdminhtml::manage');
        foreach ($definitions as $field => $spec) {
            $config = ['componentType' => 'field','formElement' => $spec['element'],'dataType' => 'text','dataScope' => $field,'source' => 'resource','label' => (string)__($spec['label'] ?? ''),'sortOrder' => $order += 10,'visible' => $spec['visible'] ?? true,'disabled' => !$editable,'validation' => ['required-entry' => (bool)($spec['required'] ?? false)]];
            if ((in_array($field, ['book_ids','book_id','policy_ids','option_values','attribute'], true) || ($this->kind === 'layers' && $field === 'source_id')) && isset($spec['options'])) {
                $config += ['component' => 'GraphCommerce_CatalogStorefrontAdminhtml/js/form/resource-picker', 'elementTmpl' => 'ui/grid/filters/elements/ui-select', 'filterOptions' => true, 'chipsEnabled' => true, 'multiple' => in_array($field, ['book_ids','policy_ids','option_values'], true), 'showCheckbox' => true];
            }
            if (isset($spec['notice'])) {
                $config['notice'] = (string)__($spec['notice']);
            }
            if ($this->kind === 'layers' && $field === 'source_id') {
                $config += ['resourceFilter' => 'sources', 'sourceRoster' => $sourceRoster,
                    'imports' => ['namespaceSelection' => $formProvider . ':data.identity_namespace']];
            }
            if (isset($spec['options'])) {
                $source = $spec['options'];
                $values = is_array($source) ? $source : (in_array($source, Definition::KINDS, true) ? array_column($this->repository->all($source), 'name', 'id') : $this->options->get($source));
                if ($field === 'parent_id') {
                    unset($values[(int)$this->request->getParam('id')]);
                }
                $config['options'] = [];
                if ($spec['element'] === 'select') {
                    $config['options'][] = ['value' => '','label' => (string)__('Please select')];
                }
                foreach ($values as $value => $label) {
                    $config['options'][] = ['value' => (string)$value,'label' => (string)__($label)];
                }
            }
            if (isset($spec['types'])) {
                foreach ($this->definition->types($this->kind) as $type => $label) {
                    $typeRules[$type][] = ['target' => 'catalog_storefront_resource_form.catalog_storefront_resource_form.general.' . $field,'callback' => 'visible','params' => [in_array($type, $spec['types'], true)]];
                }
            }
            if (in_array($spec['element'], ['ordered','fields','locations'], true)) {
                $columns = match ($spec['element']) {
                    'ordered'=>['resource_id' => ['label' => 'Layer','formElement' => 'select','options' => $config['options']]],
                    'fields'=>['field' => ['label' => 'Attribute Code','formElement' => 'input'],'operation' => ['label' => 'Operation','formElement' => 'select','options' => [['value' => 'override','label' => 'Override'],['value' => 'merge','label' => 'Merge']]]],
                    'locations'=>['code' => ['label' => 'Source Code','formElement' => 'input'],'name' => ['label' => 'Source Name','formElement' => 'input']],
                };
                if ($spec['element'] === 'ordered' && $this->kind === 'views') {
                    $columns['resource_id'] += ['component' => 'GraphCommerce_CatalogStorefrontAdminhtml/js/form/resource-picker',
                        'elementTmpl' => 'ui/grid/filters/elements/ui-select', 'multiple' => false, 'filterOptions' => true, 'chipsEnabled' => true,
                        'resourceFilter' => 'layers', 'sourceRoster' => $sourceRoster, 'layerRoster' => $layerRoster,
                        'imports' => ['sourceSelection' => $formProvider . ':data.source_id']];
                }
                if ($spec['element'] === 'fields') {
                    $columns['operation'] += ['component' => 'GraphCommerce_CatalogStorefrontAdminhtml/js/form/layer-operation',
                        'imports' => ['sourceBinding' => $formProvider . ':data.source_id', 'producerBinding' => $formProvider . ':data.layer_producer', 'namespaceBinding' => $formProvider . ':data.identity_namespace']];
                }
                $rowChildren = [];
                foreach ($columns as $key => $column) {
                    $rowChildren[$key] = ['arguments' => ['data' => ['config' => array_replace(['componentType' => 'field','dataType' => 'text','dataScope' => $key,'validation' => ['required-entry' => true],'disabled' => !$editable], $column)]]];
                }
                $rowChildren['action_delete'] = ['arguments' => ['data' => ['config' => ['componentType' => 'actionDelete','dataType' => 'text','label' => '','disabled' => !$editable]]]];
                // Native dynamicRows appends its index to the parent data scope itself.
                $config = array_replace($config, ['dataScope' => '', 'componentType' => 'dynamicRows','component' => 'Magento_Ui/js/dynamic-rows/dynamic-rows','template' => 'ui/dynamic-rows/templates/default','recordTemplate' => 'record','addButtonLabel' => (string)__('Add row'),'columnsHeader' => true,'deleteProperty' => 'delete','deleteValue' => true,'positionProvider' => 'position','dndConfig' => ['enabled' => $editable && $spec['element'] === 'ordered'],'defaultRecord' => false]);
                unset($config['formElement'], $config['options'], $config['validation']);
                $children[$field] = ['arguments' => ['data' => ['config' => $config]],'children' => ['record' => ['arguments' => ['data' => ['config' => ['componentType' => 'container','component' => 'Magento_Ui/js/dynamic-rows/record','isTemplate' => true,'is_collection' => true,'dataScope' => '']]],'children' => $rowChildren]]];
            } else {
                $children[$field] = ['arguments' => ['data' => ['config' => $config]]];
            }
        }
        $rules = [];
        foreach ($typeRules as $type => $actions) {
            $rules[] = ['value' => $type,'actions' => $actions];
        }
        $children['type']['arguments']['data']['config']['switcherConfig'] = ['enabled' => true,'rules' => $rules];
        $formConfig = ['component' => 'GraphCommerce_CatalogStorefrontAdminhtml/js/form/resource-fieldset', 'resourceKind' => $this->kind,
            'resourceId' => (int)$this->request->getParam('id', 0), 'editable' => $editable, 'metadataUrl' => $this->url->getUrl('catalog_storefront/resource/metadata'), 'fieldNames' => array_keys($children)];
        return array_replace_recursive(parent::getMeta(), ['general' => ['arguments' => ['data' => ['config' => $formConfig]], 'children' => $children]]);
    }
}
