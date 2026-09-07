<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Query;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\PageSizeLimit;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use PHPUnit\Framework\TestCase;

class PageSizeLimitTest extends TestCase
{
    public function testAPageOverTheLimitIsRefused(): void
    {
        $limit = new PageSizeLimit();
        $field = $this->createMock(Field::class);
        $limit->validate($field, ['pageSize' => 2000]);
        $limit->validate($field, ['currentPage' => 3]);

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Maximum pageSize is 2000');
        $limit->validate($field, ['pageSize' => 2001]);
    }
}
