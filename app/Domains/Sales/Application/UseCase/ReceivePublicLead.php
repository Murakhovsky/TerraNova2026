<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\InboundCaseResolverInterface;
use Domains\Sales\Application\Contract\InboundLeadRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use RuntimeException;
use Domains\Sales\Application\DTO\PublicLeadResult;
use Domains\Sales\Automation\Event\LeadCreated;
use Domains\Sales\Model\LeadStatus;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ReceivePublicLead
{
    public function __construct(
        private InboundLeadRepositoryInterface $leads,
        private InboundCaseResolverInterface $cases,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
        private SalesMutationReceiptRepositoryInterface $receipts,
    ) {
    }

    public function execute(array $input, string $sourcePage,?int $actorId=null,?string $correlationId=null): PublicLeadResult
    {
        if ($actorId!==null&&($actorId<1||$sourcePage!=='operator-intake'||trim((string)$correlationId)==='')) return PublicLeadResult::rejected('invalid_operator');
        foreach (['website','full_name','name','phone','email','role','deal_type','request_type','message','comment','telegram','request_intent','submission_id','property_id'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field]) && !($field==='property_id' && is_int($input[$field]))) return PublicLeadResult::rejected('invalid_input');
        }
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return PublicLeadResult::accepted();
        }

        $name = trim((string) ($input['full_name'] ?? $input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        if ($name === '' || ($phone === '' && $email === '')) {
            return PublicLeadResult::rejected('contact_required');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return PublicLeadResult::rejected('invalid_email');
        }

        $key=$input['submission_id']??'';
        if (preg_match('/^[a-f0-9]{32}$/D',$key)!==1) return PublicLeadResult::rejected('submission_id_required');
        $rawProperty=$input['property_id']??null;
        if ($rawProperty!==null && $rawProperty!=='' && preg_match('/^[1-9][0-9]*$/D',(string)$rawProperty)!==1) return PublicLeadResult::rejected('invalid_property');
        $propertyId=$this->cases->resolvePropertyId($rawProperty);
        if ($rawProperty!==null && $rawProperty!=='' && $propertyId===null) return PublicLeadResult::rejected('invalid_property');
        $intent=trim((string)($input['request_intent']??'general_contact'));
        if (!in_array($intent,['presentation','viewing','similar_search','general_contact'],true)) return PublicLeadResult::rejected('invalid_input');
        if (mb_strlen($name)>160 || mb_strlen($phone)>50 || mb_strlen($email)>160) return PublicLeadResult::rejected('invalid_input');
        $message=mb_substr(trim((string)($input['message']??$input['comment']??'')),0,4000);
        $telegram=mb_substr(trim((string)($input['telegram']??'')),0,80);
        $sourcePage=mb_substr($sourcePage,0,255);
        $lead=[
            'property_id'=>$propertyId,'full_name'=>$name,'phone'=>$phone!==''?$phone:null,'email'=>$email!==''?$email:null,
            'role'=>$this->role((string)($input['role']??'buyer')),
            'deal_type'=>$this->dealType((string)($input['deal_type']??$input['request_type']??'consultation')),
            'message'=>$message!==''?$message:null,'source_page'=>$sourcePage,'request_intent'=>$intent,
        ];
        $caseInput=$lead+['telegram'=>$telegram!==''?$telegram:null];
        $fingerprint=hash('sha256',json_encode($actorId===null?$caseInput:['actor_id'=>$actorId,'lead'=>$caseInput],JSON_THROW_ON_ERROR));
        $operation=$actorId===null?'public.lead.create':'operator.lead.intake';

        return $this->transactions->transactional(function()use($caseInput,$lead,$sourcePage,$key,$fingerprint,$operation,$actorId,$correlationId):PublicLeadResult{
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$this->receipts->claim($this->organizationId,$operation,$key,$pending)) {
                $existing=$this->receipts->find($this->organizationId,$operation,$key);
                if ($existing===null || preg_match('/^([a-f0-9]{64}):([1-9][0-9]*)$/D',$existing,$m)!==1
                    || !hash_equals($m[1],$fingerprint) || (string)(int)$m[2]!==$m[2]
                    || !$this->leads->existsForUpdate($this->organizationId,(int)$m[2])) return PublicLeadResult::rejected('submission_conflict');
                return PublicLeadResult::accepted((int)$m[2],true);
            }
            $case=$this->cases->resolvePersonAndCase($caseInput,$actorId,$correlationId);
            $leadId=$this->leads->create($this->organizationId,[
                'person_id'=>$case['person_id'],'client_case_id'=>$case['client_case_id'],
            ]+$lead);
            if ($case['client_case_id']!==null) $this->cases->attachRequest($case['client_case_id'],$leadId);
            $this->events->publish(LeadCreated::create(
                bin2hex(random_bytes(16)),$this->organizationId,(string)$leadId,
                ['client_case_id'=>$case['client_case_id'],'source_page'=>$sourcePage,'status'=>LeadStatus::New->value],
                new EventMetadata($correlationId??bin2hex(random_bytes(16)),null,$actorId===null?'SYSTEM':'USER',$actorId===null?'public-web':(string)$actorId),
            ));
            if (!$this->leads->existsForUpdate($this->organizationId,$leadId)
                || !$this->receipts->complete($this->organizationId,$operation,$key,$pending,$fingerprint.':'.$leadId)) {
                throw new RuntimeException('Public lead receipt could not be verified.');
            }
            return PublicLeadResult::accepted($leadId);
        });
    }

    private function role(string $value): string
    {
        return ['buyer'=>'buyer','покупець'=>'buyer','owner'=>'seller','seller'=>'seller','власник'=>'seller',
            'investor'=>'investor','інвестор'=>'investor','realtor'=>'realtor','рієлтор'=>'realtor',
            'developer'=>'developer','забудовник'=>'developer','partner'=>'partner','партнер'=>'partner']
            [mb_strtolower(trim($value))] ?? 'other';
    }

    private function dealType(string $value): string
    {
        return ['sale'=>'sale','купівля'=>'sale','продаж'=>'sale','rent'=>'rent','оренда'=>'rent',
            'investment'=>'investment','інвестиції'=>'investment','заявка інвестора'=>'investment',
            'consultation'=>'consultation','партнерство'=>'consultation'][mb_strtolower(trim($value))] ?? 'consultation';
    }
}
