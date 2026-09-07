<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Query;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;
use Magento\Framework\GraphQl\Exception\ExceptionFormatter;
use Magento\Framework\GraphQl\Query\ErrorHandlerInterface;
use Magento\Framework\GraphQl\Query\QueryDataFormatter;
use Magento\Framework\GraphQl\Query\QueryParser;
use Magento\Framework\GraphQl\Query\QueryProcessor;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Validates a query document against the schema once per process.
 *
 * KeepParsedDocuments returns the same document object for a repeated query
 * text across requests, which the memo below keys on. A document that produced
 * an error-free response was valid under the config generation of that
 * moment; later executions under the same generation skip validation by
 * passing an empty rule set, the fast path webonyx provides. Validation is
 * the only step skipped: complexity limits apply on the first execution,
 * resolvers and error handling run as always.
 */
class ValidateOncePerProcess
{
    /** @var \WeakMap<DocumentNode, string> the config generation each document was validated under */
    private \WeakMap $validated;

    public function __construct(
        private readonly QueryParser $queryParser,
        private readonly ExceptionFormatter $exceptionFormatter,
        private readonly ErrorHandlerInterface $errorHandler,
        private readonly QueryDataFormatter $formatter,
        private readonly Generation $generations,
    ) {
        $this->validated = new \WeakMap();
    }

    public function aroundProcess(
        QueryProcessor $subject,
        \Closure $proceed,
        Schema $schema,
        DocumentNode|string $source,
        ?ContextInterface $contextValue = null,
        ?array $variableValues = null,
        ?string $operationName = null
    ): array {
        $document = is_string($source) ? $this->queryParser->parse($source) : $source;
        $generation = $this->generations->current(Generation::CONFIG);
        if (($this->validated[$document] ?? null) !== $generation) {
            $result = $proceed($schema, $document, $contextValue, $variableValues, $operationName);
            if (!isset($result['errors'])) {
                $this->validated[$document] = $generation;
            }

            return $result;
        }

        $executionResult = GraphQL::executeQuery(
            $schema,
            $document,
            null,
            $contextValue,
            $variableValues,
            $operationName,
            null,
            []
        )->setErrorsHandler(
            [$this->errorHandler, 'handle']
        )->toArray(
            (int)($this->exceptionFormatter->shouldShowDetail() ? \GraphQL\Error\DebugFlag::INCLUDE_DEBUG_MESSAGE : false)
        );

        return $this->formatter->formatResponse($executionResult);
    }
}
