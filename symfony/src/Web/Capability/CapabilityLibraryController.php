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
            $coverage = $this->capabilities->coverage($actor);
            $coverageById = [];
            foreach ($coverage['capabilities'] as $row) {
                $coverageById[$row['id']] = $row;
            }
            $domains = array_values(array_unique(array_column($inventory, 'owner_domain')));
            sort($domains, SORT_STRING);
            $selectedDomain = trim((string)$request->query->get('domain', ''));
            if (!in_array($selectedDomain, ['', ...$domains], true)) $selectedDomain = '';
            $selectedKind = trim((string)$request->query->get('kind', ''));
            if (!in_array($selectedKind, ['', 'query','command','job','workflow','agent_tool'], true)) $selectedKind = '';
            $query = mb_strtolower(mb_substr(trim((string)$request->query->get('q', '')),0,80));
            $status = trim((string)$request->query->get('status', ''));
            $allowed = ['','read_only','direct_human','workflow_approval_required','unavailable'];
            if (!in_array($status,$allowed,true)) $status = '';
            $inventory = array_values(array_filter($inventory,static function(array $item) use($query,$status,$selectedDomain,$selectedKind):bool {
                if ($status !== '' && ($item['status'] ?? null) !== $status) return false;
                if ($selectedDomain !== '' && ($item['owner_domain'] ?? '') !== $selectedDomain) return false;
                if ($selectedKind !== '' && ($item['contract']['kind'] ?? '') !== $selectedKind) return false;
                if ($query === '') return true;
                return str_contains(mb_strtolower((string)($item['id'] ?? '')),$query)
                    || str_contains(mb_strtolower((string)($item['owner_domain'] ?? '')),$query);
            }));
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
                'coverage' => $coverage,
                'coverageById' => $coverageById,
                'domains' => $domains,
                'selectedDomain' => $selectedDomain,
                'selectedKind' => $selectedKind,
                'searchQuery' => $query,
                'searchStatus' => $status,
                'csrfToken' => $this->csrf->token($request),
                'idempotencyKey' => bin2hex(random_bytes(16)),
                'verifiedPropertyId' => $verified,
                'notice' => (string) $request->query->get('notice', ''),
            ]),
            200,
            ['Cache-Control'=>'no-store, private', 'X-Robots-Tag'=>'noindex, nofollow'],
        );
    }

    /** Human readable typed contract and side-effect-free validation workspace. */
    public function show(Request $request, string $id): Response
    {
        return $this->contractWorkspace($request,$id,'{}');
    }

    /** Never calls invoke(), submits a Plan, or mutates a Domain. */
    public function validate(Request $request, string $id): Response
    {
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',400);
        $raw=(string)$request->request->get('input_json','');
        if (strlen($raw)>16384) return new Response('Input is too large.',413);
        try {
            $decoded=json_decode($raw,false,32,JSON_THROW_ON_ERROR);
            if (!$decoded instanceof \stdClass) {
                return $this->contractWorkspace($request,$id,$raw,null,'Потрібен JSON-об’єкт.',422);
            }
            $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            $actor=$this->tenants->current();
            if ($actor===null) return new RedirectResponse('/auth/login');
            $result=$this->capabilities->validateInput($actor,$id,$input);
            return $this->contractWorkspace($request,$id,$raw,$result);
        } catch (\JsonException) {
            return $this->contractWorkspace($request,$id,$raw,null,'Некоректний JSON.',422);
        } catch (DomainException) {
            return $this->contractWorkspace($request,$id,$raw,null,
                'Параметри, права або доступність контракту не пройшли перевірку.',422);
        }
    }

    /** @param array<string,mixed>|null $verified */
    private function contractWorkspace(
        Request $request,string $id,string $raw,
        ?array $verified=null,?string $error=null,int $code=200,
    ): Response {
        $actor=$this->tenants->current();
        if ($actor===null) return new RedirectResponse('/auth/login');
        try {
            $capability=$this->capabilities->describe($actor,$id);
            $coverage=$this->capabilities->coverage($actor);
        } catch (DomainException) {
            return new Response('Forbidden',403);
        }
        $finding=null;
        foreach ($coverage['capabilities'] as $row) {
            if ($row['id']===$id) { $finding=$row;break; }
        }
        return new Response($this->twig->render(
            'experience/capability/detail.html.twig',[
                'capability'=>$capability,'finding'=>$finding,
                'csrfToken'=>$this->csrf->token($request),
                'inputJson'=>$raw,'verified'=>$verified,'validationError'=>$error,
            ]
        ),$code,['Cache-Control'=>'no-store, private',
                 'X-Robots-Tag'=>'noindex, nofollow']);
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
        } catch (DomainException $error) {
            // Output mismatch is uncertain AFTER the native mutation.
            // Never advise issuing a fresh idempotency key to "try again".
            if (str_contains($error->getMessage(), 'Capability output unverified')) {
                return new RedirectResponse('/workspace/capabilities?notice=unverified',303);
            }
            return new RedirectResponse('/workspace/capabilities?notice=invalid',303);
        } catch (\InvalidArgumentException) {
            return new RedirectResponse('/workspace/capabilities?notice=invalid',303);
        } catch (Throwable $error) {
            error_log('cos.capability.ui.property.create_failed '.$error->getMessage());
            return new RedirectResponse('/workspace/capabilities?notice=unavailable',303);
        }
    }
}
