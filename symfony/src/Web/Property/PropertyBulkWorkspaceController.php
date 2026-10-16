<?php
declare(strict_types=1);

namespace App\Web\Property;

use App\Persistence\Property\PropertyBulkIntakeService;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

/** Human review workspace; only proposes a Plan, never runs any Action. */
final readonly class PropertyBulkWorkspaceController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private PropertyBulkIntakeService $intake,
        private Environment $twig,
    ) {}

    public function index(Request $request):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null) return new RedirectResponse('/auth/login');
        if (!$actor->isManager()) return new Response('Forbidden',403);
        $batchId=(string)$request->query->get('batch','');
        $batch=null;
        if ($batchId!=='') {
            try { $batch=$this->intake->preview($actor,$batchId); }
            catch (Throwable) { return new Response('Property batch not found.',404); }
        }
        return new Response($this->twig->render('experience/property/bulk_intake.html.twig',[
            'csrfToken'=>$this->csrf->token($request),
            'batch'=>$batch,'notice'=>(string)$request->query->get('notice',''),
        ]),200,['Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex,nofollow']);
    }

    public function stage(Request $request):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null || !$actor->isManager()) return new Response('Forbidden',403);
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token',400);
        $raw=(string)$request->request->get('items_json','');
        if (strlen($raw)>65536 || !json_validate($raw)) {
            return new Response('Очікується JSON-масив із 1–10 об’єктами.',422);
        }
        $items=json_decode($raw,true);
        if (!is_array($items) || !array_is_list($items)) {
            return new Response('Некоректний формат пакета.',422);
        }
        try {
            $batch=$this->intake->stage($actor,$items);
            return new RedirectResponse('/workspace/property/bulk?batch='.$batch['batch_id'],303);
        } catch (DomainException|\InvalidArgumentException $e) {
            return new Response('Імпорт заблоковано: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),422);
        } catch (Throwable $e) {
            error_log('cos.property.bulk.ui.stage '.$e->getMessage());
            return new Response('Сервіс імпорту недоступний.',500);
        }
    }

    public function propose(Request $request,string $batchId):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null || !$actor->isManager()) return new Response('Forbidden',403);
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token',400);
        try {
            $this->intake->propose($actor,$batchId,(string)$request->request->get('digest',''));
            return new RedirectResponse('/workspace/property/bulk?batch='.rawurlencode($batchId).'&notice=planned',303);
        } catch (DomainException|\InvalidArgumentException $e) {
            return new Response('Plan не сформовано: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),422);
        } catch(Throwable $e) {
            error_log('cos.property.bulk.ui.propose '.$e->getMessage());
            return new Response('Планування тимчасово недоступне.',500);
        }
    }
}
