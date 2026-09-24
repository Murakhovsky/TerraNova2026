<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\ClientCaseWorkspaceViewModel;

final class ClientCaseWorkspacePresenter
{
    private const TYPES = [
        'buy'=>'Купівля','sell'=>'Продаж','rent'=>'Оренда','lease_out'=>'Здача',
        'repair'=>'Ремонт','investment'=>'Інвестиція','management'=>'Управління',
        'inheritance'=>'Спадщина','other'=>'Інше',
    ];
    private const STATUS = ['active'=>'Активний','paused'=>'Пауза','closed'=>'Закритий','lost'=>'Втрачено'];
    private const PRIORITY = ['low'=>'Низький','normal'=>'Звичайний','high'=>'Високий','urgent'=>'Терміновий'];
    private const MATCH_STATUS = [
        'suggested'=>'Запропоновано','sent'=>'Відправлено','interested'=>'Цікавить',
        'viewing'=>'Показ','rejected'=>'Відхилено','deal'=>'Угода',
    ];
    private const INTENT = [
        'general_contact'=>'Загальний контакт','presentation'=>'Презентація',
        'viewing'=>'Перегляд','similar_search'=>'Підібрати схожий',
    ];
    private const ACTIVITY = [
        'note'=>'Нотатка','call'=>'Дзвінок','message'=>'Повідомлення','meeting'=>'Зустріч',
        'viewing'=>'Показ','offer'=>'Офер','task'=>'Задача',
    ];

    /** @param array<string,mixed> $data */
    public function present(array $data, int $caseId, ?string $error = null): ClientCaseWorkspaceViewModel
    {
        $case = is_array($data['case'] ?? null) ? $data['case'] : [];
        $status = (string)($case['status'] ?? '');
        $stage = (string)($case['stage_name'] ?? $case['stage'] ?? '');
        $type = (string)($case['type'] ?? '');
        $currency = (string)($case['currency'] ?? 'USD');

        $managers = [];
        foreach ($this->list($data['managers'] ?? null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $label=trim((string)($row['full_name']??'')) ?: trim((string)($row['email']??''));
            $managers[]=['id'=>$id,'label'=>$label!==''?$label:'#'.$id];
        }

        $propertyTypes = [];
        foreach ($this->list($data['property_types'] ?? null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $propertyTypes[]=['id'=>$id,'label'=>(string)($row['name_uk']??$row['name']??'#'.$id)];
        }

        $locations = [];
        foreach ($this->list($data['locations'] ?? null) as $row) {
            $id=(int)($row['id']??0); if($id<=0)continue;
            $city=trim((string)($row['city']??'')); $region=trim((string)($row['region']??''));
            $locations[]=['id'=>$id,'label'=>trim($city.($city!==''&&$region!==''?', '.$region:'')) ?: '#'.$id];
        }

        $stages = [];
        foreach ($this->list($data['pipeline_stages'] ?? null) as $row) {
            $id=(string)($row['id']??''); if($id==='')continue;
            $stages[]=['id'=>$id,'label'=>(string)($row['name']??$row['code']??$id)];
        }

        $timeline = [];
        foreach ($this->list($data['activities'] ?? null) as $row) {
            $typeLabel=(string)($row['activity_type']??'activity');
            $user=trim((string)($row['user_name']??''));
            $body=trim((string)($row['body']??''));
            $timeline[]=[
                'time'=>(string)($row['created_at']??''),
                'title'=>(string)($row['title']??$typeLabel),
                'copy'=>trim($typeLabel.($user!==''?' · '.$user:'').($body!==''?' · '.$body:''),' ·'),
                'tone'=>'neutral',
            ];
        }

        $inbound = [];
        foreach ($this->list($data['inbound_requests'] ?? null) as $row) {
            $slug=trim((string)($row['property_slug']??''));
            $intent=(string)($row['request_intent']??'general_contact');
            $inbound[]=[
                'id'=>(int)($row['id']??0),
                'intent'=>self::INTENT[$intent]??$intent,
                'dealType'=>(string)($row['deal_type']??''),
                'propertyLabel'=>$slug!=='' ? (string)(($row['property_title']??'') ?: $slug) : 'без обʼєкта',
                'propertyHref'=>$slug!=='' ? '/property/show/'.$slug : null,
                'message'=>mb_substr((string)($row['message']??''),0,120),
                'createdAt'=>(string)($row['created_at']??''),
            ];
        }

        $matches = [];
        foreach ($this->list($data['property_matches'] ?? null) as $row) {
            $slug=trim((string)($row['slug']??''));
            $matches[]=[
                'id'=>(int)($row['id']??0),
                'slug'=>$slug,
                'title'=>(string)($row['title']??($slug!==''?$slug:'Обʼєкт')),
                'city'=>(string)($row['city']??''),
                'price'=>$this->money($row['price_amount']??null,(string)($row['price_currency']??'USD')),
                'matchStatus'=>(string)($row['match_status']??'suggested'),
                'matchStatusLabel'=>self::MATCH_STATUS[(string)($row['match_status']??'')]??(string)($row['match_status']??''),
                'score'=>$row['score']===null||$row['score']==='' ? '' : (string)(int)$row['score'],
                'note'=>(string)($row['note']??''),
                'href'=>$slug!==''?'/property/show/'.$slug:null,
                'pdfHref'=>$slug!==''?'/property/pdf/'.$slug:null,
            ];
        }

        $intelligence = is_array($data['intelligence'] ?? null) ? $data['intelligence'] : [];
        $decision = is_array($intelligence['decision'] ?? null) ? $intelligence['decision'] : null;
        $aiDecision = null;
        if ($decision !== null) {
            $aiDecision=[
                'decision'=>(string)($decision['decision']??''),
                'confidence'=>(int)round(((float)($decision['confidence']??0))*100),
                'reason'=>(string)($decision['reason']??''),
                'agent'=>(string)(($decision['agent_name']??'') ?: 'Agent'),
                'model'=>(string)(($decision['model']??'') ?: 'model pending'),
            ];
        }

        $aiActions = [];
        foreach ($this->list($intelligence['actions'] ?? null) as $row) {
            $aiActions[]=[
                'id'=>(int)($row['id']??0),
                'type'=>(string)($row['type']??'Action'),
                'status'=>(string)($row['status']??''),
                'statusTone'=>$this->actionTone((string)($row['status']??'')),
                'risk'=>(string)($row['risk_level']??''),
                'riskTone'=>$this->riskTone((string)($row['risk_level']??'')),
                'reason'=>(string)(($row['decision_reason']??'') ?: 'Recommended next action for this Deal.'),
                'resultStatus'=>(string)($row['result_status']??''),
                'resultError'=>(string)($row['result_error']??''),
                'approvalId'=>(int)($row['approval_id']??0),
                'approvalStatus'=>(string)($row['approval_status']??''),
            ];
        }

        $manager=(string)(($case['manager_name']??'') ?: 'не призначено');
        $budget=$this->money($case['budget_max']??null,$currency);
        $nextContact=(string)(($case['next_contact_at']??'') ?: 'не задано');
        $propertyType=(string)(($case['property_type_name']??'') ?: 'не задано');
        $city=(string)(($case['location_city']??'') ?: 'не задано');
        $subtitle=trim(
            (string)($case['full_name']??'')
            .' · '.(string)(($case['phone']??'') ?: 'телефон не вказано')
            .(!empty($case['email'])?' · '.(string)$case['email']:''),
            ' ·',
        );

        return new ClientCaseWorkspaceViewModel(
            id:$caseId,
            identity:trim((string)($case['public_id']??'').' / '.(string)($case['person_public_id']??''),' /'),
            title:(string)($case['title']??('Client Case #'.$caseId)),
            subtitle:$subtitle,
            statusLabel:self::STATUS[$status]??$status,
            statusTone:$this->statusTone($status),
            meta:[
                ['label'=>'Тип','value'=>self::TYPES[$type]??$type],
                ['label'=>'Етап','value'=>$stage],
                ['label'=>'Менеджер','value'=>$manager],
                ['label'=>'Бюджет','value'=>$budget],
                ['label'=>'Наступний контакт','value'=>$nextContact],
            ],
            kpis:[
                ['label'=>'Заявки','value'=>(string)count($inbound),'hint'=>'прив’язані'],
                ['label'=>'Підбори','value'=>(string)count($matches),'hint'=>'об’єкти'],
                ['label'=>'Бюджет','value'=>$budget,'hint'=>'до'],
                ['label'=>'Менеджер','value'=>$manager,'hint'=>'відповідальний'],
                ['label'=>'Тип обʼєкта','value'=>$propertyType,'hint'=>'критерій'],
                ['label'=>'Місто','value'=>$city,'hint'=>'локація'],
            ],
            form:[
                'full_name'=>(string)($case['full_name']??''),
                'phone'=>(string)($case['phone']??''),
                'email'=>(string)($case['email']??''),
                'telegram'=>(string)($case['telegram']??''),
                'title'=>(string)($case['title']??''),
                'type'=>$type,
                'status'=>$status,
                'stage_id'=>(string)($case['stage_id']??''),
                'priority'=>(string)($case['priority']??'normal'),
                'assigned_user_id'=>(int)($case['assigned_user_id']??0),
                'source'=>(string)($case['source']??''),
                'property_type_id'=>(int)($case['property_type_id']??0),
                'location_id'=>(int)($case['location_id']??0),
                'budget_min'=>$this->scalar($case['budget_min']??null),
                'budget_max'=>$this->scalar($case['budget_max']??null),
                'currency'=>$currency,
                'area_min'=>$this->scalar($case['area_min']??null),
                'area_max'=>$this->scalar($case['area_max']??null),
                'next_contact_at'=>$this->datetimeLocal($case['next_contact_at']??null),
                'description'=>(string)($case['description']??''),
                'person_notes'=>(string)($case['person_notes']??''),
            ],
            typeOptions:self::TYPES,statusOptions:self::STATUS,priorityOptions:self::PRIORITY,
            stageOptions:$stages,managerOptions:$managers,propertyTypeOptions:$propertyTypes,locationOptions:$locations,
            activityOptions:self::ACTIVITY,timeline:$timeline,inboundRequests:$inbound,propertyMatches:$matches,
            matchStatusOptions:self::MATCH_STATUS,aiDecision:$aiDecision,aiActions:$aiActions,
            requestMatchCount:count($this->list($data['request_matches']??null)),
            error:$error,
        );
    }

    private function statusTone(string $status): string
    {
        return match($status){'active'=>'positive','paused'=>'warning','lost'=>'danger',default=>'neutral'};
    }

    private function actionTone(string $status): string
    {
        return match($status){'FAILED','REJECTED'=>'danger','PENDING_APPROVAL'=>'warning','COMPLETED'=>'positive','QUEUED'=>'info',default=>'neutral'};
    }

    private function riskTone(string $risk): string
    {
        return match(strtolower($risk)){'high','critical'=>'danger','medium'=>'warning','low'=>'positive',default=>'neutral'};
    }

    private function money(mixed $amount,string $currency): string
    {
        if($amount===null||$amount==='')return 'не задано';
        return number_format((float)$amount,0,'.',' ').' '.($currency!==''?$currency:'USD');
    }

    private function scalar(mixed $value): string
    {
        return $value===null ? '' : (string)$value;
    }

    private function datetimeLocal(mixed $value): string
    {
        if($value===null||trim((string)$value)==='')return '';
        $timestamp=strtotime((string)$value);
        return $timestamp===false?'':date('Y-m-d\\TH:i',$timestamp);
    }

    private function list(mixed $value): array
    {
        return is_array($value)?array_values(array_filter($value,'is_array')):[];
    }
}
