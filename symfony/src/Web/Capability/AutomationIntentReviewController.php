<?php
declare(strict_types=1);

namespace App\Web\Capability;

use App\Persistence\Capability\CanonicalAutomationIntentStore;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class AutomationIntentReviewController
{
    public function __construct(
        private CanonicalAutomationIntentStore $intents,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private Environment $twig,
    ) {}

    public function index(Request $request):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null) return new RedirectResponse('/auth/login');
        try {
            $list=$this->intents->pending($actor);
            $id=(string)$request->query->get('intent','');
            $current=$id!==''?$this->intents->inspect($actor,$id):null;
            return new Response($this->twig->render('experience/capability/automation_intents.html.twig',[
                'intents'=>$list,'current'=>$current,'csrfToken'=>$this->csrf->token($request),
            ]),200,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex,nofollow']);
        } catch(DomainException) { return new Response('Forbidden or missing intent.',403); }
        catch(Throwable $e) { error_log('cos.automation.intent.view '.$e->getMessage());
            return new Response('Automation review unavailable.',503); }
    }

    public function adopt(Request $request,string $intentId):Response
    {
        $actor=$this->tenants->current();
        if ($actor===null) return new Response('Forbidden',403);
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF',400);
        try {
            $adopted=$this->intents->adopt($actor,$intentId,(string)$request->request->get('digest',''));
            return new RedirectResponse('/workspace/property/bulk?batch='.rawurlencode($adopted['batch_id']),303);
        } catch(DomainException|\InvalidArgumentException $e) {
            return new Response('Review blocked: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),422);
        } catch(Throwable $e) { error_log('cos.automation.intent.adopt '.$e->getMessage());
            return new Response('Automation review unavailable.',503); }
    }
}
