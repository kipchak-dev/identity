<?php

/**
 * Copy to config/kipchak.identity.php.
 *
 * Who each request is from, shared by Usage Metering and Subashi Pro quotas. Each list is tried in order and
 * the first source that answers wins. See https://kipchak.dev/docs/identity.
 */

return [
    // The consumer: who the request is billed and limited as.
    'consumer' => [
        // auth-jwks / auth-jwt: a claim of the verified token.
        ['source' => 'token', 'claim' => 'client_id'],
        // auth-hmac: the key ID of the verified signature.
        ['source' => 'attribute', 'name' => 'kipchak.hmac.key_id'],
        // auth-key: the client name the API key maps to in kipchak.auth.key (the key itself is never used).
        ['source' => 'api_key'],
    ],

    // The tenant: the organisation the request belongs to. Optional.
    'tenant' => [
        // ['source' => 'token', 'claim' => 'org_id'],
        // A map translates what a source found, e.g. an identity provider's issuer to a short name:
        // ['source' => 'token', 'claim' => 'iss', 'map' => ['https://login.acme.example/realms/main' => 'acme-sso']],
        // Only for a header your gateway sets and overwrites:
        // ['source' => 'header', 'name' => 'X-Tenant-ID'],
    ],
];
