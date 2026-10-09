<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontStatementGate\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontStatementGate\Model\Statements;
use PHPUnit\Framework\TestCase;

class StatementsTest extends TestCase
{
    public function testCountsNormalizedStatementsMostFrequentFirst(): void
    {
        $statements = new Statements();
        $statements->record('SELECT * FROM b');
        $statements->record("SELECT  *\n  FROM a");
        $statements->record('SELECT * FROM a');

        self::assertSame(['SELECT * FROM a' => 2, 'SELECT * FROM b' => 1], $statements->counts());

        $statements->_resetState();
        self::assertSame([], $statements->counts());
    }
}
