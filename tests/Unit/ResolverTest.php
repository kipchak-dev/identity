<?php

declare(strict_types=1);

namespace Kipchak\Identity\Tests\Unit;

use Kipchak\Identity\InvalidConfiguration;
use Kipchak\Identity\ApiKeyResolver;
use Kipchak\Identity\AttributeResolver;
use Kipchak\Identity\CallableResolver;
use Kipchak\Identity\HeaderResolver;
use Kipchak\Identity\ResolverChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ResolverTest extends TestCase
{
    private const array KEYS = ['secret-key-1' => 'client-a', 'secret-key-2' => 'client-b'];

    private function request(string $uri = '/v1/things', array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function testAttributeResolverReadsAStringAttribute(): void
    {
        $resolver = new AttributeResolver('kipchak.hmac.key_id');

        $this->assertSame('billing-service', $resolver->resolve($this->request()->withAttribute('kipchak.hmac.key_id', 'billing-service')));
        $this->assertNull($resolver->resolve($this->request()));
    }

    public function testAttributeResolverFollowsAClaimPathThroughArraysAndObjects(): void
    {
        $token = (object) ['sub' => 'user-1', 'org' => ['id' => 42, 'name' => 'Acme']];
        $request = $this->request()->withAttribute('token', $token);

        $this->assertSame('user-1', (new AttributeResolver('token', 'sub'))->resolve($request));
        $this->assertSame('42', (new AttributeResolver('token', 'org.id'))->resolve($request));
        $this->assertNull((new AttributeResolver('token', 'org.missing'))->resolve($request));
        $this->assertNull((new AttributeResolver('token', 'sub.deeper'))->resolve($request));
        $this->assertNull((new AttributeResolver('token', 'org'))->resolve($request), 'An array is not an identifier');
    }

    public function testAttributeResolverIgnoresValuesThatAreNotIdentifiers(): void
    {
        $resolver = new AttributeResolver('id');

        foreach ([['a'], 1.5, true, '   ', str_repeat('x', 257), new \stdClass()] as $value) {
            $this->assertNull($resolver->resolve($this->request()->withAttribute('id', $value)), var_export($value, true));
        }
        $this->assertSame('7', $resolver->resolve($this->request()->withAttribute('id', 7)));
    }

    public function testHeaderResolver(): void
    {
        $this->assertSame('acme', (new HeaderResolver('X-Tenant-ID'))->resolve($this->request(headers: ['x-tenant-id' => ' acme '])));
        $this->assertNull((new HeaderResolver('X-Tenant-ID'))->resolve($this->request()));
    }

    public function testHeaderResolverCanHashTheValue(): void
    {
        $resolved = (new HeaderResolver('Authorization', hash: true))->resolve($this->request(headers: ['Authorization' => 'Bearer abc']));

        $this->assertSame('sha256:' . hash('sha256', 'Bearer abc'), $resolved);
    }

    public function testApiKeyResolverReturnsTheClientNameNeverTheKey(): void
    {
        $resolver = new ApiKeyResolver(self::KEYS);

        $this->assertSame('client-a', $resolver->resolve($this->request(headers: ['apikey' => 'secret-key-1'])));
        $this->assertSame('client-b', $resolver->resolve($this->request('/v1/things?apikey=secret-key-2')));
        $this->assertNull($resolver->resolve($this->request(headers: ['apikey' => 'unknown'])));
        $this->assertNull($resolver->resolve($this->request()));
    }

    public function testApiKeyResolverPrefersTheHeaderLikeTheAuthKeyMiddleware(): void
    {
        $request = $this->request('/v1/things?apikey=secret-key-2', ['apikey' => 'secret-key-1']);

        $this->assertSame('client-a', (new ApiKeyResolver(self::KEYS))->resolve($request));
    }

    public function testApiKeyResolverWithCustomOrDisabledLocations(): void
    {
        $headerOnly = new ApiKeyResolver(self::KEYS, header: 'X-API-Key', queryParam: null);

        $this->assertSame('client-a', $headerOnly->resolve($this->request(headers: ['x-api-key' => 'secret-key-1'])));
        $this->assertNull($headerOnly->resolve($this->request('/v1/things?apikey=secret-key-1')));
    }

    public function testApiKeyResolverIgnoresArrayQueryValues(): void
    {
        $this->assertNull((new ApiKeyResolver(self::KEYS))->resolve($this->request('/v1/things?apikey[]=secret-key-1')));
    }

    public function testCallableResolver(): void
    {
        $resolver = new CallableResolver(fn (ServerRequestInterface $request) => $request->getHeaderLine('X-Org') ?: null);

        $this->assertSame('org-9', $resolver->resolve($this->request(headers: ['X-Org' => 'org-9'])));
        $this->assertNull($resolver->resolve($this->request()));
    }

    public function testChainUsesTheFirstResolverThatAnswers(): void
    {
        $chain = ResolverChain::fromArray('consumer', [
            ['source' => 'attribute', 'name' => 'kipchak.hmac.key_id'],
            ['source' => 'api_key'],
            ['source' => 'header', 'name' => 'X-Consumer'],
        ], ['authorised_keys' => self::KEYS, 'header' => 'apikey', 'query_param' => 'apikey']);

        $all = $this->request(headers: ['apikey' => 'secret-key-1', 'X-Consumer' => 'from-header'])->withAttribute('kipchak.hmac.key_id', 'signed');
        $this->assertSame('signed', $chain->resolve($all));
        $this->assertSame('client-a', $chain->resolve($this->request(headers: ['apikey' => 'secret-key-1', 'X-Consumer' => 'from-header'])));
        $this->assertSame('from-header', $chain->resolve($this->request(headers: ['apikey' => 'unknown', 'X-Consumer' => 'from-header'])));
        $this->assertNull($chain->resolve($this->request()));
        $this->assertFalse($chain->isEmpty());
        $this->assertTrue(ResolverChain::fromArray('tenant', [], null)->isEmpty());
    }

    public function testApiKeySourceUsesTheAuthKeyConfigLocations(): void
    {
        $chain = ResolverChain::fromArray('consumer', [['source' => 'api_key']], [
            'authorised_keys' => self::KEYS,
            'header' => 'x-key',
            'query_param' => 'k',
        ]);

        $this->assertSame('client-a', $chain->resolve($this->request(headers: ['x-key' => 'secret-key-1'])));
        $this->assertSame('client-b', $chain->resolve($this->request('/?k=secret-key-2')));
        $this->assertNull($chain->resolve($this->request(headers: ['apikey' => 'secret-key-1'])));
    }

    public function testApiKeySourceCanCarryItsOwnKeys(): void
    {
        $chain = ResolverChain::fromArray('consumer', [['source' => 'api_key', 'keys' => ['k1' => 'own'], 'query_param' => null]], null);

        $this->assertSame('own', $chain->resolve($this->request(headers: ['apikey' => 'k1'])));
        $this->assertNull($chain->resolve($this->request('/?apikey=k1')));
    }

    /**
     * @return iterable<string, array{mixed, ?array, string}>
     */
    public static function invalidSources(): iterable
    {
        yield 'not a list' => [['source' => 'header'], null, 'consumer must be a list of sources'];
        yield 'entry not array' => [['header'], null, "consumer[0] must be an array with a 'source' key"];
        yield 'unknown source' => [[['source' => 'cookie']], null, 'consumer[0].source must be one of'];
        yield 'header without name' => [[['source' => 'header']], null, 'consumer[0].name must be a non-empty string'];
        yield 'attribute with bad claim' => [[['source' => 'attribute', 'name' => 'a', 'claim' => '']], null, 'consumer[0].claim must be a non-empty string'];
        yield 'callable not callable' => [[['source' => 'callable', 'resolve' => 'no_such_function']], null, 'consumer[0].resolve must be callable'];
        yield 'api_key without keys' => [[['source' => 'api_key']], null, 'no keys were found'];
        yield 'api_key with empty auth config' => [[['source' => 'api_key']], ['authorised_keys' => []], 'no keys were found'];
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSourcesFailAtBoot(mixed $sources, ?array $apiKeyConfig, string $message): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        ResolverChain::fromArray('consumer', $sources, $apiKeyConfig);
    }
}
