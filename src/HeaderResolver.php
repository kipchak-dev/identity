<?php

declare(strict_types=1);

namespace Kipchak\Identity;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a request header, e.g. X-Tenant-ID set by a gateway in front of the API.
 *
 * Only trust a header the client cannot set itself, i.e. one your gateway overwrites; otherwise a client can
 * bill its usage to someone else. With hash, the value is replaced by 'sha256:<hex>' so a credential-like value
 * is never written to the event stream.
 */
final readonly class HeaderResolver implements Resolver
{
    public function __construct(
        private string $header,
        private bool $hash = false,
    ) {
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $value = Identifier::normalise($request->getHeaderLine($this->header));
        if ($value === null || !$this->hash) {
            return $value;
        }

        return 'sha256:' . hash('sha256', $value);
    }
}
