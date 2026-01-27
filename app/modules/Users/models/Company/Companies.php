<?php


namespace Modules\Users\Models\Company;

use Modules\Users\Models\BaseModel;

class Companies extends BaseModel
{
    public $name;
    public $status;
    public $employees;
    public $admin_id;
    public $description;
    public $url;


    public function initialize()
    {
        $this->setSource('company_companies');
    }
    public function setDataFromConversation($notes){

        $this->id = $notes["id"];
        $this->name = $notes["name"];
        $this->status = $notes["status"];
        $this->admin_id = $notes["admin_id"];
        $this->employees = $notes["employees"];
        $this->description = $notes["description"];
        $this->url = $notes["url"];
        $this->created_at =  $notes["created_at"];
    }
    public static function getDataArray($id){
        $companyData = self::findFirst("id = '" . $id . "'");
        if(!$companyData){ return array(); }

        return $companyData->toArray();
    }

    public static function getTittle($data): string
    {
        return $data->name;
    }

    public static function getTGText(array $data, array $params = array()): ?string{
        // TODO: Implement getTGText() method.
    }

    public function getTGCard(int $user_id, array $params)
    {
        // TODO: Implement getTGCard() method.
    }
}