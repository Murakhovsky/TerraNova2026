<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

final readonly class PageDeliveryEvidenceBuilder
{
    public function build(PageDeliveryContextPackage $package): PageDeliveryEvidenceContract
    {
        return new PageDeliveryEvidenceContract(
            pageId: $package->pageId,
            requiredChecks: [
                'registry_contract' => 'PASS',
                'architecture' => 'PASS',
                'functional' => 'PASS',
                'browser' => 'PASS',
                'desktop' => 'PASS',
                'mobile' => 'PASS',
                'horizontal_overflow' => 'PASS',
                'keyboard' => 'PASS',
                'accessibility_axe_aa' => 'PASS',
                'browser_errors' => 'PASS',
                'ci' => 'PASS',
            ],
            requiredArtifacts: [
                'changed_files',
                'tests_run',
                'acceptance_criteria_evidence',
                'desktop_screenshot',
                'mobile_screenshot',
                'accessibility_report',
                'ci_revision',
                'pull_request',
                'review_report',
                'qa_report',
            ],
            humanGate: [
                'required' => true,
                'decision' => 'ACCEPT|REQUEST_CHANGES',
                'may_auto_set_human_acceptance' => false,
                'may_auto_promote_v1_ready' => false,
            ],
        );
    }
}
