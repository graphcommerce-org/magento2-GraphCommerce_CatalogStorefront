<?php
declare(strict_types=1);

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Parity\Picks;
use Magento\Framework\App\Bootstrap;
use Magento\Store\Model\StoreManagerInterface;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$stores = $objectManager->get(StoreManagerInterface::class);
$store = $stores->getDefaultStoreView();
$stores->setCurrentStore($store);
$config = $objectManager->get(Config::class);
if (!$config->serveGraphQl() || $config->key() === '') {
    throw new RuntimeException('Enable Serve GraphQL and configure the storefront key.');
}
$picks = $objectManager->get(Picks::class);
$sku = $picks->skus('any', 1)[0] ?? null;
$category = $picks->categoryId('any');
if ($sku === null || $category === null) {
    throw new RuntimeException('The HTTP cache gate requires a visible product and category.');
}
$endpoint = $argv[1] ?? 'http://127.0.0.1:8080/graphql';
$queries = [
    'products' => '{products(filter:{sku:{eq:' . json_encode($sku) . '}}){items{sku}}}',
    'categories' => '{categories(filters:{ids:{eq:"' . $category . '"}}){items{uid}}}',
];
foreach ($queries as $name => $query) {
    $reference = null;
    foreach (['public' => null, 'keyed documents' => Mode::DOCUMENTS, 'keyed core' => Mode::CORE] as $context => $mode) {
        foreach (['GET', 'POST'] as $method) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $headers = [];
                $payload = ['query' => $query];
                $curl = curl_init($endpoint . ($method === 'GET' ? '?' . http_build_query($payload) : ''));
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => !in_array('--insecure', $argv, true),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Store: ' . $store->getCode(),
                        ...($mode === null ? [] : [StorefrontKey::HEADER . ': ' . $config->key(), Mode::HEADER . ': ' . $mode]),
                    ],
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                        if (str_contains($line, ':')) {
                            [$key, $value] = explode(':', $line, 2);
                            $headers[strtolower(trim($key))] = trim($value);
                        }
                        return strlen($line);
                    },
                ]);
                if ($method === 'POST') {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
                }
                $body = curl_exec($curl);
                $response = is_string($body) ? json_decode($body, true) : null;
                $label = "$name $context $method $attempt";
                if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 || !empty($response['errors']) || empty($response['data'][$name]['items'])) {
                    throw new RuntimeException("$label: expected a successful catalog response.");
                }
                foreach ([$headers['cache-control'] ?? '', $headers['x-magento-cache-control'] ?? $headers['cache-control'] ?? ''] as $control) {
                    if (!str_contains($control, 'no-store') || !str_contains($control, 'max-age=0')) {
                        throw new RuntimeException("$label: response permits HTTP caching ($control).");
                    }
                }
                if (($headers['x-magento-cache-debug'] ?? '') === 'HIT' || (int)($headers['age'] ?? 0) > 0) {
                    throw new RuntimeException("$label: response came from the HTTP cache.");
                }
                if ($mode !== null && ($response['extensions']['catalogStorefront']['mode'] ?? null) !== $mode) {
                    throw new RuntimeException("$label: response uses the wrong catalog mode.");
                }
                $reference ??= $response['data'];
                if ($response['data'] !== $reference) {
                    throw new RuntimeException("$label: catalog responses differ.");
                }
                echo "PASS $label\n";
            }
        }
    }
}
