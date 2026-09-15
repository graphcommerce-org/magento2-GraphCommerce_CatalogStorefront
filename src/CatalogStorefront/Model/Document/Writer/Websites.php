<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;

/**
 * The scopes website feed lands as one website document in the global scope,
 * with the store views of its store groups flattened into one list: the
 * writers of the other feeds fan their rows out over it. A store view carries
 * the media base URL and the image placeholder URLs a product row leaves off.
 */
class Websites implements FeedWriterInterface
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    public function write(array $rows): void
    {
        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            if (!isset($row['websiteId'])) {
                continue;
            }
            if (!empty($row['deleted'])) {
                $deletes[] = (int)$row['websiteId'];
                continue;
            }
            $media = array_column((array)($row['storeViewMedia'] ?? []), null, 'storeViewCode');
            $storeViews = [];
            foreach ((array)($row['stores'] ?? []) as $storeGroup) {
                foreach ((array)($storeGroup['storeViews'] ?? []) as $storeView) {
                    $code = (string)$storeView['storeViewCode'];
                    $storeViews[] = [
                        'code' => $code,
                        'mediaBaseUrl' => (string)($media[$code]['mediaBaseUrl'] ?? ''),
                        'imagePlaceholders' => (array)($media[$code]['imagePlaceholders'] ?? []),
                    ];
                }
            }
            $upserts[(int)$row['websiteId']] = [
                'code' => (string)$row['websiteCode'],
                'storeViews' => $storeViews,
            ];
        }
        $this->storage->upsert(Scopes::WEBSITE, Scopes::SCOPE, $upserts);
        $this->storage->delete(Scopes::WEBSITE, Scopes::SCOPE, $deletes);
    }
}
