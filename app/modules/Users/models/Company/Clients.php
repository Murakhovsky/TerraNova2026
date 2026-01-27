<?php

namespace Modules\Users\Models\Company;

use Modules\Users\Models\BaseModel;


class Clients extends BaseModel {
    public $user_id;
    public $company_id;
    public $position;
    public $status;

    public function initialize()
    {
        $this->setSource('company_clients');
    }
    public function setDataFromConversation($notes){
//        $this->id = $notes["id"];
        $this->user_id = $notes["user_id"];
        $this->company_id = $notes["company_id"];
        $this->position = $notes["position"] ?? '';
        $this->status = $notes["status"] ?? '';
    }


}