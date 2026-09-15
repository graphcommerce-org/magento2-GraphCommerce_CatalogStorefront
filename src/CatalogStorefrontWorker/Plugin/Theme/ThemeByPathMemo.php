<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Theme;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Theme\Model\Theme\ThemeProvider;
use Magento\Theme\Model\ThemeFactory;

/**
 * Keeps, under the config generation, which theme a full path names. The
 * path is the area and the theme the store's design configuration resolves,
 * so the memo answers per store view and area.
 *
 * Core caches a theme it finds, and repeats the query for a path the
 * database holds none for: the image URL builder asks for `graphql/_view`
 * on every request that renders a media gallery URL. A known path without a
 * theme answers with the empty theme core answers with.
 */
class ThemeByPathMemo
{
    private readonly Memo $themes;

    public function __construct(
        MemoFactory $memoFactory,
        private readonly ThemeFactory $themeFactory,
    ) {
        $this->themes = $memoFactory->create(['name' => Generation::CONFIG]);
    }

    public function aroundGetThemeByFullPath(ThemeProvider $subject, \Closure $proceed, $fullPath)
    {
        $themeId = $this->themes->get((string)$fullPath, static fn(): int => (int)$proceed($fullPath)->getId());

        return $themeId > 0 ? $proceed($fullPath) : $this->themeFactory->create();
    }
}
