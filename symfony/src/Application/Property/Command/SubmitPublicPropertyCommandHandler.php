<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use Domains\Property\Application\Contract\PropertyPublicIntakeRepositoryInterface;
use Domains\Property\Application\Contract\PropertySubmissionMediaInterface;
use Kernel\Application\Command\CommandHandlerInterface;
use Throwable;

final readonly class SubmitPublicPropertyCommandHandler implements CommandHandlerInterface
{
    private const SUCCESS='Об’єкт прийнято на модерацію. Менеджер Terra Nova зв’яжеться з вами для уточнення деталей.';

    public function __construct(
        private PropertyPublicIntakeRepositoryInterface $intake,
        private PropertySubmissionMediaInterface $media,
    ) {}

    /** @return array{ok:bool,message:string,id?:int} */
    public function __invoke(SubmitPublicPropertyCommand $command): array
    {
        $input=$command->input;
        if(trim((string)($input['website']??''))!=='') return ['ok'=>true,'message'=>self::SUCCESS];

        $ownerName=trim((string)($input['owner_name']??$input['full_name']??''));
        $ownerPhone=trim((string)($input['owner_phone']??$input['phone']??''));
        $ownerEmail=trim((string)($input['owner_email']??$input['email']??''));
        $propertyType=$this->enum((string)($input['property_type']??''),['apartment','house','cottage','land','commercial','new_building','other'],'other');
        $city=trim((string)($input['city']??''));
        $description=trim((string)($input['description']??''));

        if($ownerName===''||($ownerPhone===''&&$ownerEmail==='')||trim((string)($input['property_type']??''))===''||$city===''||$description===''){
            return ['ok'=>false,'message'=>'Заповніть контактні дані, місто, тип об’єкта та короткий опис.'];
        }
        if($ownerEmail!==''&&!filter_var($ownerEmail,FILTER_VALIDATE_EMAIL)){
            return ['ok'=>false,'message'=>'Вкажіть коректний email або залиште поле порожнім.'];
        }

        try{
            $id=$this->intake->create($command->organizationId->value(),[
                'submission_ref'=>'TNS-'.date('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))),
                'source_type'=>$this->enum((string)($input['source_type']??'owner'),['owner','realtor','developer','partner','other'],'owner'),
                'deal_type'=>$this->enum((string)($input['deal_type']??'sale'),['sale','rent','investment'],'sale'),
                'property_type'=>$propertyType,
                'title'=>$this->text($input['title']??null,220)?:mb_substr($propertyType.' / '.$city,0,220),
                'city'=>mb_substr($city,0,120),
                'region'=>$this->text($input['region']??null,120),
                'district'=>$this->text($input['district']??null,120),
                'address'=>$this->text($input['address']??null,255),
                'price_amount'=>$this->number($input['price_amount']??null),
                'price_currency'=>$this->enum(strtoupper((string)($input['price_currency']??'USD')),['USD','EUR','UAH'],'USD',false),
                'area_total'=>$this->number($input['area_total']??null),
                'land_area'=>$this->number($input['land_area']??null),
                'rooms'=>$this->number($input['rooms']??null),
                'floor'=>$this->integer($input['floor']??null),
                'floors'=>$this->integer($input['floors']??null),
                'built_year'=>$this->year($input['built_year']??null),
                'has_3d_tour'=>isset($input['has_3d_tour'])?1:0,
                'media_links'=>$this->text($input['media_links']??null,2000),
                'description'=>mb_substr($description,0,5000),
                'features_text'=>$this->text($input['features_text']??null,3000),
                'owner_name'=>mb_substr($ownerName,0,160),
                'owner_phone'=>$this->text($ownerPhone,50),
                'owner_email'=>$this->text($ownerEmail,160),
                'preferred_contact'=>$this->enum((string)($input['preferred_contact']??'any'),['phone','telegram','email','any'],'any'),
                'source_page'=>mb_substr($command->sourcePage,0,255),
            ]);

            try{
                $stored=$this->media->storeUploadedFiles($command->files,'property_submission',$id);
                $message=self::SUCCESS.($stored!==[]?' Медіа додано до заявки.':'');
            }catch(Throwable $mediaError){
                error_log('property.public.intake_media_failed '.$mediaError->getMessage());
                $message=self::SUCCESS.' Частину медіа не вдалося додати.';
            }

            return ['ok'=>true,'message'=>$message,'id'=>$id];
        }catch(Throwable $error){
            error_log('property.public.intake_failed '.$error->getMessage());
            return ['ok'=>false,'message'=>'Об’єкт не вдалося зберегти. Спробуйте ще раз.'];
        }
    }

    private function text(mixed $value,int $limit):?string
    {
        $value=trim((string)$value);
        return $value===''?null:mb_substr($value,0,$limit);
    }

    private function number(mixed $value):?float
    {
        return $value!==null&&trim((string)$value)!==''&&is_numeric($value)?max(0,(float)$value):null;
    }

    private function integer(mixed $value):?int
    {
        return is_numeric($value)&&(int)$value>0?(int)$value:null;
    }

    private function year(mixed $value):?int
    {
        if(!is_numeric($value)) return null;
        $year=(int)$value;
        return $year>=1800&&$year<=((int)date('Y')+2)?$year:null;
    }

    /** @param list<string> $allowed */
    private function enum(string $value,array $allowed,string $fallback,bool $lower=true):string
    {
        $value=trim($value);
        if($lower)$value=mb_strtolower($value);
        return in_array($value,$allowed,true)?$value:$fallback;
    }
}
