<?php

declare(strict_types=1);

namespace Kipchak\Identity;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Translates what another resolver found through a fixed map, e.g. an identity provider's issuer URL to a short
 * name, or a client ID to a customer name. Values not in the map resolve to nothing, so the next source in the
 * chain is tried.
 */
final readonly class MappedResolver implements Resolver
{
    /**
     * @param array<string, string> $map
     */
    public function __construct(
        private Resolver $resolver,
        private array $map,
    ) {
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $value = $this->resolver->resolve($request);

        return $value === null ? null : Identifier::normalise($this->map[$value] ?? null);
    }
}
