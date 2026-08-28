<?php

/**
 * Created by PhpStorm.
 * User: think
 * Date: 04.07.17
 * Time: 16:22
 */

namespace Infrastructure\Persistence\Phalcon\Identity\Telegram\Person;

use Infrastructure\Persistence\MySql\Database\Exception\DatabaseOperationFailed;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\BaseModel;
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
                throw new DatabaseOperationFailed(
                    'Не вдалося створити Telegram-користувача: '
                    . implode('; ', array_map(static fn($message): string => (string) $message, $user->getMessages())),
                );
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


