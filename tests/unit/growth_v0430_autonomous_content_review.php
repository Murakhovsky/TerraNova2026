<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Domain\AutonomousContentReviewPolicy;

function expectGrowthV0430(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$policy=new AutonomousContentReviewPolicy(
    ['email'=>'policy_auto_approve','linkedin'=>'human_review','phone'=>'blocked'],0.92,500
);

$email=$policy->evaluate('email',0.96,[],'Short evidence-bound message.');
expectGrowthV0430(($email['auto_approve']??false)===true,'Safe email draft should auto-approve.');

$low=$policy->evaluate('email',0.80,[],'Short message.');
expectGrowthV0430(($low['code']??null)==='draft_confidence_below_threshold','Low confidence must require review.');
expectGrowthV0430(($low['requires_human_review']??false)===true,'Low confidence must fall back to human review.');

$risk=$policy->evaluate('email',0.99,['unsupported_claim'],'Short message.');
expectGrowthV0430(($risk['code']??null)==='draft_risk_flags_require_review','Risk flags must prevent auto-approval.');

$linkedin=$policy->evaluate('linkedin',0.99,[],'Short message.');
expectGrowthV0430(($linkedin['code']??null)==='human_review_required','Human-review channel must never auto-approve.');

$phone=$policy->evaluate('phone',0.99,[],'Call brief.');
expectGrowthV0430(($phone['code']??null)==='content_review_blocked','Blocked channel must remain blocked.');

$long=$policy->evaluate('email',0.99,[],str_repeat('x',501));
expectGrowthV0430(($long['code']??null)==='draft_body_too_long','Body length guardrail must prevent auto-approval.');

echo "Growth V0.43 Autonomous Content Review policy contracts passed.\n";
