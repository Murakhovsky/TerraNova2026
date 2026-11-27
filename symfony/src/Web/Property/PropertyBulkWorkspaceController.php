<?php
declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\PropertyBulkCsvReader;
use App\Persistence\Property\PropertyBulkIntakeService;
use App\Persistence\Property\PropertyBulkPlanProposalService;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Adaptive\AdaptivePagePresentation;
use App\Web\Experience\Adaptive\ExperienceMode;
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
        private PropertyBulkPlanProposalService $plans,
        private PropertyBulkCsvReader $csv,
        private Environment $twig,
        private AdaptivePagePresentation $adaptive,
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
        $sections=[];
        $triggers=[];
        if ($batch===null) {
            $sections[]=AdaptivePagePresentation::section('bulk.json_input',ExperienceMode::Expert);
        } else {
            $sections[]=AdaptivePagePresentation::section('bulk.identifiers',ExperienceMode::Expert);
            $verified=count(array_filter($batch['items'],static fn(array $item):bool =>
                $item['status']==='verified' && !empty($item['native_asset_id'])));
            if ($batch['state']==='staged') $triggers[]='manual_review';
            if ($batch['state']==='planned') {
                // An approval gate is required; the batch alone cannot prove
                // whether an independent Plan/Action approval is already granted.
                $triggers[]='approval_required';
            }
            if ($batch['state']==='executing') $triggers[]='running';
            if ($verified!==$batch['count'] && $batch['state']!=='staged') $triggers[]='external_outcome_unknown';
            if ($batch['state']==='verified' && $verified!==$batch['count']) $triggers[]='blocked';
            if ($batch['state']==='verified' && $verified===$batch['count']) $triggers[]='result_available';
        }
        $adaptive=$this->adaptive->prepare($request,$actor,'unified.property.bulk',$sections,$triggers,
            entity: $batch['batch_id']??'',operationClass:'action');
        if ($adaptive instanceof Response) return $adaptive;
        return new Response($this->twig->render('experience/property/bulk_intake.html.twig',[
            'adaptive'=>$adaptive,'csrfToken'=>$this->csrf->token($request),
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

    public function stageCsv(Request $request):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null || !$actor->isManager()) return new Response('Forbidden',403);
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token',400);
        $file=$request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            || !$file->isValid() || $file->getSize()===false || $file->getSize()>65536) {
            return new Response('Потрібен коректний CSV-файл до 64 КіБ.',422);
        }
        try {
            $bytes=file_get_contents($file->getPathname());
            if(!is_string($bytes)) throw new DomainException('CSV file cannot be read.');
            $items=$this->csv->parse($file->getClientOriginalName(),$bytes);
            $batch=$this->intake->stage($actor,$items);
            return new RedirectResponse('/workspace/property/bulk?batch='.$batch['batch_id'],303);
        } catch (DomainException|\InvalidArgumentException $e) {
            return new Response('CSV імпорт заблоковано: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),422);
        } catch (Throwable $e) {
            error_log('cos.property.bulk.csv '.$e->getMessage());
            return new Response('CSV імпорт недоступний.',500);
        }
    }

    public function propose(Request $request,string $batchId):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null || !$actor->isManager()) return new Response('Forbidden',403);
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token',400);
        try {
            $this->plans->propose($actor,$batchId,(string)$request->request->get('digest',''));
            return new RedirectResponse('/workspace/property/bulk?batch='.rawurlencode($batchId).'&notice=planned',303);
        } catch (DomainException|\InvalidArgumentException $e) {
            return new Response('Plan не сформовано: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),422);
        } catch(Throwable $e) {
            error_log('cos.property.bulk.ui.propose '.$e->getMessage());
            return new Response('Планування тимчасово недоступне.',500);
        }
    }
}
