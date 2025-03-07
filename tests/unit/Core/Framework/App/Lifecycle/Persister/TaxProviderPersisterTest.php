<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\App\Lifecycle\Persister;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\App\Lifecycle\Persister\TaxProviderPersister;
use Shopware\Core\Framework\App\Manifest\Manifest;
use Shopware\Core\Framework\App\Manifest\Xml\Metadata;
use Shopware\Core\Framework\App\Manifest\Xml\Tax;
use Shopware\Core\Framework\App\Manifest\Xml\TaxProvider;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Test\IdsCollection;
use Shopware\Core\System\TaxProvider\TaxProviderCollection;
use Shopware\Core\System\TaxProvider\TaxProviderDefinition;
use Shopware\Core\System\TaxProvider\TaxProviderEntity;

/**
 * @package checkout
 *
 * @internal
 *
 * @covers \Shopware\Core\Framework\App\Lifecycle\Persister\TaxProviderPersister
 */
class TaxProviderPersisterTest extends TestCase
{
    private const META_APP_NAME = 'testApp';

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
    }

    public function testCreateNewTaxProvider(): void
    {
        $provider = $this->createTaxProviders([
            [
                'identifier' => 'test',
                'name' => 'lol',
                'processUrl' => 'https://example.com',
                'priority' => '1',
            ],
        ]);

        $manifest = $this->createManifest($provider);
        $existing = $this->existingProviders();

        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects(static::once())
            ->method('search')
            ->willReturn($existing);
        $repo
            ->expects(static::once())
            ->method('upsert')
            ->with(
                [[
                    'identifier' => 'app\\testApp_test',
                    'name' => 'lol',
                    'processUrl' => 'https://example.com',
                    'priority' => 1,
                    'appId' => 'foo',
                ]],
                static::isInstanceOf(Context::class),
            );

        $persister = new TaxProviderPersister($repo);
        $persister->updateTaxProviders($manifest, 'foo', 'testApp', Context::createDefaultContext());
    }

    public function testCreateNewTaxProviderExisting(): void
    {
        $provider = $this->createTaxProviders([
            [
                'identifier' => 'test',
                'name' => 'lol',
                'processUrl' => 'https://example.com',
                'priority' => '1',
            ],
        ]);

        $manifest = $this->createManifest($provider);
        $existing = $this->existingProviders($provider, 'foo', 'testApp');

        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects(static::once())
            ->method('search')
            ->willReturn($existing);
        $repo
            ->expects(static::once())
            ->method('upsert')
            ->with(
                [[
                    'identifier' => 'app\\testApp_test',
                    'name' => 'lol',
                    'processUrl' => 'https://example.com',
                    'id' => $this->ids->get('tax-provider-test'),
                    'appId' => 'foo',
                    'priority' => 1,
                ]],
                static::isInstanceOf(Context::class),
            );

        $persister = new TaxProviderPersister($repo);
        $persister->updateTaxProviders($manifest, 'foo', 'testApp', Context::createDefaultContext());
    }

    public function testNoTaxInManifest(): void
    {
        $manifest = $this->createMock(Manifest::class);
        $manifest
            ->method('getTax')
            ->willReturn(null);

        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects(static::never())
            ->method('upsert');

        $persister = new TaxProviderPersister($repo);
        $persister->updateTaxProviders($manifest, 'foo', 'testApp', Context::createDefaultContext());
    }

    public function testNoTaxProvidersInManifest(): void
    {
        $manifest = $this->createManifest($this->createTaxProviders([]));

        $repo = $this->createMock(EntityRepository::class);
        $repo
            ->expects(static::never())
            ->method('upsert');

        $persister = new TaxProviderPersister($repo);
        $persister->updateTaxProviders($manifest, 'foo', 'testApp', Context::createDefaultContext());
    }

    /**
     * @param array<TaxProvider> $providers
     */
    private function createManifest(array $providers = []): Manifest
    {
        $ref = new \ReflectionClass(Manifest::class);
        $manifestConstructor = $ref->getConstructor();
        static::assertNotNull($manifestConstructor);

        $manifestConstructor->setAccessible(true);

        $manifest = $ref->newInstanceWithoutConstructor();

        $ref = new \ReflectionClass(Tax::class);
        $taxConstructor = $ref->getConstructor();
        static::assertNotNull($taxConstructor);

        $taxConstructor->setAccessible(true);

        $tax = $ref->newInstanceWithoutConstructor();

        $taxConstructor->invoke($tax, $providers);

        $domDocument = new \DOMDocument();
        $domElement = $domDocument->createElement('root');

        $childElementLabel = $domDocument->createElement('label', 'label value');
        $childElementName = $domDocument->createElement('name', self::META_APP_NAME);
        $childElementUrl = $domDocument->createElement('url', 'url value');
        $childElementAuthor = $domDocument->createElement('author', 'author value');
        $childElementCopyright = $domDocument->createElement('copyright', 'copyright value');
        $childElementLicense = $domDocument->createElement('license', 'license value');
        $childElementVersion = $domDocument->createElement('version', 'version value');

        $domElement->appendChild($childElementLabel);
        $domElement->appendChild($childElementName);
        $domElement->appendChild($childElementUrl);
        $domElement->appendChild($childElementAuthor);
        $domElement->appendChild($childElementCopyright);
        $domElement->appendChild($childElementLicense);
        $domElement->appendChild($childElementVersion);

        $metaData = Metadata::fromXml($domElement);

        $manifestConstructor->invoke(
            $manifest,
            'foo',
            $metaData,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $tax
        );

        return $manifest;
    }

    /**
     * @param array<array<string, string>> $providers
     *
     * @return array<TaxProvider>
     */
    private function createTaxProviders(array $providers): array
    {
        $taxProviders = [];

        foreach ($providers as $providerData) {
            $ref = new \ReflectionClass(TaxProvider::class);
            $constructor = $ref->getConstructor();
            static::assertNotNull($constructor);

            $constructor->setAccessible(true);

            $taxProvider = $ref->newInstanceWithoutConstructor();

            static::assertInstanceOf(TaxProvider::class, $taxProvider);

            $constructor->invoke($taxProvider, $providerData);

            $taxProviders[] = $taxProvider;
        }

        return $taxProviders;
    }

    /**
     * @param array<TaxProvider> $providers
     *
     * @return EntitySearchResult<TaxProviderCollection>
     */
    private function existingProviders(array $providers = [], ?string $appId = null, ?string $appName = null): EntitySearchResult
    {
        $result = new TaxProviderCollection();

        foreach ($providers as $provider) {
            $taxProvider = new TaxProviderEntity();
            $taxProvider->setId($this->ids->get('tax-provider-' . $provider->getIdentifier()));
            $taxProvider->setActive(true);
            $taxProvider->setName($provider->getName());
            $taxProvider->setIdentifier(
                \sprintf('app\\%s_%s', $appName ?? self::META_APP_NAME, $provider->getIdentifier()),
            );

            $result->add($taxProvider);

            if (!$appId || !$appName) {
                continue;
            }

            $taxProvider->setProcessUrl($provider->getProcessUrl());
            $taxProvider->setAppId($appId);
        }

        return new EntitySearchResult(
            TaxProviderDefinition::ENTITY_NAME,
            $result->count(),
            $result,
            null,
            new Criteria(),
            Context::createDefaultContext()
        );
    }
}
