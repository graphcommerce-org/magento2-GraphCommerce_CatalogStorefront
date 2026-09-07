<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use OpenSearch\Exception\JsonException;
use OpenSearch\Serializers\SmartSerializer;

/**
 * Decodes JSON responses with ext-simdjson_plus, about twice as fast as json_decode on a page
 * of product documents. Without the extension the responses decode as core's serializer does.
 */
class Serializer extends SmartSerializer
{
    public function deserialize(?string $data, array $headers)
    {
        $type = $headers['content_type'] ?? implode(' ', (array)(array_change_key_case($headers)['content-type'] ?? []));
        if ($data === null || $data === '' || !str_contains($type, 'json') || !function_exists('simdjson_decode')) {
            return parent::deserialize($data, $headers);
        }
        try {
            return simdjson_decode($data, true, 512);
        } catch (\SimdJsonException $e) {
            throw new JsonException($e->getCode(), $data, $e);
        }
    }
}
