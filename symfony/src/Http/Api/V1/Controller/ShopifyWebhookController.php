<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use DomainException;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyWebhookVerifier;
use Kernel\Module\ActiveModuleResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * External HMAC-authenticated ingestion, not a mutation endpoint.
 * A delivery receipt requests later reconciliation via canonical workflow.
 */
final readonly class ShopifyWebhookController
{
    public function __construct(
        private ShopifyObservationStore $observations,
        private ActiveModuleResolver $modules,
    ) {}

    public function receive(Request $request): JsonResponse
    {
        $raw=(string)$request->getContent();
        $shop=(string)$request->headers->get('X-Shopify-Shop-Domain','');
        $signature=(string)$request->headers->get('X-Shopify-Hmac-Sha256','');
        $delivery=(string)$request->headers->get('X-Shopify-Webhook-Id','');
        $topic=(string)$request->headers->get('X-Shopify-Topic','');
        $secret=getenv('SHOPIFY_CLIENT_SECRET');
        if (strlen($raw)>1048576) return $this->reply('payload_too_large',413);
        if (!is_string($secret)||$secret==='') return $this->reply('webhook_disabled',503);
        try {
            ShopifyReadClient::assertShopDomain($shop);
            ShopifyWebhookVerifier::verify($raw,$signature,$secret);
            $connection=$this->observations->uniqueConnectionForShop($shop);
            if (!$this->modules->isEnabled($connection['organization_id'],'product_management')) {
                throw new DomainException('Product Management is inactive for the target tenant.');
            }
            $new=$this->observations->recordVerifiedDelivery(
                $connection['organization_id'],$connection['connection_id'],
                $delivery,$topic,$raw
            );
            return $this->reply($new?'accepted_for_reconciliation':'already_received',$new?202:200);
        } catch (DomainException $e) {
            return $this->reply('invalid_shopify_webhook',401);
        } catch (Throwable $e) {
            // No raw body, tokens, PII or secret in error logs.
            error_log('product_management.shopify_webhook_ingress_failed');
            return $this->reply('temporary_failure',503);
        }
    }

    private function reply(string $status,int $code): JsonResponse
    {
        $response=new JsonResponse(['status'=>$status],$code);
        $response->headers->set('Cache-Control','no-store');
        $response->headers->set('X-Robots-Tag','noindex, nofollow');
        return $response;
    }
}
