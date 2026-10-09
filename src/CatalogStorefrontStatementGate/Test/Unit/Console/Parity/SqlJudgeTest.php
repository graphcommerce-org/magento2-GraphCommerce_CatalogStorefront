<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontStatementGate\Test\Unit\Console\Parity;

use GraphCommerce\CatalogStorefrontStatementGate\Console\Parity\SqlJudge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class SqlJudgeTest extends TestCase
{
    public function testALookupFailsAndAWriteIsPrinted(): void
    {
        $output = new BufferedOutput();
        $judge = new SqlJudge();

        self::assertTrue($judge->judge('q', [], ['extensions' => ['catalogStorefront' => ['sql' => ['INSERT INTO search_query' => 1]]]], $output));
        self::assertStringContainsString('WRITE q (document path): 1x INSERT INTO search_query', $output->fetch());

        self::assertFalse($judge->judge('q', [], ['extensions' => ['catalogStorefront' => ['sql' => ['SELECT 1' => 2]]]], $output));
        self::assertStringContainsString('SQL   q (document path): 2 queries', $output->fetch());

        self::assertTrue($judge->judge('q', [], [], $output));
    }

    public function testAQueryWhoseSubjectIsADatabaseEntityListsItsLookupsAndPasses(): void
    {
        $output = new BufferedOutput();
        $judge = new SqlJudge(['30-cart-guest' => 'quote']);
        $response = ['extensions' => ['catalogStorefront' => ['sql' => ['SELECT quote' => 2]]]];

        self::assertTrue($judge->judge('30-cart-guest', [], $response, $output));
        self::assertStringContainsString('SQL   30-cart-guest (document path): 2 queries for the quote', $output->fetch());

        self::assertFalse($judge->judge('13-listing', [], $response, $output));
    }

    public function testAStatementThatProvesTheRequestsCustomerIsPrintedAndPasses(): void
    {
        $output = new BufferedOutput();
        $judge = new SqlJudge([], ['jwt_auth_revoked' => 'the token revocation check']);
        $revoked = 'SELECT `jwt_auth_revoked`.* FROM `jwt_auth_revoked` WHERE (user_id = 1)';

        self::assertTrue($judge->judge('13-listing', [], ['extensions' => ['catalogStorefront' => ['sql' => [$revoked => 1]]]], $output));
        self::assertStringContainsString('AUTH  13-listing (document path): 1x the token revocation check', $output->fetch());

        self::assertFalse($judge->judge(
            '13-listing',
            [],
            ['extensions' => ['catalogStorefront' => ['sql' => [$revoked => 1, 'SELECT `catalog_product_entity`.*' => 1]]]],
            $output
        ));
        self::assertStringContainsString('SQL   13-listing (document path): 1 queries', $output->fetch());
    }
}
