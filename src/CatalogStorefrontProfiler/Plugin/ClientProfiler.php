<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProfiler\Plugin;

use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client;
use MageOS\Profiler\Model\Instrumentation\Guard;
use MageOS\Profiler\Model\Instrumentation\QueryCapture;
use MageOS\Profiler\Model\Instrumentation\Settings;
use MageOS\Profiler\Model\Instrumentation\Timer;
use MageOS\Profiler\Model\Instrumentation\TimerId;
use MageOS\Profiler\Model\Profiler\Driver\Timeline;

/**
 * Times every call of the document store client as `OPENSEARCH:<operation> (<index>)`, next to the rows
 * MageOS_Profiler records for Magento's own search client. With MAGE_PROFILER_SEARCH=query the request
 * body of a read rides the span, like a captured SQL statement.
 */
class ClientProfiler
{
    private const PREFIX = 'OPENSEARCH';

    public function __construct(
        private readonly Guard $guard,
        private readonly Timer $timer,
        private readonly TimerId $timerId,
        private readonly Settings $settings,
        private readonly QueryCapture $capture,
    ) {
    }

    public function aroundGet(Client $subject, callable $proceed, string $index, array $ids, array $fields): array
    {
        return $this->measure('mget', $index, count($ids), ['ids' => array_values($ids), 'fields' => $fields], fn() => $proceed($index, $ids, $fields));
    }

    public function aroundSearch(Client $subject, callable $proceed, string $index, array $body): array
    {
        return $this->measure('search', $index, null, $body, fn() => $proceed($index, $body));
    }

    public function aroundMultiSearch(Client $subject, callable $proceed, array $searches): array
    {
        $indices = implode('+', array_unique(array_column($searches, 0)));

        return $this->measure('msearch', $indices, count($searches), array_column($searches, 1), fn() => $proceed($searches));
    }

    public function aroundUpsert(Client $subject, callable $proceed, string $index, array $documents): void
    {
        $this->measure('bulk:upsert', $index, count($documents), null, fn() => $proceed($index, $documents));
    }

    public function aroundDelete(Client $subject, callable $proceed, string $index, array $ids): void
    {
        $this->measure('bulk:delete', $index, count($ids), null, fn() => $proceed($index, $ids));
    }

    public function aroundIndexExists(Client $subject, callable $proceed, string $index): bool
    {
        return $this->measure('indexExists', $index, null, null, fn() => $proceed($index));
    }

    public function aroundCreateIndex(Client $subject, callable $proceed, string $index, array $mapping, array $aliases = []): void
    {
        $this->measure('createIndex', $index, null, null, fn() => $proceed($index, $mapping, $aliases));
    }

    public function aroundDeleteIndex(Client $subject, callable $proceed, string $index): void
    {
        $this->measure('deleteIndex', $index, null, null, fn() => $proceed($index));
    }

    /**
     * A count in the detail is snapped to a power of ten (x10, x100), so batch sizes stay a few ids.
     */
    private function measure(string $operation, string $index, ?int $count, ?array $body, callable $call): mixed
    {
        if (!$this->guard->isActive(Settings::AREA_SEARCH)) {
            return $call();
        }
        $detail = $this->timerId->indexName($index) ?? $index;
        if ($count !== null) {
            $detail .= ' x' . ($count < 10 ? $count : 10 ** (int)floor(log10($count)));
        }
        $captures = $body !== null
            && Timeline::isRecording()
            && strtolower($this->settings->getString('MAGE_PROFILER_' . Settings::AREA_SEARCH)) === 'query';

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, $operation, $detail),
            $call,
            $captures ? $this->capture->captureSearch($body) : null,
        );
    }
}
