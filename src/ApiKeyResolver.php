<?php

declare(strict_types=1);

namespace Kipchak\Identity;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Bills API-key requests to the client name the key maps to in kipchak.auth.key's authorised_keys
 * ('key' => 'client-name'), reading the key from the same header and query parameter as that middleware.
 *
 * The key itself is never returned, so it never reaches the event stream. A key that is not in the map
 * resolves to nothing.
 */
final readonly class ApiKeyResolver implements Resolver
{
    /**
     * @param array<string, string> $authorisedKeys key => client name
     */
    public function __construct(
        private array $authorisedKeys,
        private ?string $header = 'apikey',
        private ?string $queryParam = 'apikey',
    ) {
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $key = $this->header === null ? '' : $request->getHeaderLine($this->header);
        if ($key === '' && $this->queryParam !== null) {
            $key = $request->getQueryParams()[$this->queryParam] ?? '';
        }
        if (!is_string($key) || $key === '') {
            return null;
        }

        return Identifier::normalise($this->authorisedKeys[$key] ?? null);
    }
}
