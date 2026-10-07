<?php

declare(strict_types=1);

namespace Kipchak\Identity;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a request attribute set by an earlier middleware, e.g. kipchak.hmac.key_id from Request Signing.
 *
 * With a claim, the attribute is an array or object and the claim is a dot-separated path into it, e.g. 'sub'
 * or 'org.id' for a decoded token.
 *
 * The attribute is only visible if that middleware runs before this one: in middlewares/middlewares.php,
 * initialise Metering before it (Slim runs the last-added middleware first).
 */
final readonly class AttributeResolver implements Resolver
{
    public function __construct(
        private string $attribute,
        private ?string $claim = null,
    ) {
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute($this->attribute);

        if ($this->claim !== null) {
            foreach (explode('.', $this->claim) as $segment) {
                $value = match (true) {
                    is_array($value) => $value[$segment] ?? null,
                    is_object($value) => $value->{$segment} ?? null,
                    default => null,
                };
            }
        }

        return Identifier::normalise($value);
    }
}
