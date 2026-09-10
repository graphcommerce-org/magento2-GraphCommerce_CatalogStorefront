<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductListing\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontProductListing\Model\Mode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Console\Request as ConsoleRequest;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;

class ModeTest extends TestCase
{
    private const KEY = 'a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5';
    private const STORE_ID = 1;

    private HttpRequest $request;

    /** @var array<string, string> the headers the request currently carries */
    private array $headers = [];

    /**
     * The request is built once and its headers changed per call, because that is how a worker
     * sees them: one long-lived object, different values each request.
     */
    private function mode(bool $servePlp, string $configuredKey = self::KEY): Mode
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getHeader')->willReturnCallback(
            fn(string $name) => $this->headers[$name] ?? false
        );

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($servePlp);

        $config = $this->createMock(Config::class);
        $config->method('key')->willReturn($configuredKey);

        return new Mode($scopeConfig, $config, $this->request);
    }

    private function send(string $path, string $key = self::KEY): void
    {
        $this->headers = [Mode::HEADER => $path, StorefrontKey::HEADER => $key];
    }

    public function testTheStoreSettingDecidesWithoutAHeader(): void
    {
        $this->assertTrue($this->mode(true)->documents(self::STORE_ID));
        $this->assertFalse($this->mode(false)->documents(self::STORE_ID));
    }

    public function testDocumentsHeaderOverridesTheStoreSetting(): void
    {
        $mode = $this->mode(false);
        $this->send(Mode::DOCUMENTS);

        $this->assertTrue($mode->documents(self::STORE_ID));
    }

    public function testCoreHeaderOverridesTheStoreSetting(): void
    {
        $mode = $this->mode(true);
        $this->send(Mode::CORE);

        $this->assertFalse($mode->documents(self::STORE_ID));
    }

    public function testHeaderIsIgnoredWithoutTheKey(): void
    {
        $mode = $this->mode(false);
        $this->headers = [Mode::HEADER => Mode::DOCUMENTS];

        $this->assertFalse($mode->documents(self::STORE_ID));
        $this->assertNull($mode->requested());
    }

    public function testHeaderIsIgnoredWhenTheKeyDoesNotMatch(): void
    {
        $mode = $this->mode(false);
        $this->send(Mode::DOCUMENTS, 'not-the-key');

        $this->assertFalse($mode->documents(self::STORE_ID));
    }

    public function testHeaderIsIgnoredWhenNoKeyIsConfigured(): void
    {
        // An unconfigured key must not mean "anything matches".
        $mode = $this->mode(false, '');
        $this->send(Mode::DOCUMENTS, '');

        $this->assertFalse($mode->documents(self::STORE_ID));
    }

    public function testAnUnknownHeaderValueFallsBackToTheSetting(): void
    {
        $mode = $this->mode(true);
        $this->send('nonsense');

        $this->assertTrue($mode->documents(self::STORE_ID));
        $this->assertNull($mode->requested());
    }

    public function testTheHeaderIsCaseInsensitive(): void
    {
        $mode = $this->mode(false);
        $this->send('DOCUMENTS');

        $this->assertTrue($mode->documents(self::STORE_ID));
    }

    public function testAConsoleRequestNeverReadsHeaders(): void
    {
        // A listing collection can be loaded from the CLI, where the request has no headers at all.
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $config = $this->createMock(Config::class);
        $config->method('key')->willReturn(self::KEY);

        $mode = new Mode($scopeConfig, $config, $this->createMock(ConsoleRequest::class));

        $this->assertNull($mode->requested());
        $this->assertFalse($mode->documents(self::STORE_ID));
    }

    public function testRequestedNamesThePathForThePageCache(): void
    {
        $mode = $this->mode(false);

        $this->send(Mode::DOCUMENTS);
        $this->assertSame(Mode::DOCUMENTS, $mode->requested());

        $this->send(Mode::CORE);
        $this->assertSame(Mode::CORE, $mode->requested());
    }

    /**
     * The regression this class exists to avoid.
     *
     * An earlier version memoised the answer per store id. In a FrankenPHP worker this object
     * outlives the request, so whatever the first listing request decided bound every later one:
     * the store setting appeared to work and the header never did. Nothing here may be cached.
     */
    public function testTwoRequestsInOneProcessDecideIndependently(): void
    {
        $mode = $this->mode(false);

        $this->send(Mode::DOCUMENTS);
        $this->assertTrue($mode->documents(self::STORE_ID), 'first request asked for documents');

        $this->headers = [];
        $this->assertFalse($mode->documents(self::STORE_ID), 'second request asked for nothing');

        $this->send(Mode::DOCUMENTS);
        $this->assertTrue($mode->documents(self::STORE_ID), 'third request asked for documents again');
    }
}
