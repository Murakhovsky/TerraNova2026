<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PropertySubmissionsViewModel;

final class PropertySubmissionsPresenter
{
    private const LABELS=[
        'draft'=>'Чернетки','submitted'=>'Подані','new'=>'Нові','review'=>'В роботі',
        'in_review'=>'На перевірці','needs_changes'=>'Потрібні правки','accepted'=>'Прийняті',
        'approved'=>'Схвалені','published'=>'Опубліковані','rejected'=>'Відхилені','spam'=>'Спам',
    ];

    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        string $status,
        int $page,
        int $perPage,
        ?string $error=null,
    ): PropertySubmissionsViewModel {
        $counts=is_array($data['counts']??null)?$data['counts']:[];
        $pagination=is_array($data['pagination']??null)?$data['pagination']:[];
        $page=max(1,(int)($pagination['page']??$page));
        $perPage=max(10,(int)($pagination['per_page']??$perPage));
        $total=max(0,(int)($pagination['total']??$data['total']??0));

        $items=[];
        foreach($this->list($data['items']??null) as $submission){
            $state=(string)($submission['status']??'');
            $contact=trim((string)(($submission['owner_phone']??'')?:($submission['owner_email']??'')));
            $location=trim(implode(' · ',array_filter([
                (string)($submission['city']??''),
                (string)($submission['district']??''),
            ])));
            $items[]=[
                'id'=>(int)($submission['id']??0),
                'title'=>trim((string)($submission['title']??''))?:'Заявка на об’єкт',
                'subtitle'=>trim(implode(' · ',array_filter([
                    (string)($submission['submission_ref']??''),
                    (string)($submission['property_type']??''),
                    (string)($submission['deal_type']??''),
                    $location,
                ]))),
                'meta'=>trim(implode(' · ',array_filter([
                    (string)($submission['owner_name']??''),
                    $contact,
                    (string)($submission['created_at']??''),
                ]))),
                'statusLabel'=>self::LABELS[$state]??($state!==''?$state:'—'),
                'statusTone'=>$this->tone($state),
                'href'=>'/property/submission/'.(int)($submission['id']??0),
            ];
        }

        $statusOptions=[];
        foreach(self::LABELS as $code=>$label){
            $statusOptions[]=['code'=>$code,'label'=>$label,'count'=>(int)($counts[$code]??0)];
        }

        return new PropertySubmissionsViewModel(
            status:$status,
            items:$items,
            counts:$counts,
            statusOptions:$statusOptions,
            total:$total,
            page:$page,
            perPage:$perPage,
            previousUrl:$page>1?$this->url($status,$page-1,$perPage):null,
            nextUrl:$page*$perPage<$total?$this->url($status,$page+1,$perPage):null,
            error:$error,
        );
    }

    private function tone(string $status): string
    {
        return match($status){
            'submitted','new','review','in_review'=>'info',
            'needs_changes'=>'warning',
            'accepted','approved','published'=>'positive',
            'rejected','spam'=>'danger',
            default=>'neutral',
        };
    }

    private function url(string $status,int $page,int $perPage): string
    {
        $query=['page'=>$page,'per_page'=>$perPage];
        if($status!=='')$query['status']=$status;
        return '/property/submissions?'.http_build_query($query);
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if(!is_array($value))return [];
        return array_values(array_filter($value,'is_array'));
    }
}
