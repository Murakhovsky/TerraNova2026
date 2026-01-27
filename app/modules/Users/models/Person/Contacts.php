<?php
/**
 * Created by PhpStorm.
 * User: think
 * Date: 08.06.17
 * Time: 20:32
 */

namespace Modules\Users\Models\Person;
use Modules\Users\Models\Featuring\BaseModel;

class Contacts extends BaseModel
{
    public $person_id;

    public $category;

    public function setContact($tContact)
    {
        $this->name = $tContact["name"] ?? 'Unknown';
        $this->phone = isset($tContact["phone"]) ? str_replace(["+38", "(", ")", "-", " "],"", $tContact["phone"]) : 'Unknown';
        $this->email = $tContact["email"];
        $this->address = $tContact["address"];
        $this->category = $tContact["category"] ?? "Unknown";
        $this->description = $tContact["description"];
        $this->added_at = $tContact["added_at"] ?? date("Y-m-d");
    }
}