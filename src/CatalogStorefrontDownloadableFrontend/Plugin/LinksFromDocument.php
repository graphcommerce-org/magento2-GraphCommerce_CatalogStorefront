<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontDownloadableFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\Downloadable\Model\Link;
use Magento\Downloadable\Model\LinkFactory;
use Magento\Downloadable\Model\Product\Type;
use Magento\Downloadable\Model\SampleFactory;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\CollectionFactory;
use Magento\Framework\GraphQl\Query\Uid;

/**
 * The links and samples of a downloadable product that carries a document, from its `optionsV2`
 * downloadable values and its `samples`, in the memo keys core's type reads first. A link has a
 * sample when the feed gives it an `infoUrl`.
 */
class LinksFromDocument
{
    public function __construct(
        private readonly LinkFactory $linkFactory,
        private readonly SampleFactory $sampleFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly Uid $uid,
    ) {
    }

    /**
     * @param Type $subject
     * @param \Closure $proceed
     * @param Product $product
     * @return Link[]
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetLinks(Type $subject, \Closure $proceed, $product)
    {
        $document = $product->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        if (!is_array($document) || $product->getDownloadableLinks() !== null) {
            return $proceed($product);
        }

        $values = [];
        foreach ((array)($document['optionsV2'] ?? []) as $option) {
            if (($option['type'] ?? null) === 'downloadable') {
                array_push($values, ...(array)($option['values'] ?? []));
            }
        }

        $links = [];
        foreach ($values as $value) {
            $parts = explode('/', (string)$this->uid->decode((string)($value['id'] ?? '')));
            if (count($parts) !== 2 || $parts[0] !== 'downloadable' || !ctype_digit($parts[1])) {
                throw new DocumentReadException('Catalog downloadable link UID is invalid: ' . ($value['id'] ?? ''));
            }
            $link = $this->linkFactory->create()->setData([
                'link_id' => $parts[1],
                'product_id' => (string)$product->getId(),
                'sort_order' => (string)($value['sortOrder'] ?? 0),
                'title' => $value['label'] ?? null,
                'price' => (string)($value['price'] ?? 0),
                'number_of_downloads' => (string)($value['qty'] ?? 0),
                'sample_url' => $value['infoUrl'] ?? null,
            ]);
            $link->setProduct($product);
            $links[$parts[1]] = $link;
        }
        uasort($links, static fn(Link $a, Link $b) =>
            [(int)$a->getSortOrder(), (int)$a->getId()] <=> [(int)$b->getSortOrder(), (int)$b->getId()]);
        $product->setDownloadableLinks($links);

        return $links;
    }

    /**
     * @param Type $subject
     * @param \Closure $proceed
     * @param Product $product
     * @return Collection
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetSamples(Type $subject, \Closure $proceed, $product)
    {
        $document = $product->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        if (!is_array($document) || $product->getDownloadableSamples() !== null) {
            return $proceed($product);
        }

        $samples = [];
        foreach ((array)($document['samples'] ?? []) as $sample) {
            if (!preg_match('~/sample_id/(\d+)~', (string)($sample['resource']['url'] ?? ''), $match)) {
                throw new DocumentReadException('Catalog downloadable sample URL names no sample: ' . $product->getId());
            }
            $samples[] = $this->sampleFactory->create()->setData([
                'sample_id' => $match[1],
                'product_id' => (string)$product->getId(),
                'sort_order' => (string)($sample['sortOrder'] ?? 0),
                'title' => $sample['resource']['label'] ?? null,
            ]);
        }
        usort($samples, static fn($a, $b) =>
            [(int)$a->getSortOrder(), (int)$a->getId()] <=> [(int)$b->getSortOrder(), (int)$b->getId()]);

        $collection = $this->collectionFactory->create();
        foreach ($samples as $sample) {
            $collection->addItem($sample);
        }
        $product->setDownloadableSamples($collection);

        return $collection;
    }
}
