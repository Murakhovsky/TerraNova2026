<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Sales\Application\Contract\SalesActivityEvidenceReadPort;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;

/** Verify existing operator receipts and native state; never execute a command. */
final readonly class SalesActivityOutcomeEvidence
{
    private const OPERATIONS=[
        'sales.governed.activity.record'=>'activity.record',
        'sales.governed.activity.complete'=>'activity.complete',
        'sales.governed.activity.reschedule'=>'activity.reschedule',
        'sales.governed.followup.schedule'=>'followup.schedule',
        'sales.governed.property_match.record'=>'property.match',
    ];

    public function __construct(private SalesActivityEvidenceReadPort $native,
        private SalesMutationReceiptRepositoryInterface $receipts) {}

    public static function supports(string $type):bool { return isset(self::OPERATIONS[$type]); }

    public function verify(string $org,string $type,int $actor,string $target,string $key,array $input):string
    {
        $op=self::OPERATIONS[$type]??throw new DomainException('Unsupported activity proof.');
        $deal=$input['deal_id']??null;
        if($org===''||$actor<1||!is_int($deal)||$deal<1||(string)$deal!==$target||$key==='')
            throw new DomainException('Invalid activity proof identity.');
        ksort($input);
        $hash=hash('sha256',json_encode([$actor,$op,$input],JSON_THROW_ON_ERROR));
        $receipt=$this->receipts->find($org,'operator.'.$op,$key);
        if(!is_string($receipt)||preg_match('/^([a-f0-9]{64}):([1-9][0-9]{0,18})$/D',$receipt,$m)!==1
            ||!hash_equals($hash,$m[1])||(string)(int)$m[2]!==$m[2])
            throw new DomainException('Activity receipt does not match approved input.');
        $id=(int)$m[2];
        if($op==='property.match'){
            $property=$input['property_id']??null;
            if(!is_int($property)||$property<1)throw new DomainException('Invalid property reference.');
            $row=$this->native->propertyMatch($org,$deal,$property);
            $matches=is_array($row)&&(int)($row['property_id']??0)===$property
                &&($row['match_status']??null)===$input['match_status']
                &&(!array_key_exists('score',$input)||(int)$row['score']===$input['score'])
                &&(!array_key_exists('note',$input)||($row['note']??'')===$input['note']);
        }else{
            $row=$this->native->activity($org,$deal,$id);
            if(!is_array($row)||($row['cancelled_at']??null)!==null)
                throw new DomainException('Native activity unavailable or cancelled.');
            $due=isset($input['due_at'])?(new DateTimeImmutable($input['due_at']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'):null;
            $matches=match($op){
                'activity.record'=>($row['activity_type']??null)===$input['activity_type']
                    &&($row['title']??null)===$input['title']&&($row['body']??'')===($input['body']??'')
                    &&(($row['completed_at']??null)!==null)===$input['completed']
                    &&($row['due_at']??null)===$due,
                'followup.schedule'=>($row['activity_type']??null)==='followup'
                    &&($row['title']??null)===$input['title']&&($row['body']??'')===($input['body']??'')
                    &&($row['completed_at']??null)===null&&($row['due_at']??null)===$due,
                'activity.complete'=>($input['activity_id']??null)===$id&&($row['completed_at']??null)!==null,
                'activity.reschedule'=>($input['activity_id']??null)===$id&&($row['completed_at']??null)===null
                    &&($row['due_at']??null)===$due,
                default=>false,
            };
        }
        if(!$matches||($row['organization_id']??null)!==$org||(int)($row['client_case_id']??0)!==$deal
            ||(int)($row['id']??0)!==$id)
            throw new DomainException('Approved activity state no longer observable.');
        return 'sales:'.($op==='property.match'?'property_match':'activity').':'.$id.':'.$key;
    }
}
