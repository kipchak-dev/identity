<?php

declare(strict_types=1);

namespace Kipchak\Identity;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Delegates to your own function, for identities the built-in resolvers cannot read.
 */
final readonly class CallableResolver implements Resolver
{
    /** @var \Closure(ServerRequestInterface): mixed */
    private \Closure $resolve;

    /**
     * @param callable(ServerRequestInterface): (string|int|null) $resolve
     */
    public function __construct(callable $resolve)
    {
        $this->resolve = $resolve(...);
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        return Identifier::normalise(($this->resolve)($request));
    }
}
