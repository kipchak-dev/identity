<?php

declare(strict_types=1);

namespace Kipchak\Identity\Tests\Unit;

use Kipchak\Driver\Config\Config;
use Kipchak\Identity\Identity;
use Kipchak\Identity\InvalidConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class IdentityTest extends TestCase
{
    private function configure(array $files): void
    {
        $container = new class ($files) implements ContainerInterface {
            public function __construct(private array $files)
            {
            }

            public function get(string $id): mixed
            {
                return $this->files;
            }

            public function has(string $id): bool
            {
                return $id === 'drivers.config';
            }
        };
        (new \ReflectionProperty(Config::class, 'container'))->setValue(null, $container);
    }

    private function request(array $headers = [], ?object $token = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/v1/things');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $token === null ? $request : $request->withAttribute(Identity::TOKEN_ATTRIBUTE, $token);
    }

    public function testTheTokenSourceReadsAClaimOfTheVerifiedToken(): void
    {
        $identity = Identity::fromArray([
            'consumer' => [['source' => 'token', 'claim' => 'client_id'], ['source' => 'token', 'claim' => 'sub']],
            'tenant' => [['source' => 'token', 'claim' => 'org.id']],
        ]);

        $token = (object) ['sub' => 'user-1', 'client_id' => 'mobile-app', 'org' => (object) ['id' => 'acme']];
        $this->assertSame('mobile-app', $identity->consumerOf($this->request(token: $token)));
        $this->assertSame('acme', $identity->tenantOf($this->request(token: $token)));
        $this->assertSame('user-1', $identity->consumerOf($this->request(token: (object) ['sub' => 'user-1'])), 'falls through to sub');
    }

    public function testAMapTranslatesWhatASourceFound(): void
    {
        $identity = Identity::fromArray(['tenant' => [
            ['source' => 'token', 'claim' => 'iss', 'map' => ['https://login.acme.example/realms/main' => 'acme-sso']],
            ['source' => 'header', 'name' => 'X-Tenant-ID'],
        ]]);

        $this->assertSame('acme-sso', $identity->tenantOf($this->request(token: (object) ['iss' => 'https://login.acme.example/realms/main'])));
        $this->assertSame('fallback', $identity->tenantOf($this->request(['X-Tenant-ID' => 'fallback'], (object) ['iss' => 'https://other.example/'])), 'unmapped values fall through');
    }

    public function testAMapMustMapToNames(): void
    {
        $this->expectExceptionMessage('kipchak.identity: consumer[0].map must map found values to non-empty names');

        Identity::fromArray(['consumer' => [['source' => 'token', 'claim' => 'iss', 'map' => ['https://x/' => '']]]]);
    }

    public function testATokenClaimIsNeverReadFromAnUnverifiedHeader(): void
    {
        // Without the attribute (no auth middleware ran, or the path is ignored) there is no identity, even
        // with a bearer token present.
        $identity = Identity::fromArray(['consumer' => [['source' => 'token', 'claim' => 'sub']]]);

        $this->assertNull($identity->consumerOf($this->request(['Authorization' => 'Bearer eyJhbGciOiJub25lIn0.eyJzdWIiOiJhZG1pbiJ9.'])));
    }

    public function testTheTokenSourceNeedsAClaim(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('kipchak.identity: consumer[0].claim must be a non-empty string');

        Identity::fromArray(['consumer' => [['source' => 'token']]]);
    }

    public function testErrorsNameTheConfigFileTheyCameFrom(): void
    {
        $this->expectExceptionMessage('kipchak.metering: tenant[0].name must be a non-empty string');

        Identity::fromArray(['tenant' => [['source' => 'header']]], null, 'kipchak.metering');
    }

    public function testLoadReadsTheSharedFileAndTheApiKeyMap(): void
    {
        $this->configure([
            'kipchak.identity' => ['consumer' => [['source' => 'api_key']]],
            'kipchak.auth.key' => ['authorised_keys' => ['k1' => 'acme-corp'], 'header' => 'apikey', 'query_param' => 'apikey'],
        ]);

        $this->assertSame('acme-corp', Identity::load()->consumerOf($this->request(['apikey' => 'k1'])));
    }

    public function testLoadWithoutASharedFileResolvesNothing(): void
    {
        $this->configure([]);

        $identity = Identity::load();
        $this->assertTrue($identity->consumer->isEmpty());
        $this->assertNull($identity->consumerOf($this->request(['apikey' => 'k1'])));
    }

    public function testAPackageInheritsTheSharedDefinitionKeyByKey(): void
    {
        $this->configure(['kipchak.identity' => [
            'consumer' => [['source' => 'header', 'name' => 'X-Shared-Consumer']],
            'tenant' => [['source' => 'header', 'name' => 'X-Shared-Tenant']],
        ]]);

        $identity = Identity::forPackage(['tenant' => [['source' => 'header', 'name' => 'X-Own-Tenant']]], 'kipchak.metering');
        $request = $this->request(['X-Shared-Consumer' => 'c', 'X-Shared-Tenant' => 'shared', 'X-Own-Tenant' => 'own']);

        $this->assertSame('c', $identity->consumerOf($request), 'consumer from kipchak.identity');
        $this->assertSame('own', $identity->tenantOf($request), 'tenant overridden by the package');
    }

    public function testErrorsInTheSharedFileNameIt(): void
    {
        $this->configure(['kipchak.identity' => ['consumer' => [['source' => 'cookie']]]]);

        $this->expectExceptionMessage('kipchak.identity: consumer[0].source');
        Identity::forPackage([], 'kipchak.metering');
    }
}
