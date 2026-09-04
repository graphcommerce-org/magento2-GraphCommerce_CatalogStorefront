<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\Config;

/**
 * The mapping of a metadata entity: the fields it declares in
 * `EntityMappings`, nothing else. Every field stays in the source.
 */
class Declared implements EntityConfigInterface
{
    /**
     * @param array<string, string> $fields field name to engine-neutral type
     */
    public function __construct(
        private readonly array $fields = [],
    ) {
    }

    public function getSettings(): array
    {
        $settings = ['dynamic' => false];
        foreach ($this->fields as $field => $type) {
            $settings['properties'][$field] = ['type' => $type];
        }

        return $settings;
    }
}
