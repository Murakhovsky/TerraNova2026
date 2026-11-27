<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$get = static fn(string $file): string => (string)file_get_contents($root.'/'.$file);
$ai = 'symfony/src/Web/Experience/AI/';
$twig = 'symfony/templates/experience/ai/';
$controller = $get($ai.'AIPanelController.php');
$adapter = $get($ai.'HermesWorkspaceAdapter.php');
$runtime = $get($ai.'AgentWorkspaceRuntime.php');
$panel = $get($twig.'ai_panel_frame.html.twig');
$hermes = $get($twig.'hermes_workspace.html.twig');

foreach (['AgentWorkspaceAccess::allows','AgentWorkspaceScope::permitsEntity','AgentWorkspaceScope::permitsRun',
    'scopeResolved','recentForSubject','recentForOrganization','no-store, private','WorkspaceShellFactory','SessionCsrfValidator'] as $marker) {
    if (!str_contains($controller,$marker)) throw new RuntimeException('UX06 controller missing: '.$marker);
}
foreach (['HermesAnalystReadinessService','MarketingCampaignService','MarketingAnalystReviewService',
    'AgentRuntimeAdapterInterface','live_verified','tool_limit','retry_limit','UNAVAILABLE','journal_available','planning_available','token_budget_verified','remote_cancel_available','ActiveModuleResolver'] as $marker) {
    if (!str_contains($adapter,$marker)) throw new RuntimeException('UX06 owner adapter missing: '.$marker);
}
foreach (['PDO','Doctrine\\','curl_','->post(','->execute(','->recommend(','CredentialVault','new ActionProposal','->propose('] as $forbidden) {
    if (str_contains($controller,$forbidden)||str_contains($adapter,$forbidden)) {
        throw new RuntimeException('UX06 crossed canonical execution/secret boundary: '.$forbidden);
    }
}
foreach (['AutowireIterator','Ambiguous Agent runtime binding','AgentWorkspaceAccess::allows'] as $marker) {
    if (!str_contains($runtime,$marker)) throw new RuntimeException('UX06 runtime dispatch missing: '.$marker);
}
if (str_contains($controller, "['campaign']") || !str_contains($panel, "runtime.presentation == 'hermes_marketing'")) {
    throw new RuntimeException('UX06 runtime-neutral controller or server-owned presentation selection regressed');
}
foreach (['/analyst-plans','csrf_token','idempotency_key','Independent Plan and Action approvals','No automatic retry','Native journal unavailable','Exact provider token budget','Remote cancellation'] as $marker) {
    if (!str_contains($hermes,$marker)) throw new RuntimeException('UX06 guarded delegation missing: '.$marker);
}
foreach ([$panel,$hermes,$get($twig.'workspace.html.twig'),$get('symfony/templates/components/experience/cos_agent_action.html.twig')] as $source) {
    if (str_contains($source,'|raw')||str_contains($source,'/v1/chat/completions')||str_contains($source,'/api/v1/')) {
        throw new RuntimeException('UX06 template owns unsafe rendering or runtime execution');
    }
}
if (!str_contains($get('symfony/config/routes.yaml'),'path: /marketing/campaigns/{id}/analyst-plans')
    || !str_contains($get('symfony/src/Web/Marketing/MarketingPageController.php'),'$this->csrf->isValid($request)')
    || !str_contains($get('app/Domains/Marketing/Automation/Action/MarketingAnalystRunHandler.php'),'$this->workerAdmission->assertAuthorized($action)')) {
    throw new RuntimeException('UX06 canonical owner delegation path changed');
}
echo "UX06 canonical AI workspace contract PASS.\n";
