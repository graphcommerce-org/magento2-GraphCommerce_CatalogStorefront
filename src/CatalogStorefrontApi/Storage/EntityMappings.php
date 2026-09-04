<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Storage;

/**
 * The fields an entity declares for filtering, sorting and statistics
 * (di.xml `mappings`, entity name to dotted field name to type), in the
 * engine-neutral types keyword, integer, float, boolean and date, or a
 * nested list of objects (`type` nested, `fields` as above). A storage module
 * maps them to its index. Every other field stays in the source only.
 */
final class EntityMappings
{
    /**
     * @param array<string, array<string, string|array>> $mappings
     */
    public function __construct(
        private readonly array $mappings = [],
    ) {
    }

    /**
     * @return array<string, string|array> field name to type
     */
    public function fields(string $entity): array
    {
        return $this->mappings[$entity] ?? [];
    }
}
