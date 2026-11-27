<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Persistence\Workflow\DoctrineKernelExecutionRecoveryManager;
use App\Persistence\Workflow\DoctrineWorkflowRecoveryManager;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Model\WorkflowRecoveryPetition;
use Platform\Workflow\Model\WorkflowRecoveryResolution;
use Platform\Workflow\Model\WorkflowRunCommand;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/** Delegates only to the canonical recovery APIs. No replay, direct write or success inference. */
#[AsController]
final readonly class OperationsRecoveryController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private OperationsRecoveryUI $controls,
        private DoctrineWorkflowRecoveryManager $workflows,
        private DoctrineKernelExecutionRecoveryManager $kernel,
    ) {}

    public function submit(Request $request,string $runId): Response
    {
        $actor = $this->tenants->current();
        if ($actor === null || !$actor->isManager() || !$actor->allows(TenantPermissions::ACCESS)
            || !$actor->allows(TenantPermissions::MANAGE) || !$this->csrf->isValid($request)) {
            return new Response('Forbidden.',403);
        }
        if ($request->request->get('confirmed') !== '1') return new Response('Explicit confirmation required.',422);
        $org=$actor->organizationId()->value();
        $context=new WebExtensionContext($org,$actor->role()->value(),'workspace','operations','operations');
        $actionId=(string)$request->request->get('ui_action','');
        try {
            foreach ($this->controls->available($actor,$context,$runId) as $option) {
                if ($option['action']->id !== $actionId) continue;
                $identity=new WorkflowRunIdentity($org,$runId);
                $operation=$option['operation'];
                $resource=$option['resource'];
                $command=new WorkflowRunCommand($identity,$actor,$option['runRevision'],
                    'workflow-recovery:'.$runId,$operation==='apply'?$resource:null);
                $manager=$this->kernel->owns($identity)?$this->kernel:$this->workflows;
                if ($operation==='request') {
                    $resolution=WorkflowRecoveryResolution::from($option['resolution']);
                    $reason=trim((string)$request->request->get('reason',''));
                    $receipt=$resolution===WorkflowRecoveryResolution::VERIFIED_COMPLETE
                        ?trim((string)$request->request->get('receipt_reference','')):null;
                    if ($receipt !== null && strlen($receipt)>255) return new Response('Receipt reference is too long.',422);
                    // Stable across retries for the same actor, step and fenced intent.
                    $requestId=substr(hash('sha256',json_encode([$org,$actor->userId()->value(),$actionId],JSON_THROW_ON_ERROR)),0,32);
                    $petition=new WorkflowRecoveryPetition($command,$requestId,$option['step'],$option['stepRevision'],
                        $resolution,$reason,$receipt);
                    $manager->request($petition);
                } elseif ($operation==='approve') {
                    $manager->approve($command,$resource);
                } elseif ($operation==='apply') {
                    if ($manager instanceof DoctrineKernelExecutionRecoveryManager) $manager->reconcile($command,$resource);
                    else $manager->reconcile($command);
                } else return new Response('Unrecognized recovery action.',403);
                // Read back authoritative state instead of claiming business completion.
                return new RedirectResponse('/workspace/operations?operation='.rawurlencode('workflow:'.$runId),303);
            }
            return new Response('Recovery action is no longer available. Refresh the operation.',409);
        } catch (\LogicException|\ValueError $e) {
            return new Response('Recovery was not confirmed. Refresh the operation and review the canonical evidence.',409);
        } catch (\Throwable $e) {
            error_log('cos.operations.recovery.submit_failed '.$e::class);
            return new Response('Recovery unavailable. No outcome has been confirmed.',503);
        }
    }
}
