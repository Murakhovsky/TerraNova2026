<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DomainException;

/** Export-ready Sales report data, independent of the common Workspace renderer. */
final readonly class SalesReportService
{
    private const REPORTS=['outcomes','forecast','conversion','stages'];
    public function __construct(private SalesCommercialAnalyticsService $analytics) {}

    public function read(string $org,int $actor,array $input):array
    {
        if (array_diff(array_keys($input),['report_id','scope','owner_id','pipeline_id','from','to'])!==[]
            ||!in_array($input['report_id']??null,self::REPORTS,true)) throw new DomainException('Unknown Sales report.');
        $id=$input['report_id'];unset($input['report_id']);
        $data=$this->analytics->read($org,$actor,$id,$input);
        $rows=[];
        $add=static function(string $metric,?string $value,string $unit,?string $currency=null,
            ?string $bucket=null,?string $pipeline=null,?string $fromStage=null,?string $toStage=null)use(&$rows):void{
            if (count($rows)>=10000) throw new DomainException('Sales report exceeds export bound; narrow the filters.');
            $rows[]=['metric'=>$metric,'value'=>$value,'unit'=>$unit,'currency'=>$currency,'bucket'=>$bucket,
                'pipeline_id'=>$pipeline,'from_stage_id'=>$fromStage,'to_stage_id'=>$toStage];
        };
        if ($id==='outcomes') {
            foreach (['won_count','lost_count','unattributed_count','won_without_booked_value'] as $key) $add($key,(string)$data[$key],'count');
            $add('win_rate_percent',$data['win_rate_percent'],'percent');
            foreach ($data['booked_by_currency'] as $row) {
                $add('booked_deal_count',(string)$row['deal_count'],'count',$row['currency']);
                $add('booked_value',$row['booked_value'],'money',$row['currency']);
            }
        } elseif ($id==='forecast') {
            foreach ($data['buckets'] as $bucket) {
                foreach (['deal_count','unknown_value_count'] as $key) $add($key,(string)$bucket[$key],'count',null,$bucket['bucket']);
                foreach ($bucket['money_by_currency'] as $row) {
                    foreach (['deal_count','weighted_count'] as $key) $add($key,(string)$row[$key],'count',$row['currency'],$bucket['bucket']);
                    foreach (['pipeline_value','weighted_value'] as $key) $add($key,$row[$key],'money',$row['currency'],$bucket['bucket']);
                }
            }
        } elseif ($id==='conversion') {
            foreach (['lead_count','linked_count','event_confirmed_count','link_without_event_count','dangling_link_count'] as $key) $add($key,(string)$data[$key],'count');
            $add('conversion_percent',$data['conversion_percent'],'percent');
        } else {
            foreach ($data['items'] as $row) {
                foreach (['entry_count','distinct_deal_count','complete_count','partial_count','estimated_count','duration_count','average_duration_seconds'] as $key) {
                    $add($key,$row[$key]===null?null:(string)$row[$key],$key==='average_duration_seconds'?'seconds':'count',
                        null,null,$row['pipeline_id'],$row['from_stage_id'],$row['to_stage_id']);
                }
            }
        }
        return ['report_id'=>$id,'schema_version'=>'1.0.0','basis'=>$data['basis'],'from'=>$data['from'],
            'to_exclusive'=>$data['to_exclusive'],'owner_id'=>$data['owner_id'],'pipeline_id'=>$data['pipeline_id'],
            'columns'=>['metric','value','unit','currency','bucket','pipeline_id','from_stage_id','to_stage_id'],
            'row_count'=>count($rows),'rows'=>$rows];
    }
}
