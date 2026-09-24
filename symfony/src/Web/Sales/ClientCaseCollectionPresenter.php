<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\ClientCaseCollectionViewModel;

final class ClientCaseCollectionPresenter
{
    private const TYPES = [
        'buy'=>'Купівля','sell'=>'Продаж','rent'=>'Оренда','lease_out'=>'Здача',
        'repair'=>'Ремонт','investment'=>'Інвестиція','management'=>'Управління',
        'inheritance'=>'Спадщина','other'=>'Інше',
    ];
    private const STATUS = ['active'=>'Активний','paused'=>'Пауза','closed'=>'Закритий','lost'=>'Втрачено'];
    private const PRIORITY = ['low'=>'Низький','normal'=>'Звичайний','high'=>'Високий','urgent'=>'Терміновий'];

    /** @param array<string,mixed> $data */
    public function present(array $data, ?string $error = null): ClientCaseCollectionViewModel
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
        $pipelines = $this->list($data['pipelines'] ?? null);
        $pipeline = $pipelines[0] ?? ['stages'=>[]];

        $stageOptions = [];
        foreach ($this->list($pipeline['stages'] ?? null) as $stage) {
            $id=(string)($stage['id']??''); $code=strtoupper(trim((string)($stage['code']??'')));
            if ($id==='' || $code==='') continue;
            $stageOptions[]=['id'=>$id,'code'=>$code,'label'=>(string)($stage['name']??$code)];
        }

        $managers=[];
        foreach ($this->list($data['managers']??null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $label=trim((string)($row['full_name']??'')) ?: trim((string)($row['email']??''));
            $managers[]=['id'=>$id,'label'=>$label!==''?$label:'#'.$id];
        }

        $propertyTypes=[];
        foreach ($this->list($data['property_types']??null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $propertyTypes[]=['id'=>$id,'label'=>(string)($row['name_uk']??$row['name']??'#'.$id)];
        }

        $locations=[];
        foreach ($this->list($data['locations']??null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $city=trim((string)($row['city']??'')); $region=trim((string)($row['region']??''));
            $locations[]=['id'=>$id,'label'=>trim($city.($city!==''&&$region!==''?', '.$region:'')) ?: '#'.$id];
        }

        $openCases=[];
        foreach ($this->list($data['open_cases']??null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $public=trim((string)($row['public_id']??'')); $person=trim((string)($row['full_name']??''));
            $openCases[]=['id'=>$id,'label'=>trim($public.($public!==''&&$person!==''?' / ':'').$person) ?: '#'.$id];
        }

        $cases=[]; $byStage=[];
        foreach ($this->list($data['cases']??null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $code=strtoupper(trim((string)($row['stage_code']??$row['stage']??'')));
            $type=(string)($row['type']??''); $priority=(string)($row['priority']??'normal');
            $property=trim((string)($row['property_type_name']??'').(!empty($row['location_city'])?' · '.(string)$row['location_city']:''),' ·');
            $contact=trim((string)($row['phone']??'')) ?: (trim((string)($row['email']??'')) ?: (trim((string)($row['telegram']??'')) ?: 'контакт не вказано'));
            $item=[
                'id'=>$id,
                'publicId'=>(string)($row['public_id']??'#'.$id),
                'title'=>(string)($row['title']??'Клієнтський кейс'),
                'person'=>(string)($row['full_name']??'Без імені'),
                'contact'=>$contact,
                'typeLabel'=>self::TYPES[$type]??$type,
                'priority'=>$priority,
                'priorityLabel'=>self::PRIORITY[$priority]??$priority,
                'propertyContext'=>$property,
                'manager'=>trim((string)($row['manager_name']??'')) ?: 'не призначено',
                'stageId'=>(string)($row['stage_id']??''),
                'stageCode'=>$code,
                'stageLabel'=>(string)($row['stage_name']??$code),
                'stageTone'=>$this->stageTone($code),
                'status'=>(string)($row['status']??'active'),
                'assignedUserId'=>(int)($row['assigned_user_id']??0),
                'budget'=>$this->money($row['budget_max']??null,(string)($row['currency']??'USD')),
                'relations'=>(int)($row['inquiry_count']??0).' заявок · '.(int)($row['match_count']??0).' підборів',
                'nextContact'=>trim((string)($row['next_contact_at']??'')) ?: 'не задано',
                'activityCount'=>(int)($row['activity_count']??0),
                'href'=>'/client-case/show/'.$id,
            ];
            $cases[]=$item; $byStage[$code][]=$item;
        }

        if ($stageOptions===[]) {
            $seen=[];
            foreach ($cases as $item) {
                if($item['stageCode']===''||isset($seen[$item['stageCode']]))continue;
                $seen[$item['stageCode']]=true;
                $stageOptions[]=['id'=>$item['stageId'],'code'=>$item['stageCode'],'label'=>$item['stageLabel']];
            }
        }

        $stageTabs=[[
            'key'=>'all','label'=>'Усі · '.(int)($stats['all']??count($cases)),
            'href'=>'/client-case','active'=>trim((string)($filters['stage']??''))==='',
        ]];
        $funnel=[];
        foreach ($stageOptions as $stage) {
            $code=(string)$stage['code']; $items=$byStage[$code]??[];
            $stageTabs[]=[
                'key'=>strtolower($code),
                'label'=>$stage['label'].' · '.(int)($stats[$code]??count($items)),
                'href'=>'/client-case?stage='.rawurlencode($code),
                'active'=>strtoupper((string)($filters['stage']??''))===$code,
            ];
            $funnel[]=[
                'code'=>$code,'label'=>$stage['label'],'count'=>count($items),
                'items'=>array_slice($items,0,6),'remaining'=>max(0,count($items)-6),
                'href'=>'/client-case?stage='.rawurlencode($code),
            ];
        }

        $unlinked=[];
        foreach (array_slice($this->list($data['unlinked_requests']??null),0,8) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $unlinked[]=[
                'id'=>$id,'name'=>(string)($row['full_name']??'Без імені'),
                'intent'=>(string)($row['request_intent']??'Заявка'),
                'contact'=>trim((string)($row['phone']??'')) ?: (trim((string)($row['email']??'')) ?: 'контакт не вказано'),
                'context'=>trim((string)($row['property_title']??'')) ?: (string)($row['source_page']??''),
            ];
        }

        return new ClientCaseCollectionViewModel(
            filters:[
                'q'=>(string)($filters['q']??''),'stage'=>(string)($filters['stage']??''),
                'type'=>(string)($filters['type']??''),'status'=>(string)($filters['status']??''),
                'priority'=>(string)($filters['priority']??''),'assigned_user_id'=>(int)($filters['assigned_user_id']??0),
                'sort'=>(string)($filters['sort']??'updated'),
            ],
            stageTabs:$stageTabs,
            stageOptions:array_map(static fn(array $s):array=>['id'=>(string)$s['id'],'label'=>(string)$s['label']],$stageOptions),
            managerOptions:$managers, propertyTypeOptions:$propertyTypes, locationOptions:$locations,
            openCaseOptions:$openCases, typeOptions:self::TYPES, statusOptions:self::STATUS,
            priorityOptions:self::PRIORITY,
            sortOptions:['updated'=>'Оновлені','newest'=>'Нові','next_contact'=>'Наступний контакт','budget'=>'Бюджет'],
            cases:$cases, funnelStages:$funnel, unlinkedRequests:$unlinked,
            total:(int)($stats['all']??count($cases)), error:$error,
        );
    }

    private function stageTone(string $stage): string
    {
        return match($stage){'WON'=>'positive','LOST'=>'danger','NEGOTIATION','MEETING','PROPOSAL','QUALIFIED'=>'info',default=>'neutral'};
    }

    private function money(mixed $amount,string $currency): string
    {
        if($amount===null||$amount==='')return 'не задано';
        return number_format((float)$amount,0,'.',' ').' '.($currency!==''?$currency:'USD');
    }

    private function list(mixed $value): array
    {
        return is_array($value)?array_values(array_filter($value,'is_array')):[];
    }
}
