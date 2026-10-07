<?php

declare(strict_types=1);

namespace Kipchak\Identity;


use Psr\Http\Message\ServerRequestInterface;

/**
 * Tries each resolver in turn; the first that returns a value wins.
 */
final readonly class ResolverChain implements Resolver
{
    /**
     * @param list<Resolver> $resolvers
     */
    public function __construct(private array $resolvers)
    {
    }

    /**
     * Builds the chain for the 'consumer' or 'tenant' config key: a list of sources such as
     *
     *   ['source' => 'attribute', 'name' => 'kipchak.hmac.key_id'],
     *   ['source' => 'token', 'claim' => 'client_id'],
     *   ['source' => 'api_key'],
     *   ['source' => 'header', 'name' => 'x-tenant-id'],
     *   ['source' => 'callable', 'resolve' => fn (ServerRequestInterface $r) => ...],
     *
     * Any source can take a 'map' that translates what it found, e.g. an issuer URL to a short name:
     *
     *   ['source' => 'token', 'claim' => 'iss', 'map' => ['https://login.acme.example/' => 'acme-sso']],
     *
     * @param array<string, mixed>|null $apiKeyConfig The kipchak.auth.key config, for the api_key source.
     */
    public static function fromArray(string $path, mixed $sources, ?array $apiKeyConfig): self
    {
        if (!is_array($sources) || !array_is_list($sources)) {
            throw InvalidConfiguration::at($path, 'must be a list of sources');
        }

        $resolvers = [];
        foreach ($sources as $index => $source) {
            $at = "{$path}[{$index}]";
            if (!is_array($source)) {
                throw InvalidConfiguration::at($at, "must be an array with a 'source' key");
            }

            $resolver = match ($source['source'] ?? null) {
                'attribute' => new AttributeResolver(
                    self::requireString($source, 'name', $at),
                    self::optionalString($source, 'claim', $at),
                ),
                'token' => new AttributeResolver(
                    Identity::TOKEN_ATTRIBUTE,
                    self::requireString($source, 'claim', $at),
                ),
                'header' => new HeaderResolver(
                    self::requireString($source, 'name', $at),
                    (bool) ($source['hash'] ?? false),
                ),
                'api_key' => self::apiKeyResolver($source, $at, $apiKeyConfig),
                'callable' => is_callable($source['resolve'] ?? null)
                    ? new CallableResolver($source['resolve'])
                    : throw InvalidConfiguration::at("{$at}.resolve", 'must be callable'),
                default => throw InvalidConfiguration::at(
                    "{$at}.source",
                    "must be one of 'token', 'api_key', 'attribute', 'header' or 'callable'"
                ),
            };

            if (array_key_exists('map', $source)) {
                $map = $source['map'];
                if (!is_array($map) || $map === [] || array_filter($map, fn ($v, $k) => !is_string($v) || $v === '' || $k === '', ARRAY_FILTER_USE_BOTH) !== []) {
                    throw InvalidConfiguration::at("{$at}.map", 'must map found values to non-empty names');
                }
                $resolver = new MappedResolver($resolver, array_map('strval', $map));
            }

            $resolvers[] = $resolver;
        }

        return new self($resolvers);
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $value = $resolver->resolve($request);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->resolvers === [];
    }

    private static function apiKeyResolver(array $source, string $at, ?array $apiKeyConfig): ApiKeyResolver
    {
        $keys = $source['keys'] ?? $apiKeyConfig['authorised_keys'] ?? null;
        if (!is_array($keys) || $keys === []) {
            throw InvalidConfiguration::at(
                $at,
                "uses the api_key source, but no keys were found: set 'keys', or authorised_keys in kipchak.auth.key"
            );
        }

        return new ApiKeyResolver(
            $keys,
            array_key_exists('header', $source) ? $source['header'] : ($apiKeyConfig['header'] ?? 'apikey'),
            array_key_exists('query_param', $source) ? $source['query_param'] : ($apiKeyConfig['query_param'] ?? 'apikey'),
        );
    }

    private static function requireString(array $source, string $key, string $at): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw InvalidConfiguration::at("{$at}.{$key}", 'must be a non-empty string');
        }

        return $value;
    }

    private static function optionalString(array $source, string $key, string $at): ?string
    {
        return isset($source[$key]) ? self::requireString($source, $key, $at) : null;
    }
}
