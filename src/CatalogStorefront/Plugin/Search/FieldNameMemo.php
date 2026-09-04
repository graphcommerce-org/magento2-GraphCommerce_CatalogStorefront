<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Search;

use Magento\Elasticsearch\Model\Adapter\FieldMapper\FieldMapperResolver;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Remembers the search index field name per attribute code and context within
 * a request: the core mapper loads the attribute for every facet bucket,
 * filter and sort, several times per attribute.
 */
class FieldNameMemo implements ResetAfterRequestInterface
{
    private const LIMIT = 2000;

    /** @var array<string, string> */
    private array $names = [];

    public function aroundGetFieldName(FieldMapperResolver $subject, \Closure $proceed, $attributeCode, $context = [])
    {
        $key = $attributeCode . '|' . json_encode($context);
        if (!isset($this->names[$key])) {
            if (count($this->names) >= self::LIMIT) {
                $this->names = [];
            }
            $this->names[$key] = $proceed($attributeCode, $context);
        }

        return $this->names[$key];
    }

    public function _resetState(): void
    {
        $this->names = [];
    }
}
