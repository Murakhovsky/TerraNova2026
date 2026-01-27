<?php
/**
 * Created by PhpStorm.
 * User: think
 * Date: 08.06.17
 * Time: 20:32
 */

namespace Modules\Users\Models\Person;
use Featuring\BaseModel;

// parsed from marketplaces
class ContactsRE extends BaseModel
{
    public $name;
    public $phone;

    public $type;
    public $agency;
    public $description;
    public $countItem;
    public $link;


    public function initialize()
    {
        $this->setSource('person_contacts_re');
    }
    public function setContact($tContact)
    {

#todo add person_id by name or phone or create it
        $this->type = $tContact["type"];
        $this->agency = $tContact["agency"];
        $this->description = $tContact["description"];
        $this->countItem = isset($tContact["countItem"]) ? $tContact["countItem"] : "";
        $this->link = isset($tContact["link"]) ? $tContact["link"] : "";
        $t_date = new \DateTime();
        $this->date = $t_date->format("Y-m-d H:i:s");
    }
}