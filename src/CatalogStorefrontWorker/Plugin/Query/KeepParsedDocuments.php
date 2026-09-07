<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Query;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use GraphQL\Language\AST\DocumentNode;
use Magento\Framework\GraphQl\Query\QueryParser;

/**
 * Keeps the parsed document per query text across requests. Core's parser
 * drops its cache in its state reset, so a repeated query was parsed again
 * (2 ms) and, since the validated set keys on the document object, validated
 * again (5 ms). The documents live under the config generation.
 */
class KeepParsedDocuments
{
    private readonly Memo $documents;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->documents = $memoFactory->create(['name' => Generation::CONFIG, 'limit' => 200]);
    }

    public function aroundParse(QueryParser $subject, \Closure $proceed, string $query): DocumentNode
    {
        return $this->documents->get(sha1($query), static fn(): DocumentNode => $proceed($query));
    }
}
