<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The lifetime of every process memo in this module: a memo keeps what it
 * holds while the generation it was built under is the current one.
 *
 * A generation is a random token in the cache under the config cache tag. A
 * cache flush or a config cache clean removes every generation; a save of
 * the data a memo derives from bumps its own generation (see the Bump*
 * plugins). Each request reads a generation once, so a memo costs one cache
 * read per generation per request instead of the lookups it replaces.
 */
class Generation implements ResetAfterRequestInterface
{
    public const CONFIG = 'config';
    public const TAX = 'tax';
    public const CURRENCY = 'currency';

    private const KEY = 'GC_CATALOG_STOREFRONT_GENERATION_';

    /** @var array<string, string> */
    private array $current = [];

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function current(string $name): string
    {
        if (!isset($this->current[$name])) {
            $token = $this->cache->load(self::KEY . $name);
            if (!$token) {
                $token = bin2hex(random_bytes(8));
                $this->cache->save($token, self::KEY . $name, [ConfigCache::CACHE_TAG]);
            }
            $this->current[$name] = (string)$token;
        }

        return $this->current[$name];
    }

    public function bump(string $name): void
    {
        $this->cache->remove(self::KEY . $name);
        unset($this->current[$name]);
    }

    public function _resetState(): void
    {
        $this->current = [];
    }
}
