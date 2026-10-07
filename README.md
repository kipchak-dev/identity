# Kipchak Identity

Who a request is from: the **consumer** it is billed and limited as, and the **tenant** it belongs to, read from
API keys (`kipchak/middleware-auth-key`), verified tokens (`auth-jwks`, `auth-jwt`), signed requests
(`auth-hmac`), request attributes, headers or your own function.

It is defined once, in `config/kipchak.identity.php`, and shared by Usage Metering and Subashi Pro quotas, so a
client is the same consumer on the bill and in the limits. Those packages install it; there is nothing to
initialise.

Documentation: <https://kipchak.dev/docs/identity>. Sample config: [`sample.config.php`](sample.config.php).

#### Composer Package

`kipchak/identity`

## Sources

| Source | Reads |
| --- | --- |
| `token` | A claim of the token auth-jwks or auth-jwt verified (the `kipchak.auth.token` request attribute). |
| `api_key` | The client name for the request's key in `kipchak.auth.key`. |
| `attribute` | A request attribute, optionally a `claim` path into it. |
| `header` | A request header, optionally hashed. |
| `callable` | `fn (ServerRequestInterface $request): ?string`. |

Any source can take a `map` that translates what it found into a name.

## Using it in a package

```php
use Kipchak\Identity\Identity;

// The package's own 'consumer' / 'tenant' keys where set, kipchak.identity otherwise.
$identity = Identity::forPackage($packageConfig, 'kipchak.my-package');

$consumer = $identity->consumerOf($request);
$tenant = $identity->tenantOf($request);
```

Invalid sources raise `Kipchak\Identity\InvalidConfiguration` at boot, naming the config file and key.

## Tests

```bash
composer install
./vendor/bin/phpunit
```

## Licence

MIT
