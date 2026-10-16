<?php
declare(strict_types=1);

namespace App\Web\Capability;

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

/** A human-only adapter to the SAME canonical Gateway used by the API. */
final readonly class CapabilityLibraryController
{
    public function __construct(
        private CanonicalCapabilityGateway $capabilities,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->tenants->current();
        if ($actor === null) return new RedirectResponse('/auth/login');
        try {
            $inventory = $this->capabilities->inventory($actor);
        } catch (DomainException) {
            return new Response('Forbidden', 403);
        }

        // A success indication requires a fresh, tenant-scoped Domain read.
        // URL parameters alone are never evidence that an Asset was created.
        $reference = (string) $request->query->get('reference', '');
        $verified = null;
        if (preg_match('/^PROP-[A-F0-9]{20}$/D', $reference) === 1) {
            try {
                $result = $this->capabilities->invoke(
                    $actor, 'property.asset.get', ['reference'=>$reference],
                    'human_ui', '', CorrelationId::generate()->value(),
                );
                if (is_array($result)) $verified = $reference;
            } catch (Throwable) {
                $verified = null;
            }
        }

        return new Response(
            $this->twig->render('experience/capability/library.html.twig', [
                'capabilities' => $inventory,
                'csrfToken' => $this->csrf->token($request),
                'idempotencyKey' => bin2hex(random_bytes(16)),
                'verifiedPropertyId' => $verified,
                'notice' => (string) $request->query->get('notice', ''),
            ]),
            200,
            ['Cache-Control'=>'no-store, private', 'X-Robots-Tag'=>'noindex, nofollow'],
        );
    }

    /** POST is a single explicit human mutation; not autonomous/bulk execution. */
    public function createProperty(Request $request): Response
    {
        $actor = $this->tenants->current();
        if ($actor === null) return new RedirectResponse('/auth/login');
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', 400);
        $key = trim((string) $request->request->get('idempotency_key', ''));
        $input = [
            'type_code'=>trim((string)$request->request->get('type_code', '')),
            'city'=>trim((string)$request->request->get('city', '')),
        ];
        foreach (['region','district','address'] as $field) {
            $value=trim((string)$request->request->get($field, ''));
            if ($value !== '') $input[$field]=$value;
        }
        $area=trim((string)$request->request->get('area_total', ''));
        if ($area !== '') {
            if (!is_numeric($area) || !is_finite((float)$area)
                || (float)$area < 0 || (float)$area > 1000000) {
                return new RedirectResponse('/workspace/capabilities?notice=invalid',303);
            }
            $input['area_total']=(float)$area;
        }
        try {
            $result=$this->capabilities->invoke(
                $actor,'property.asset.create',$input,'human_ui',
                $key,CorrelationId::generate()->value(),
            );
            $assetId=$result['asset']['asset_id'] ?? null;
            if (!is_string($assetId) || !preg_match('/^PROP-[A-F0-9]{20}$/D',$assetId)) {
                // A Domain result without a verifiable reference is NOT success.
                return new RedirectResponse('/workspace/capabilities?notice=unverified',303);
            }
            return new RedirectResponse(
                '/workspace/capabilities?reference='.rawurlencode($assetId),303
            );
        } catch (DomainException|\InvalidArgumentException) {
            return new RedirectResponse('/workspace/capabilities?notice=invalid',303);
        } catch (Throwable $error) {
            error_log('cos.capability.ui.property.create_failed '.$error->getMessage());
            return new RedirectResponse('/workspace/capabilities?notice=unavailable',303);
        }
    }
}
