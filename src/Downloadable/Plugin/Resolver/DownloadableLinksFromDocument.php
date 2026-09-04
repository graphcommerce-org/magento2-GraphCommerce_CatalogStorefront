<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontDownloadable\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontApi\Read\HydrationInterface;
use Magento\DownloadableGraphQl\Resolver\Product\Links;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\UrlInterface;

/**
 * Serves downloadable_product_links from the downloadable option of the
 * document. The feed value id is the core link uid ("downloadable/<link id>"),
 * so the link id comes from decoding it. Links are listed by sort order, then
 * title, as the core link collection orders them.
 */
class DownloadableLinksFromDocument
{
    public function __construct(
        private readonly Uid $uidEncoder,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    public function aroundResolve(
        Links $subject,
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

        $links = [];
        foreach ((array)($document['optionsV2'] ?? []) as $option) {
            if (($option['type'] ?? null) !== 'downloadable') {
                continue;
            }
            foreach ((array)($option['values'] ?? []) as $optionValue) {
                [, $id] = explode('/', $this->uidEncoder->decode((string)$optionValue['id']));
                $links[] = [
                    'id' => (int)$id,
                    'sort_order' => (int)($optionValue['sortOrder'] ?? 0),
                    'title' => $optionValue['label'] ?? null,
                    'sample_url' => $this->urlBuilder->getUrl('downloadable/download/linkSample', ['link_id' => $id]),
                    'price' => (float)($optionValue['price'] ?? 0),
                ];
            }
        }
        usort($links, static fn(array $a, array $b) =>
            [$a['sort_order'], (string)$a['title']] <=> [$b['sort_order'], (string)$b['title']]);

        return $links;
    }
}
