<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;

/**
 * The product URL rewrites of a listing's cards, from the documents the listing read. The
 * product collection asks for them by product id after its load, with the category id in
 * the metadata when product URLs carry the category path. Each product requires URL rewrite data.
 * A lookup over several store views (the import's, which passes a list) stays with core.
 */
class UrlRewritesFromDocuments
{
    public function __construct(
        private readonly ListingDocuments $listing,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlRewriteFactory $urlRewriteFactory,
        private readonly Mode $mode,
    ) {
    }

    /**
     * @return UrlRewrite[]
     */
    public function aroundFindAllByData(UrlFinderInterface $subject, \Closure $proceed, array $data): array
    {
        $ids = $data[UrlRewrite::ENTITY_ID] ?? null;
        if (($data[UrlRewrite::ENTITY_TYPE] ?? null) !== 'product'
            || !is_array($ids)
            || $ids === []
            || !is_numeric($data[UrlRewrite::STORE_ID] ?? null)
        ) {
            return $proceed($data);
        }

        $storeId = (int)$data[UrlRewrite::STORE_ID];
        if (!$this->mode->listing($storeId)) {
            return $proceed($data);
        }
        $documents = $this->listing->documents((string)$this->storeManager->getStore($storeId)->getCode());
        if ($documents === []) {
            return $proceed($data);
        }
        $categoryId = (string)($data[UrlRewrite::METADATA]['category_id'] ?? '');

        $rewrites = [];
        foreach ($ids as $id) {
            $document = $documents[(int)$id] ?? null;
            if ($document === null || !array_key_exists('urlRewrites', $document)) {
                throw new DocumentReadException('Catalog product requires URL rewrite data: ' . (int)$id);
            }
            foreach ((array)$document['urlRewrites'] as $rewrite) {
                $parameters = array_column((array)($rewrite['parameters'] ?? []), 'value', 'name');
                if ((string)($parameters['category'] ?? '') !== $categoryId) {
                    continue;
                }
                $path = (string)(parse_url((string)($rewrite['url'] ?? ''), PHP_URL_PATH) ?? '');
                $rewrites[] = $this->urlRewriteFactory->create()
                    ->setEntityType('product')
                    ->setEntityId((int)$id)
                    ->setStoreId($storeId)
                    ->setRequestPath(ltrim($path, '/'));
                break;
            }
        }

        return $rewrites;
    }
}
