<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Plugin;

use GraphCommerce\CatalogStorefrontOpenSearch\Model\Serializer;
use Magento\AdvancedSearch\Model\Client\ClientOptionsInterface;

/**
 * Every search client core builds decodes its responses through the simdjson serializer:
 * the client builder takes a serializer as a config key next to the hosts.
 */
class ClientSerializer
{
    public function __construct(
        private readonly Serializer $serializer,
    ) {
    }

    public function afterPrepareClientOptions(ClientOptionsInterface $subject, array $options): array
    {
        return $options + ['serializer' => $this->serializer];
    }
}
