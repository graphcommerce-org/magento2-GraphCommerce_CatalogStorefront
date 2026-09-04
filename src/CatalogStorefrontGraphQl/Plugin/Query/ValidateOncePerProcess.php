<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query;

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
 * The parser already caches the parsed document per query text, so the same
 * document object comes back for a repeated query. A document that produced
 * an error-free response was valid; later executions of it skip validation
 * by passing an empty rule set, the fast path webonyx provides. Validation
 * is the only step skipped: complexity limits apply on the first execution,
 * resolvers and error handling run as always.
 */
class ValidateOncePerProcess
{
    private \WeakMap $validated;

    public function __construct(
        private readonly QueryParser $queryParser,
        private readonly ExceptionFormatter $exceptionFormatter,
        private readonly ErrorHandlerInterface $errorHandler,
        private readonly QueryDataFormatter $formatter,
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
        if (!isset($this->validated[$document])) {
            $result = $proceed($schema, $document, $contextValue, $variableValues, $operationName);
            if (!isset($result['errors'])) {
                $this->validated[$document] = true;
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
