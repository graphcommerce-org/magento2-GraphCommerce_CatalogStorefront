<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage\Data;

/**
 * Storage Entry Iterator implementation for elasticsearch documents.
 */
class DocumentIterator implements EntryIteratorInterface
{
    /**
     * @var array
     */
    private $documents;

    /**
     * @param Document[] $documents
     */
    public function __construct(array $documents)
    {
        $this->documents = $documents;
    }

    /**
     * @inheritdoc
     */
    public function next(): void
    {
        next($this->documents);
    }

    /**
     * @inheritdoc
     */
    public function key(): mixed
    {
        key($this->documents);
    }

    /**
     * @inheritdoc
     */
    public function valid(): bool
    {
        return key($this->documents) !== null;
    }

    /**
     * @inheritdoc
     */
    public function rewind(): void
    {
        reset($this->documents);
    }

    /**
     * @inheritdoc
     */
    #[\ReturnTypeWillChange]
    public function current(): EntryInterface
    {
        return current($this->documents);
    }

    /**
     * @inheritdoc
     */
    public function toArray(bool $sortById = true): array
    {
        $data = [];
        reset($this->documents);
        foreach ($this->documents as $docKey => $doc) {
            $id = $sortById ? $doc->getId() : $docKey;
            $data[$id] = $doc->getData();
        }
        return $data;
    }
}
