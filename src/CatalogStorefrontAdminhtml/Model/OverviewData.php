<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Model;

/**
 * Adapts factual Magento data to the presentation shape of the Admin design.
 */
final class OverviewData
{
    private const DASH = '—';
    private const NEUTRAL_BORDER = '#C7C7C7';
    private const NEUTRAL_COLOR = '#303030';
    private const NEUTRAL_BACKGROUND = '#F1F1F1';

    /**
     * @param array<int, array<string, mixed>> $views
     * @param array{available: bool, fallback: string, items: array<int, array{id: int, code: string, key: string}>} $groups
     * @param array<string, array{name: string, source: string, fields: string, available: bool}> $contributions
     * @return array<string, mixed>
     */
    public static function build(array $views, array $groups, array $contributions): array
    {
        $groupNames = array_values(array_map(
            static fn(array $group): string => $group['code'],
            $groups['items'],
        ));
        $enabledContributionNames = array_values(array_map(
            static fn(array $contribution): string => $contribution['name'],
            array_filter($contributions, static fn(array $contribution): bool => $contribution['available']),
        ));
        $currencies = [];
        foreach ($views as $view) {
            $currency = trim((string)($view['currency']['base'] ?? ''));
            if ($currency !== '') {
                $currencies[$currency] = true;
            }
        }
        $currency = $currencies === [] ? self::DASH : implode(', ', array_keys($currencies));

        return [
            'catalogViews' => self::catalogViews($views, $groups['available'], $groupNames, $enabledContributionNames),
            'sources' => self::sources($views),
            'books' => self::books($groups, $currency),
            'layers' => self::layers($contributions),
            'policies' => [],
            'viewTip' => ['lines' => [
                ['head' => 'Magento store views', 'text' => 'Each row is derived from an active Magento store view.'],
                ['head' => 'Protection', 'text' => 'Managed catalog-view protection is not available in Magento.'],
            ]],
            'bookTip' => ['lines' => [
                ['head' => 'Fallback prices', 'text' => 'The all price key supplies the shared fallback product price.'],
                ['head' => 'Customer groups', 'text' => 'Each Magento customer group has a derived price key that can override the fallback.'],
            ]],
            'layerTip' => ['lines' => [
                ['head' => 'Magento contributions', 'text' => 'Rows show installed document contributions and the fields they provide.'],
                ['head' => 'Module state', 'text' => 'Enabled means the Magento module is enabled; it is not a document-health check.'],
            ]],
            'policyTip' => ['lines' => [
                ['head' => 'Planned', 'text' => 'Managed catalog policies are not available in Magento.'],
            ]],
            'triggerTip' => ['lines' => [
                ['text' => 'Policy triggers are not available until managed catalog policies are supported.'],
            ]],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $views
     * @param string[] $groupNames
     * @param string[] $contributionNames
     * @return array<int, array<string, mixed>>
     */
    private static function catalogViews(
        array $views,
        bool $groupsAvailable,
        array $groupNames,
        array $contributionNames,
    ): array {
        return array_values(array_map(static function (array $view) use (
            $groupsAvailable,
            $groupNames,
            $contributionNames,
        ): array {
            $code = (string)$view['code'];
            return [
                'name' => (string)$view['name'],
                'id' => $code,
                'protection' => self::DASH,
                'source' => $code,
                'bookMode' => 'Customer group pricing',
                'bookList' => $groupsAvailable && $groupNames !== []
                    ? implode(', ', $groupNames)
                    : self::DASH,
                'policies' => self::DASH,
                'layers' => $contributionNames === []
                    ? self::DASH
                    : implode(', ', $contributionNames),
                'tagBorder' => self::NEUTRAL_BORDER,
                'tagColor' => self::NEUTRAL_COLOR,
                'tagBg' => self::NEUTRAL_BACKGROUND,
                'tip' => ['lines' => [
                    ['head' => 'Catalog source', 'text' => sprintf('Uses Magento store view %s.', $code)],
                    [
                        'head' => 'Assortment',
                        'text' => sprintf(
                            'Website %s; root category %d. Product status and visibility determine eligibility.',
                            (string)($view['website']['code'] ?? self::DASH),
                            (int)($view['store']['rootCategoryId'] ?? 0),
                        ),
                    ],
                ]],
            ];
        }, $views));
    }

    /**
     * @param array<int, array<string, mixed>> $views
     * @return array<int, array<string, mixed>>
     */
    private static function sources(array $views): array
    {
        return array_values(array_map(static fn(array $view): array => [
            'code' => (string)$view['code'],
            'type' => 'Magento Store View Catalog',
            'origin' => sprintf('Store view %s (ID %d)', (string)$view['code'], (int)$view['id']),
            'locale' => trim((string)($view['locale'] ?? '')) ?: self::DASH,
            'feedProducts' => self::unavailableFeed(),
            'feedCategories' => self::unavailableFeed(),
            'feedAttributes' => self::unavailableFeed(),
        ], $views));
    }

    /**
     * @param array{available: bool, fallback: string, items: array<int, array{id: int, code: string, key: string}>} $groups
     * @return array<int, array<string, mixed>>
     */
    private static function books(array $groups, string $currency): array
    {
        $books = [[
            'id' => $groups['fallback'],
            'name' => 'Magento Base Prices',
            'type' => 'Magento Fallback',
            'currency' => $currency,
            'rows' => self::DASH,
            'depth' => 0,
            'role' => 'Fallback',
            'currencyNote' => '',
            'indent' => '0px',
            'glyph' => '■',
            'feedPrices' => self::unavailableFeed(),
        ]];
        if (!$groups['available']) {
            return $books;
        }
        foreach ($groups['items'] as $group) {
            $books[] = [
                'id' => $group['key'],
                'name' => $group['code'],
                'type' => 'Magento Customer Group',
                'currency' => $currency,
                'rows' => self::DASH,
                'depth' => 1,
                'role' => 'Child',
                'currencyNote' => '',
                'indent' => '12px',
                'glyph' => '└',
                'feedPrices' => self::unavailableFeed(),
            ];
        }
        return $books;
    }

    /**
     * @param array<string, array{name: string, source: string, fields: string, available: bool}> $contributions
     * @return array<int, array<string, mixed>>
     */
    private static function layers(array $contributions): array
    {
        $layers = [];
        foreach ($contributions as $contribution) {
            $layers[] = [
                'name' => $contribution['name'],
                'order' => self::DASH,
                'locale' => 'All locales',
                'managedBy' => $contribution['source'],
                'records' => self::DASH,
                'groups' => [[
                    'kind' => 'FIELDS',
                    'fields' => $contribution['fields'],
                    'label' => $contribution['fields'],
                ]],
                'feedRecords' => self::unavailableFeed(),
                'status' => $contribution['available'] ? 'MODULE ENABLED' : 'MODULE UNAVAILABLE',
                'statusBg' => $contribution['available'] ? self::NEUTRAL_BACKGROUND : '#FFFFFF',
                'statusBorder' => self::NEUTRAL_BORDER,
            ];
        }
        return $layers;
    }

    /** @return array{value: string, hasBadge: false, badgeLabel: string, badgeBg: string, badgeBorder: string, badgeFg: string} */
    private static function unavailableFeed(): array
    {
        return [
            'value' => self::DASH,
            'hasBadge' => false,
            'badgeLabel' => '',
            'badgeBg' => self::NEUTRAL_BACKGROUND,
            'badgeBorder' => 'transparent',
            'badgeFg' => self::NEUTRAL_COLOR,
        ];
    }
}
