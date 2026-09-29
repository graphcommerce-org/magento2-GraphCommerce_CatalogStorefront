<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontDownloadableGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\DownloadableGraphQl\Resolver\Product\Samples;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\UrlInterface;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves downloadable_product_samples from the samples slice of the document.
 * The feed identifies a sample only by its download URL, so the sample id is
 * taken from that URL. Samples are listed by sort order, then title, as the
 * core sample collection orders them.
 */
class DownloadableSamplesFromDocument
{
    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        Samples $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $samples = [];
        foreach ((array)($document['samples'] ?? []) as $sample) {
            if (!preg_match('~/sample_id/(\d+)~', (string)($sample['resource']['url'] ?? ''), $match)) {
                $this->strict->fallback(self::class, 'sample url without sample_id');
            }
            $samples[] = [
                'id' => (int)$match[1],
                'sort_order' => (int)($sample['sortOrder'] ?? 0),
                'title' => $sample['resource']['label'] ?? null,
                'sample_url' => $this->urlBuilder->getUrl('downloadable/download/sample', ['sample_id' => $match[1]]),
            ];
        }
        usort($samples, static fn(array $a, array $b) =>
            [$a['sort_order'], (string)$a['title']] <=> [$b['sort_order'], (string)$b['title']]);

        return $samples;
    }
}
