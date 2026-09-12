<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontAdminhtml\Ui\Component;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/** Localized labels belong to Admin presentation; stored locale codes remain unchanged. */
class LocaleName extends Column
{
    public function __construct(ContextInterface $context, UiComponentFactory $uiComponentFactory, private readonly ResolverInterface $locale, array $components = [], array $data = [])
    {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }
    public function prepareDataSource(array $dataSource): array
    {
        foreach ($dataSource['data']['items'] ?? [] as $key => $row) {
            $value = $row[$this->getData('name')] ?? '';
            if (is_string($value) && preg_match('/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,3}$/D', $value)) {
                $dataSource['data']['items'][$key][$this->getData('name')] = \Locale::getDisplayName($value, $this->locale->getLocale());
            }
        }
        return $dataSource;
    }
}
