<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefront\Model\Strict;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\UrlRewriteGraphQl\Model\Resolver\UrlRewrite;

/**
 * Serves url_rewrites from the feed document, skipping the per-product url
 * finder query. The document carries absolute URLs, so the host is stripped
 * back to the request path the core resolver returns.
 */
class UrlRewritesFromDocument
{
    public function __construct(
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        UrlRewrite $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        $product = $value['model'] ?? null;
        $document = $product?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($field, $context, $info, $value, $args);
        }
        if (!array_key_exists('urlRewrites', $document)) {
            $this->strict->fallback(self::class, 'document without urlRewrites');

            return $proceed($field, $context, $info, $value, $args);
        }

        $urlRewrites = [];
        foreach ($document['urlRewrites'] ?? [] as $rewrite) {
            $urlRewrites[] = [
                'url' => $this->toRequestPath($rewrite['url'] ?? ''),
                'parameters' => array_map(
                    static fn(array $parameter) => [
                        'name' => $parameter['name'] ?? '',
                        'value' => $parameter['value'] ?? '',
                    ],
                    $rewrite['parameters'] ?? []
                ),
            ];
        }

        return $urlRewrites;
    }

    private function toRequestPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? $url;

        return ltrim((string)$path, '/');
    }
}
