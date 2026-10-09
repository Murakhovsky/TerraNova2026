<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Documents\Automation\Action\PrepareProposalDraftHandler;
use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Platform\Documents\Contract\DocumentAttachmentPort;
use Platform\Documents\Contract\DocumentTemplateGenerationPort;

$gen = new class implements DocumentTemplateGenerationPort {
    public array $calls = [];
    public function generateFromTemplate(string $org,int $actor,string $corr,string $template,string $key,array $input):array {
        $this->calls[] = [$org,$actor,$corr,$template,$key,$input];
        return ['document_id'=>'DOC-1','status'=>'active'];
    }
};
$attachment = new class implements DocumentAttachmentPort {
    public array $calls = [];
    public bool $broken = false;
    public function attachExistingDocument(string $org,int $actor,string $corr,string $document,string $type,string $related,string $key):array {
        $this->calls[] = [$org,$actor,$corr,$document,$type,$related,$key];
        return $this->broken ? ['relation_id'=>'REL-1','document_id'=>'DOC-1','related_type'=>'sales.lead','related_id'=>'99']
            : ['relation_id'=>'REL-1','document_id'=>'DOC-1','related_type'=>'sales.lead','related_id'=>'7'];
    }
};
$growth = new class implements GrowthHandoffBoundary {
    public string $reference = '7';
    public array $calls = [];
    public function dispatch(string $org,int $actor,string $corr,string $candidate,string $key):array{return [];}
    public function handoffBrief(string $org,string $candidate):array {
        $this->calls[] = [$org,$candidate];
        return [
            'candidate'=>['status'=>'handed_off','candidate_id'=>$candidate],
            'latest_attempt'=>['status'=>'accepted','target_domain'=>'sales',
                'target_reference_type'=>'sales_lead','target_reference_id'=>$this->reference],
        ];
    }
    public function targets():array{return ['sales'];}
};
$sales = new class implements SalesWorkspaceReadModelInterface {
    public array $calls = [];
    public function dashboard(string $org,?int $owner=null):array{return [];}
    public function leads(string $org,array $filters=[]):array{return [];}
    public function lead(string $org,int $id):?array {
        $this->calls[]=[$org,$id];
        return $id === 7 ? ['id'=>7,'source'=>'growth-handoff'] : null;
    }
    public function deals(string $org,array $filters=[]):array{return [];}
    public function deal(string $org,int $id):?array{return null;}
    public function timeline(string $org,int $id,int $limit=100):array{return [];}
    public function pipelines(string $org):array{return [];}
    public function today(string $org,int $owner):array{return [];}
    public function metrics(string $org,int $days=30):array{return [];}
};
$states = new class implements ModuleStateRepositoryInterface {
    public bool $enableGrowth = true;
    public function enabledOverride(string $org,string $module):?bool {
        return $module !== 'growth' || $this->enableGrowth;
    }
    public function setEnabled(string $org,string $module,bool $enabled):void {}
};
$catalog = new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('growth','Growth','1.0.0'),new ModuleContributions()),
    new ModuleDefinition(new ModuleManifest('sales','Sales','1.0.0'),new ModuleContributions()),
    new ModuleDefinition(new ModuleManifest('documents','Documents','1.0.0'),new ModuleContributions()),
]);
$handler = new PrepareProposalDraftHandler($gen,$attachment,$growth,$sales,new ActiveModuleResolver($catalog,$states));
$make = static fn(string $candidate='C-1',array $params=['template_id'=>'T-1','variables'=>[]]):Action =>
    new Action('a-1','tenant-1',PrepareProposalDraftHandler::TYPE,'growth_candidate',$candidate,$params,
        'USER','42','APPROVAL_REQUIRED','HIGH','fed:1',new DateTimeImmutable('2026-10-09T12:00:00Z'),
        ActionStatus::Running,'corr');
$growth->reference='99';
if ($handler->execute($make())->successful || $gen->calls!==[]) {
    throw new RuntimeException('Unverified Sales Lead was allowed to generate a document.');
}
$growth->reference='7';
$sales->calls=[];
$states->enableGrowth=false;
if ($handler->execute($make())->successful || $gen->calls!==[]) {
    throw new RuntimeException('Disabled Growth module was not respected.');
}
$states->enableGrowth=true;
$ok=$handler->execute($make());
if (!$ok->successful || $ok->data['status']!=='prepared_not_sent'
    || $ok->data['goal_outcome_verified']!==false
    || count($gen->calls)!==1 || count($attachment->calls)!==1
    || $sales->calls!==[['tenant-1',7]]) {
    throw new RuntimeException('Native Document proposal generation + verified attachment failed.');
}
if ($gen->calls[0][4] === $attachment->calls[0][6]
    || !str_ends_with($gen->calls[0][4],'-generate')
    || !str_ends_with($attachment->calls[0][6],'-attach')) {
    throw new RuntimeException('Stable distinct idempotency sub-keys were not applied.');
}
$attachment->broken=true;
if ($handler->execute($make())->successful) {
    throw new RuntimeException('Forged/mismatched attachment was accepted.');
}
echo "Federation Documents proposal: real ports, native handoff + lead verification, tenant gate, attachment integrity PASS.\n";
