<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQlApi\Parity;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * One more verdict of the parity gate on a query, next to the response diff
 * (di.xml `judges` on the parity command): it sees both decoded responses,
 * prints what it finds and says whether the query passes.
 */
interface JudgeInterface
{
    public function judge(string $name, array $core, array $documents, OutputInterface $output): bool;
}
