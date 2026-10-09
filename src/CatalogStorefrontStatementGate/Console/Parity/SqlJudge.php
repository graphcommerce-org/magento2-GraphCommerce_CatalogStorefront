<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontStatementGate\Console\Parity;

use GraphCommerce\CatalogStorefrontGraphQlApi\Parity\JudgeInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A document-path query that ran a SQL lookup fails with its statements. A
 * write is printed, not failed: core records a search term's popularity on
 * every search, and the rule forbids lookups.
 *
 * A query whose subject is a database entity is listed under `subjects` in
 * di.xml: its lookups are printed with the subject and do not fail, because
 * core reads that entity from the database on both paths. A profiler trace
 * tells the statements of the subject from the statements of a product
 * field apart.
 *
 * A statement that names a table of `signIn` proves who the request is, not
 * what the catalog holds: a read side without a catalog database still reads
 * it. Such a statement is printed with what it proves and fails no query.
 */
class SqlJudge implements JudgeInterface
{
    /**
     * @param array<string, string> $subjects query name to the entity the query reads
     * @param array<string, string> $signIn table name to what a statement on it proves
     */
    public function __construct(
        private readonly array $subjects = [],
        private readonly array $signIn = [],
    ) {
    }

    public function judge(string $name, array $core, array $documents, OutputInterface $output): bool
    {
        $lookups = [];
        $proven = [];
        foreach ((array)($documents['extensions']['catalogStorefront']['sql'] ?? []) as $statement => $count) {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement)) {
                $output->writeln(sprintf('WRITE %s (document path): %dx %s', $name, $count, substr($statement, 0, 160)));
                continue;
            }
            $proves = null;
            foreach ($this->signIn as $table => $subject) {
                if (str_contains($statement, '`' . $table . '`')) {
                    $proves = $subject;
                }
            }
            if ($proves === null) {
                $lookups[$statement] = $count;
            } else {
                $proven[$proves] = ($proven[$proves] ?? 0) + $count;
            }
        }
        foreach ($proven as $proves => $count) {
            $output->writeln(sprintf('AUTH  %s (document path): %dx %s', $name, $count, $proves));
        }
        if (!$lookups) {
            return true;
        }
        $subject = $this->subjects[$name] ?? null;
        $output->writeln($subject === null
            ? sprintf('SQL   %s (document path): %d queries', $name, array_sum($lookups))
            : sprintf('SQL   %s (document path): %d queries for the %s', $name, array_sum($lookups), $subject));
        foreach (array_slice($lookups, 0, 8, true) as $statement => $count) {
            $output->writeln(sprintf('      %dx %s', $count, $statement));
        }

        return $subject !== null;
    }
}
