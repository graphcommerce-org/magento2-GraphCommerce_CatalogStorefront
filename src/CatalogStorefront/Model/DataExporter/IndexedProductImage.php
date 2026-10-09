<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter;

use Magento\Catalog\Model\Product\Image;

/**
 * Core's product image with every stored image path taken as an existing file. The export computes
 * the resized media path only; a file check on remote storage is a request per image, which made
 * the export of a catalog on S3 wait on the network for most of its time. A path that names no
 * file gets the resized path, where core's GraphQL answers the placeholder.
 */
class IndexedProductImage extends Image
{
    /**
     * @param string $filename
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _fileExists($filename)
    {
        return true;
    }
}
