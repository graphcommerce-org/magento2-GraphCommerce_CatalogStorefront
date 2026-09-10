<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin;

use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Framework\App\PageCache\Identifier;

/**
 * Keeps a page requested on a chosen path out of the ordinary page's cache slot.
 *
 * A request carrying the storefront key can ask for the core path or the document
 * path. Without this, the first such request would store its page under the id
 * every visitor reads, and a gate run would serve its own output to the shop.
 */
class PageCacheIdentifier
{
    public function __construct(
        private readonly Mode $mode,
    ) {
    }

    /**
     * @param Identifier $subject
     * @param string $result
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetValue(Identifier $subject, $result)
    {
        $requested = $this->mode->requested();

        return $requested === null ? $result : $result . '|' . Mode::HEADER . '=' . $requested;
    }
}
