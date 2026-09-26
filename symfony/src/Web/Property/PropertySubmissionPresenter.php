<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PropertySubmissionViewModel;

final class PropertySubmissionPresenter
{
    private const LABELS=[
        'draft'=>'Чернетка','submitted'=>'Подана','new'=>'Нова','review'=>'В роботі',
        'in_review'=>'На перевірці','needs_changes'=>'Потрібні правки','accepted'=>'Прийнята',
        'approved'=>'Схвалена','published'=>'Опублікована','rejected'=>'Відхилена','spam'=>'Спам',
    ];

    /** @param array<string,mixed> $submission */
    public function present(array $submission,int $id,?string $error=null): PropertySubmissionViewModel
    {
        $status=(string)($submission['status']??'');
        $currency=trim((string)($submission['price_currency']??'USD'))?:'USD';
        $price=($submission['price_amount']??null)!==null
            ? number_format((float)$submission['price_amount'],0,'.',' ').' '.$currency
            : '—';
        $location=trim(implode(' · ',array_filter([
            (string)($submission['city']??''),
            (string)($submission['district']??''),
            (string)($submission['address']??''),
        ])));
        $contact=trim(implode(' · ',array_filter([
            (string)($submission['owner_name']??''),
            (string)($submission['owner_phone']??''),
            (string)($submission['owner_email']??''),
        ])));

        return new PropertySubmissionViewModel(
            id:$id,
            title:trim((string)($submission['title']??''))?:'Заявка на об’єкт',
            subtitle:$location!==''?$location:'Локація не вказана',
            identity:(string)($submission['submission_ref']??('Submission #'.$id)),
            statusLabel:self::LABELS[$status]??($status!==''?$status:'—'),
            statusTone:$this->tone($status),
            meta:[
                ['label'=>'Property type','value'=>(string)($submission['property_type']??'—')],
                ['label'=>'Deal type','value'=>(string)($submission['deal_type']??'—')],
                ['label'=>'Source','value'=>(string)($submission['source_type']??'—')],
                ['label'=>'Property ID','value'=>(string)($submission['property_id']??'—')],
            ],
            kpis:[
                ['label'=>'Price','value'=>$price,'hint'=>'submitted value'],
                ['label'=>'Area','value'=>$this->measure($submission['area_total']??null,'m²'),'hint'=>'total area'],
                ['label'=>'Rooms','value'=>(string)($submission['rooms']??'—'),'hint'=>'rooms'],
                ['label'=>'Created','value'=>(string)($submission['created_at']??'—'),'hint'=>'intake time'],
            ],
            details:[
                ['label'=>'Contact','value'=>$contact!==''?$contact:'—'],
                ['label'=>'Preferred contact','value'=>(string)($submission['preferred_contact']??'—')],
                ['label'=>'Land area','value'=>$this->measure($submission['land_area']??null,'m²')],
                ['label'=>'Floor','value'=>(string)($submission['floor']??'—')],
                ['label'=>'Floors','value'=>(string)($submission['floors']??'—')],
                ['label'=>'Built year','value'=>(string)($submission['built_year']??'—')],
                ['label'=>'3D tour','value'=>(bool)($submission['has_3d_tour']??false)?'Yes':'No'],
                ['label'=>'Media','value'=>(string)($submission['media_links']??'—')],
                ['label'=>'Features','value'=>(string)($submission['features_text']??'—')],
                ['label'=>'Updated','value'=>(string)($submission['updated_at']??'—')],
            ],
            description:trim((string)($submission['description']??''))?:'—',
            reviewNote:trim((string)($submission['review_note']??'')),
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

    private function measure(mixed $value,string $unit): string
    {
        if($value===null||$value==='')return '—';
        return number_format((float)$value,1,'.',' ').' '.$unit;
    }
}
