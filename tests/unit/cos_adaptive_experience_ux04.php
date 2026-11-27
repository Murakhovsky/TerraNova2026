<?php
declare(strict_types=1);

$ui=dirname(__DIR__,2).'/symfony/src/Web/Experience/';
foreach ([
    'Action/UIActionIntent.php','Action/UIActionDangerLevel.php','Action/UIActionPlacement.php',
    'Action/UIActionConfirmation.php','Action/UIAction.php',
    'Adaptive/ExperienceMode.php','Adaptive/ExperienceSemantic.php','Adaptive/ExperienceContext.php',
    'Adaptive/ExperienceComposition.php','Adaptive/AdaptiveExperienceResolver.php',
] as $file) require_once $ui.$file;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionIntent;
use App\Web\Experience\Adaptive\AdaptiveExperienceResolver;
use App\Web\Experience\Adaptive\ExperienceContext;
use App\Web\Experience\Adaptive\ExperienceMode;
use App\Web\Experience\Adaptive\ExperienceSemantic;

$signals=['approval_required','blocked','failed','running','result_available','risk_attention',
    'manual_review','stale_data','external_outcome_unknown','permission_unavailable'];
$descriptors=[
    new ExperienceSemantic('ux.summary','component','Summary','primary',ExperienceMode::Result,0,'always'),
    new ExperienceSemantic('ux.timeline','component','Lifecycle','supporting',ExperienceMode::Process,10,'mode_default'),
    new ExperienceSemantic('ux.trace','component','Trace','expert',ExperienceMode::Expert,20,'mode_default'),
    new ExperienceSemantic('ux.explicit','component','Optional','contextual',ExperienceMode::Expert,30,'explicit'),
    new ExperienceSemantic('ux.protected','component','Restricted','expert',ExperienceMode::Expert,40,'always',[],'sales.restricted'),
];
foreach ($signals as $signal) {
    $descriptors[]=new ExperienceSemantic('ux.'.$signal,'component',$signal,'contextual',
        ExperienceMode::Expert,100,'on_trigger',[$signal]);
}
$sections=array_map(static fn(ExperienceSemantic $s):array=>[
    'id'=>$s->id,'level'=>$s->recommendedMode->defaultLevel(),'semantic'=>$s,
],$descriptors);
$actions=[
    new UIAction('ux.read','Read',UIActionIntent::View),
    new UIAction('ux.edit','Edit',UIActionIntent::Edit,permission:'write'),
    new UIAction('ux.denied','Denied',UIActionIntent::Edit,permission:'write',enabled:false,disabledReason:'Denied'),
    new UIAction('ux.insecure','Insecure',UIActionIntent::Edit),
    new UIAction('ux.restricted','Restricted',UIActionIntent::View),
    new UIAction('ux.approve','Approve',UIActionIntent::Approve,permission:'approve',priority:800),
    new UIAction('ux.reject','Reject',UIActionIntent::Reject,permission:'approve',priority:800),
    new UIAction('ux.approve_other','Approve second',UIActionIntent::Approve,permission:'approve',priority:900),
];
$actions[]=$actions[0]; // duplicate desktop/mobile action
$resolver=new AdaptiveExperienceResolver();
$compose=static fn(ExperienceMode $mode,array $triggers=[],array $expanded=[]):array=>
    $resolver->compose(new ExperienceContext('actor','tenant',$mode,sections:$sections,
        expandedSections:$expanded,decisionRequests:[['id'=>'approval-1']],
        blockingRisks:[['id'=>'risk-1']],actionCapabilities:['ux.restricted'=>'sales.restricted'],
        triggers:$triggers),$actions)->toArray();
$baseline=null;
foreach (ExperienceMode::cases() as $mode) {
    $ordinary=$compose($mode);
    $all=$compose($mode,$signals,['ux.protected','ux.explicit']);
    foreach ($signals as $signal) {
        if (!in_array('ux.'.$signal,$all['visible_components'],true)
            || $all['disclosure_reasons']['ux.'.$signal]!=='contextual_trigger'
            || in_array('ux.'.$signal,$ordinary['visible_components'],true)) {
            throw new RuntimeException('Invalid contextual signal disclosure: '.$signal);
        }
    }
    if ($all['decision_requests']!==[['id'=>'approval-1']] || $all['blocking_risks']!==[['id'=>'risk-1']]
        || in_array('ux.protected',$all['visible_components'],true)
        || !in_array('ux.explicit',$all['visible_components'],true)
        || in_array('ux.explicit',$ordinary['visible_components'],true)) {
        throw new RuntimeException('Mode, explicit expansion or trigger bypassed a disclosure invariant.');
    }
    $ids=[...$all['primary_actions'],...$all['secondary_actions']];
    sort($ids);
    if ($baseline!==null && $baseline!==$ids) throw new RuntimeException('Mode changed authorized action set.');
    $baseline=$ids;
    if (count($ids)!==5 || count(array_unique($ids))!==count($ids)
        || array_diff(['ux.approve','ux.approve_other','ux.reject'],$all['primary_actions'])!==[]) {
        throw new RuntimeException('Decisions hidden, missing permission or duplicate action.');
    }
}
foreach ([
    static fn()=>new ExperienceContext('actor','tenant',ExperienceMode::Expert,triggers:['grant_admin']),
    static fn()=>new ExperienceContext('actor','tenant',ExperienceMode::Expert,sections:[
        ['id'=>'ux.forged','level'=>0,'semantic'=>$descriptors[0]],
    ]),
] as $invalid) {
    try { $invalid(); throw new RuntimeException('Invalid trigger or descriptor accepted.'); }
    catch (InvalidArgumentException) {}
}
echo "UX-04: ten trusted triggers, mode parity, explicit expansion, capability fence, action dedup and decision visibility PASS.\n";
