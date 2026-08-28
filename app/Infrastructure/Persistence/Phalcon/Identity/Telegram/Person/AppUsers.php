<?php

/**
 * Created by PhpStorm.
 * User: think
 * Date: 04.07.17
 * Time: 16:22
 */

namespace Infrastructure\Persistence\Phalcon\Identity\Telegram\Person;

use Interfaces\Telegram\Presentation\Messages;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\BaseModel;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Phalcon\Di\Di;
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
        $this->newSavedServiceName = "user:newProfileSaved";
    }
    static function getCompanyId($user_id){
        return self::findFirst('id = "' . $user_id . '"')->company_id;
    }



    public static function findFirstOrCreate($user_id):AppUsers
    {
        $user = self::findFirst($user_id);
        if(!$user){
            $user = new AppUsers();
            $user->id = $user_id;
            $user->status = "public";
            $user->first_name = "user_".$user_id;
            if (!$user->save()) {
                $messages = $user->getMessages();
                foreach ($messages as $message) {
                    Messages::sendMeMessage("❗ Помилка: " . $message );
                }
            }
        }
        return $user;
    }


    public function newSaved()
    {
        // TODO: Implement newSaved() method.
    }

    public function editSaved()
    {
        // TODO: Implement editSaved() method.
    }
}


