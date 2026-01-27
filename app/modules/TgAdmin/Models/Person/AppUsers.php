<?php

/**
 * Created by PhpStorm.
 * User: think
 * Date: 04.07.17
 * Time: 16:22
 */

namespace Modules\TgAdmin\Models\Person;

use Modules\TgAdmin\Models\Featuring\ForEvents;
use Modules\TgAdmin\Models\Featuring\PresentationModel;
use Longman\TelegramBot\Entities\InlineKeyboard;

use Modules\TgAdmin\Featuring\Buttons;
use Modules\Users\Models\BaseModel;


class AppUsers extends BaseModel
{
    public $status;
    public $first_name;
    public $last_name;
    public $username;
    public $tg_user_id;
    public $company_id;
    public $language_code;
    public $what_can;
    public $what_need;
    public $photo;
    public $contact_phone;
    public $where_from;

    public function initialize()
    {
        $this->setSource('person_users');
    }
    static function getCompanyId($user_id){
        return self::findFirst('id = "' . $user_id . '"')->company_id;
    }

    public function new()
    {

    }
    public function edit()
    {

    }

}