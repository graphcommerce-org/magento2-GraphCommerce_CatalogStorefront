<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontExplorer\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Mode;

/**
 * The Catalog switcher of the API explorer: Default sends no header, the
 * other options send the path with the storefront key, so the explorer's
 * requests pick their path and carry the fallback report.
 */
class Switcher implements \JsonSerializable
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function jsonSerialize(): array
    {
        $key = $this->config->key();
        $option = static fn(string $label, string $mode) => [
            'label' => $label,
            'headers' => [Mode::HEADER => $mode, StorefrontKey::HEADER => $key],
        ];

        return [
            'label' => 'Catalog',
            'header' => Mode::HEADER,
            'options' => [
                '' => 'Default',
                Mode::DOCUMENTS => $option('Documents', Mode::DOCUMENTS),
                Mode::CORE => $option('Database', Mode::CORE),
            ],
        ];
    }
}
