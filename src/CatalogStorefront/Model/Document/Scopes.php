<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The store views of every website and the customer groups, as the scopes
 * feeds put them in the document store. A writer fans its rows out over them
 * instead of asking the store manager or the group repository, so the write
 * side needs no Magento state at all. The documents are global: one index per
 * entity under the scope name.
 */
class Scopes implements ResetAfterRequestInterface
{
    public const WEBSITE = 'website';

    public const CUSTOMER_GROUP = 'customer_group';

    /** The store view name the global scope indices carry. */
    public const SCOPE = 'global';

    /** The feed exports the groups from id 1, so the not logged in group is added. */
    private const NOT_LOGGED_IN_ID = 0;

    /** @var array<string, string[]>|null store view codes by website code */
    private ?array $websites = null;

    /** @var array<string, int>|null group id by the code the feeds name the group with */
    private ?array $customerGroups = null;

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    /**
     * @return string[] every store view code
     */
    public function storeViews(): array
    {
        return array_values(array_unique(array_merge([], ...array_values($this->websites()))));
    }

    /**
     * @return string[] the store view codes of one website
     */
    public function storeViewsOfWebsite(string $websiteCode): array
    {
        return $this->websites()[$websiteCode] ?? [];
    }

    /**
     * The not logged in group first, then the others by name, the order core's
     * group management yields and the price index keeps.
     *
     * @return array<string, int> group id by the code a feed row names the group with
     */
    public function customerGroups(): array
    {
        if ($this->customerGroups === null) {
            $documents = $this->storage->all(self::CUSTOMER_GROUP, self::SCOPE);
            if (!$documents) {
                throw new \RuntimeException(
                    'No customer group documents: export the scopesCustomerGroup feed '
                    . '(bin/magento indexer:reindex scopes_customergroup_data_exporter).'
                );
            }
            usort($documents, static fn(array $a, array $b) =>
                (string)($a['name'] ?? '') <=> (string)($b['name'] ?? ''));
            $this->customerGroups = [sha1((string)self::NOT_LOGGED_IN_ID) => self::NOT_LOGGED_IN_ID];
            foreach ($documents as $document) {
                $this->customerGroups[(string)$document['code']] = (int)$document['id'];
            }
        }

        return $this->customerGroups;
    }

    public function _resetState(): void
    {
        $this->websites = null;
        $this->customerGroups = null;
    }

    /**
     * @return array<string, string[]>
     */
    private function websites(): array
    {
        if ($this->websites === null) {
            $documents = $this->storage->all(self::WEBSITE, self::SCOPE);
            if (!$documents) {
                throw new \RuntimeException(
                    'No website documents: export the scopesWebsite feed '
                    . '(bin/magento indexer:reindex scopes_website_data_exporter).'
                );
            }
            $this->websites = [];
            foreach ($documents as $document) {
                $this->websites[(string)$document['code']] = array_map('strval', (array)($document['storeViews'] ?? []));
            }
        }

        return $this->websites;
    }
}
