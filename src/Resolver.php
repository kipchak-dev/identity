<?php

declare(strict_types=1);

namespace Kipchak\Identity;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Works out who a request should be billed to (the consumer) or which tenant it belongs to.
 */
interface Resolver
{
    /**
     * @return string|null Null when this resolver cannot tell; the next one in the chain is tried.
     */
    public function resolve(ServerRequestInterface $request): ?string;
}
