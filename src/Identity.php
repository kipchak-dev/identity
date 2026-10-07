<?php

declare(strict_types=1);

namespace Kipchak\Identity;

use Kipchak\Driver\Config\Config;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who a request is from: the consumer it is billed and limited as, and the tenant it belongs to.
 *
 * Defined once in config/kipchak.identity.php and shared by every package that needs it (Usage Metering,
 * Subashi Pro quotas), so "acme-corp" means the same client for billing and for limits:
 *
 *   return [
 *       'consumer' => [
 *           ['source' => 'token', 'claim' => 'client_id'],   // auth-jwks / auth-jwt
 *           ['source' => 'api_key'],                         // auth-key client names
 *       ],
 *       'tenant' => [
 *           ['source' => 'token', 'claim' => 'org_id'],
 *       ],
 *   ];
 *
 * Each list is tried in order and the first source that answers wins. Resolution reads only the request,
 * so it is safe in FrankenPHP worker mode.
 */
final readonly class Identity
{
    public const string CONFIG_KEY = 'kipchak.identity';

    /** The request attribute auth-jwks and auth-jwt put the verified token's claims in. */
    public const string TOKEN_ATTRIBUTE = 'kipchak.auth.token';

    public function __construct(
        public ResolverChain $consumer,
        public ResolverChain $tenant,
    ) {
    }

    /**
     * Builds the consumer and tenant chains from a config array with 'consumer' and 'tenant' lists.
     *
     * @param array<string, mixed>|null $apiKeyConfig The kipchak.auth.key config, for the api_key source.
     *
     * @throws InvalidConfiguration Prefixed with $configName.
     */
    public static function fromArray(array $config, ?array $apiKeyConfig = null, string $configName = self::CONFIG_KEY): self
    {
        try {
            return new self(
                ResolverChain::fromArray('consumer', $config['consumer'] ?? [], $apiKeyConfig),
                ResolverChain::fromArray('tenant', $config['tenant'] ?? [], $apiKeyConfig),
            );
        } catch (InvalidConfiguration $exception) {
            throw $exception->inConfig($configName);
        }
    }

    /**
     * The shared definition from config/kipchak.identity.php (empty chains when there is none).
     */
    public static function load(): self
    {
        return self::fromArray(Config::get(self::CONFIG_KEY), Config::get('kipchak.auth.key') ?: null);
    }

    /**
     * A package's own 'consumer' and 'tenant' keys where it sets them, the shared definition otherwise.
     * Each key falls back separately, so a package can override only the tenant.
     *
     * @throws InvalidConfiguration Prefixed with $configName.
     */
    public static function forPackage(array $packageConfig, string $configName): self
    {
        return self::merge($packageConfig, Config::get(self::CONFIG_KEY), Config::get('kipchak.auth.key') ?: null, $configName);
    }

    /**
     * forPackage() without reading config files: the package's 'consumer' and 'tenant' where it sets them, the
     * shared definition otherwise. Errors name whichever file the faulty sources came from.
     *
     * @throws InvalidConfiguration
     */
    public static function merge(array $packageConfig, array $sharedConfig, ?array $apiKeyConfig, string $configName): self
    {
        $chains = [];
        foreach (['consumer', 'tenant'] as $key) {
            $own = array_key_exists($key, $packageConfig);
            try {
                $chains[$key] = ResolverChain::fromArray($key, $own ? $packageConfig[$key] : ($sharedConfig[$key] ?? []), $apiKeyConfig);
            } catch (InvalidConfiguration $exception) {
                throw $exception->inConfig($own ? $configName : self::CONFIG_KEY);
            }
        }

        return new self($chains['consumer'], $chains['tenant']);
    }

    public function consumerOf(ServerRequestInterface $request): ?string
    {
        return $this->consumer->resolve($request);
    }

    public function tenantOf(ServerRequestInterface $request): ?string
    {
        return $this->tenant->resolve($request);
    }
}
