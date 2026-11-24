<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;

/**
 * Read-only Shopify OAuth and webhook HMAC material.
 * Legacy single-store environment is retained for the supervised pilot.
 * Per-store env://COS_SHOPIFY_* JSON is resolved by the existing COS Vault,
 * never persisted in pm_external_connections or returned to a capability.
 */
final readonly class ShopifyClientCredentials
{
    public function __construct(
        private ShopifyHttpTransportInterface $http,
        private ?CredentialVaultInterface $vault = null,
    ) {}

    public static function assertSecretReference(string $reference): void
    {
        if ($reference !== 'shopify_env_primary'
            && !preg_match('#^env://COS_SHOPIFY_[A-Z0-9_]{3,80}$#D', $reference)) {
            throw new DomainException('Unsupported Shopify credential reference.');
        }
    }

    /** @return array{client_id:string,client_secret:string} */
    private function material(
        string $shopDomain,
        string $secretRef,
        ?string $organizationId,
        ?string $connectionId,
    ): array {
        ShopifyReadClient::assertShopDomain($shopDomain);
        self::assertSecretReference($secretRef);
        if ($secretRef === 'shopify_env_primary') {
            $configured = getenv('SHOPIFY_SHOP_DOMAIN');
            $id = getenv('SHOPIFY_CLIENT_ID');
            $secret = getenv('SHOPIFY_CLIENT_SECRET');
        } else {
            if ($this->vault === null || $organizationId === null || $organizationId === ''
                || $connectionId === null || $connectionId === '') {
                throw new DomainException('Tenant-scoped Shopify credential resolver is required.');
            }
            $credential = new Credential(
                'pm-shopify-'.substr(hash('sha256', $shopDomain.':'.$connectionId), 0, 32),
                OrganizationId::fromString($organizationId),
                $connectionId,
                'shopify_client_credentials',
                $secretRef,
                ['shopify.read'],
            );
            $material = $this->vault->resolve($credential);
            $configured = $material['shop_domain'] ?? null;
            $id = $material['client_id'] ?? null;
            $secret = $material['client_secret'] ?? null;
        }
        if (!is_string($configured) || !hash_equals($shopDomain, $configured)
            || !is_string($id) || $id === '' || strlen($id) > 512
            || !is_string($secret) || $secret === '' || strlen($secret) > 512
            || strpbrk($id.$secret, "\r\n") !== false) {
            throw new DomainException('Shopify server credentials not configured for this connection.');
        }
        return ['client_id'=>$id,'client_secret'=>$secret];
    }

    public function accessToken(
        string $shopDomain,
        string $secretRef,
        ?string $organizationId = null,
        ?string $connectionId = null,
    ): string {
        $material = $this->material($shopDomain, $secretRef, $organizationId, $connectionId);
        $result = $this->http->post('https://'.$shopDomain.'/admin/oauth/access_token', [
            'Content-Type'=>'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type'=>'client_credentials',
            'client_id'=>$material['client_id'],
            'client_secret'=>$material['client_secret'],
        ], '', '&', PHP_QUERY_RFC3986));
        if(in_array($result['status'],[408,425,429,500,502,503,504],true))throw new ShopifyTransientReadFailure('Shopify OAuth temporarily unavailable.');
        if ($result['status'] !== 200) throw new DomainException('Shopify client credentials rejected.');
        $payload = json_decode($result['body'], true, flags: JSON_THROW_ON_ERROR);
        $token = $payload['access_token'] ?? null;
        if (!is_string($token) || $token === '') throw new DomainException('Shopify OAuth token missing.');
        return $token;
    }

    /** Shop-specific HMAC key, never exposed to HTTP responses or logs. */
    public function webhookSecret(
        string $shopDomain,
        string $secretRef,
        ?string $organizationId = null,
        ?string $connectionId = null,
    ): string {
        return $this->material($shopDomain, $secretRef, $organizationId, $connectionId)['client_secret'];
    }
}
