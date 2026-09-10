<?php
declare(strict_types=1);

use Composer\InstalledVersions;
use GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Views\Index;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\DerivedViews;
use Magento\Framework\Acl\AclResource\ProviderInterface as AclResourceProviderInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Route\ConfigInterface as RouteConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Result\PageFactory;

const MODULE = 'GraphCommerce_CatalogStorefrontAdminhtml';
const ACL = 'GraphCommerce_CatalogStorefrontAdminhtml::views';
const HANDLE = 'catalog_storefront_views_index';
const COMPONENTS = [
    'catalog_storefront_views_listing',
    'catalog_storefront_sources_listing',
    'catalog_storefront_books_listing',
    'catalog_storefront_layers_listing',
    'catalog_storefront_policies_listing',
];
const PACKAGE = 'graphcommerce/magento-catalog-storefront';

if ((getenv('CATALOG_VIEWS_SMOKE') ?: '') !== 'read-only') {
    fwrite(STDERR, "Refusing to run without CATALOG_VIEWS_SMOKE=read-only\n");
    exit(2);
}

$root = rtrim((string)(getenv('MAGENTO_ROOT') ?: ''), '/');
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Set MAGENTO_ROOT to the deployed Magento root\n");
    exit(2);
}
$expectedPackageReference = trim((string)(getenv('CATALOG_VIEWS_PACKAGE_REFERENCE') ?: ''));

require $root . '/app/bootstrap.php';

$checks = [];
$requireData = (getenv('CATALOG_VIEWS_REQUIRE_DATA') ?: 'strict') === 'strict';
$failure = static function (string $name, string $reason) use (&$checks): void {
    $checks[$name] = ['passed' => false, 'reason' => $reason];
};
$pass = static function (string $name, array $facts = []) use (&$checks): void {
    $checks[$name] = ['passed' => true] + $facts;
};
$containsResource = static function (array $nodes, string $wanted) use (&$containsResource): bool {
    if (($nodes['id'] ?? null) === $wanted) {
        return true;
    }
    foreach ($nodes as $node) {
        if (is_array($node) && $containsResource($node, $wanted)) {
            return true;
        }
    }
    return false;
};

$stage = 'bootstrap';
try {
    $bootstrap = Bootstrap::create($root, $_SERVER);
    $objects = $bootstrap->getObjectManager();
    $stage = 'admin-area';
    $state = $objects->get(State::class);
    try {
        $state->setAreaCode(Area::AREA_ADMINHTML);
    } catch (\Magento\Framework\Exception\LocalizedException) {
        if ($state->getAreaCode() !== Area::AREA_ADMINHTML) {
            throw new RuntimeException('Magento area is already set to a different value.');
        }
    }
    $objects->get(AreaList::class)->getArea(Area::AREA_ADMINHTML)->load(Area::PART_CONFIG);
    $objects->get(DesignInterface::class)->setDesignTheme('Magento/backend', Area::AREA_ADMINHTML);

    $stage = 'package';
    $packageInstalled = InstalledVersions::isInstalled(PACKAGE);
    $packageReference = $packageInstalled
        ? (string)(InstalledVersions::getReference(PACKAGE) ?? '')
        : '';
    if (!$packageInstalled) {
        $failure('package', 'package is absent from Composer installed versions');
    } elseif ($expectedPackageReference !== '' && !hash_equals($expectedPackageReference, $packageReference)) {
        $failure('package', 'installed package reference does not match the required commit');
    } else {
        $pass('package', ['name' => PACKAGE, 'reference' => $packageReference]);
    }

    $stage = 'module';
    /** @var ModuleManager $modules */
    $modules = $objects->get(ModuleManager::class);
    $sourceFile = (new ReflectionClass(DerivedViews::class))->getFileName();
    $sourceSha256 = is_string($sourceFile) && is_file($sourceFile)
        ? hash_file('sha256', $sourceFile)
        : false;
    if ($modules->isEnabled(MODULE) && is_string($sourceSha256)) {
        $pass('module', ['name' => MODULE, 'sourceSha256' => $sourceSha256]);
    } else {
        $failure('module', 'module is not enabled or its source cannot be read');
    }

    $stage = 'route';
    /** @var RouteConfigInterface $routes */
    $routes = $objects->get(RouteConfigInterface::class);
    $frontName = (string)$routes->getRouteFrontName('catalog_storefront', Area::AREA_ADMINHTML);
    $routeModules = $routes->getModulesByFrontName('catalog_storefront', Area::AREA_ADMINHTML);
    if ($frontName === 'catalog_storefront' && in_array(MODULE, $routeModules, true)) {
        $pass('route', ['frontName' => $frontName, 'module' => MODULE]);
    } else {
        $failure('route', 'admin route does not resolve to the Views module');
    }

    $stage = 'controller';
    $interfaces = class_implements(Index::class);
    if (is_array($interfaces)
        && in_array(HttpGetActionInterface::class, $interfaces, true)
        && Index::ADMIN_RESOURCE === ACL
    ) {
        $pass('controller', ['method' => 'GET', 'acl' => ACL]);
    } else {
        $failure('controller', 'controller is not GET-only or names a different ACL');
    }

    $stage = 'acl';
    /** @var AclResourceProviderInterface $aclResources */
    $aclResources = $objects->get(AclResourceProviderInterface::class);
    $containsResource($aclResources->getAclResources(), ACL)
        ? $pass('acl', ['resource' => ACL])
        : $failure('acl', 'resource is missing from the merged ACL tree');

    $stage = 'data';
    /** @var DerivedViews $data */
    $data = $objects->get(DerivedViews::class);
    $views = $data->views();
    $groups = $data->groups();
    $contributions = $data->contributions();
    $viewShapeReady = array_reduce($views, static function (bool $valid, array $view): bool {
        return $valid
            && isset($view['id'], $view['code'], $view['name'], $view['website']['id'], $view['website']['code'])
            && isset($view['store']['id'], $view['store']['code'], $view['store']['rootCategoryId'])
            && array_key_exists('locale', $view)
            && isset($view['currency']['base'], $view['currency']['default'], $view['currency']['allowed'])
            && array_key_exists('indexing', $view)
            && array_key_exists('graphqlDocuments', $view)
            && array_key_exists('productListingDocuments', $view);
    }, true);
    $dataReady = $views !== []
        && $viewShapeReady
        && $groups['available']
        && $groups['items'] !== []
        && $contributions !== [];
    if ($dataReady || !$requireData) {
        $pass('data', [
            'ready' => $dataReady,
            'activeStoreViews' => count($views),
            'customerGroups' => count($groups['items']),
            'catalogLayerContributions' => count($contributions),
        ]);
    } else {
        $checks['data'] = [
            'passed' => false,
            'reason' => 'one or more factual Admin data sources are unavailable',
            'activeStoreViews' => count($views),
            'customerGroupsAvailable' => $groups['available'],
            'catalogLayerContributions' => count($contributions),
        ];
    }

    $stage = 'request';
    /** @var Http $request */
    $request = $objects->get(Http::class);
    $request->setRouteName('catalog_storefront');
    $request->setControllerName('views');
    $request->setActionName('index');
    if ($request->getFullActionName() !== HANDLE) {
        throw new RuntimeException('The emulated request does not resolve to the expected layout handle.');
    }

    $stage = 'page-result';
    /** @var PageFactory $pages */
    $pages = $objects->get(PageFactory::class);
    $page = $pages->create(false, ['isIsolated' => true]);
    $stage = 'layout';
    $layout = $page->getLayout();
    $layout->publicBuild();
    $declarations = $layout->getUpdate()->asSimplexml()->xpath('//uiComponent');
    $declaredNames = is_array($declarations)
        ? array_map(static fn(SimpleXMLElement $component): string => (string)$component['name'], $declarations)
        : [];
    $declaredNames = array_values(array_filter(
        $declaredNames,
        static fn(string $name): bool => str_starts_with($name, 'catalog_storefront_'),
    ));
    if ($declaredNames !== COMPONENTS) {
        $failure('layout', 'merged layout does not contain the exact five overview listings in order');
    } else {
        $pass('layout', [
            'handle' => HANDLE,
            'pageLayout' => $layout->getUpdate()->getPageLayout(),
            'components' => $declaredNames,
        ]);
    }

    $stage = 'render';
    $rendered = [];
    foreach (COMPONENTS as $componentName) {
        $html = $layout->renderElement($componentName);
        if ($html === '') {
            $failure('render', 'one or more native overview listings rendered empty');
            break;
        }
        $rendered[$componentName] = [
            'bytes' => strlen($html),
            'sha256' => hash('sha256', $html),
        ];
    }
    if (count($rendered) === count(COMPONENTS)) {
        $pass('render', ['components' => $rendered]);
    }
} catch (Throwable $error) {
    $failure($stage, get_class($error));
}

$passed = $checks !== [] && !in_array(false, array_column($checks, 'passed'), true);
echo json_encode([
    'schemaVersion' => 1,
    'passed' => $passed,
    'checks' => $checks,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed ? 0 : 1);
