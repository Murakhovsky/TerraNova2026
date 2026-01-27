<?php


namespace Modules\TgAdmin\Services;

use Longman\TelegramBot\Request;
use Modules\TgAdmin\Models\Person\AppUsers;

class AccessRights
{
    static private $access = array(
        'public' => array(
            'menu_profile',
            'menu_CRM',
            'menu_search',
            'menu_language',
            'menu_catalog',
            'menu_adverts',
            'menu_another',
            'menu_myObjects', // temp
            'menu_advertsAll', // for Paliy
            'menu_games'
            ),
        'registered' => array(
            'menu_company',
            'menu_myObjects',
            'menu_myRequests',
            'menu_showing',
            'menu_tasks',
            'menu_archive',
            'menu_objectsAll',
            'menu_requestsAll',
            'menu_settings',
            'menu_wallet',
            'menu_top_up',
            'menu_mining',
            'menu_pay',
            'menu_admin_panel',
        ),
        'paid' => array(),
        'admin' => array(),
    );

    static private $err_message = array(
        'public' => TG_NO_PAGE_IN_LIST,
        'registered' => TG_NEED_TO_ADD_PROFILE,
        'paid' => TG_NEED_TO_PAY,
        'admin' => TG_NEED_ADMIN_RIGHTS
    );

    static public function check($user_id, $data){
        $openPages = array();

        $result = array('is_allow' => true, 'message' =>  TG_ACCESS_IS_ALLOWED);
        $status = self::getStatus($user_id);

        switch ($status){
            case 'admin':{
                $openPages = array_merge($openPages, self::$access['admin']);
            }
            case 'paid':{
                $openPages = array_merge($openPages, self::$access['paid']);
            }
            case 'registered':{
                $openPages = array_merge($openPages, self::$access['registered']);
            }
            case 'public':{
                $openPages = array_merge($openPages, self::$access['public']);
                break;
            }
            default:{
                break;
            }
        }
        if(!in_array($data, $openPages)){
            $message = TG_NO_PAGE_IN_LIST;
            foreach (self::$access as $key => $pages){
                if(in_array($data, $pages)){
                    $message = self::$err_message[$key];
                    break;
                }
            }
            $result = array('is_allow' => false, 'message' => $message);
        }
        return $result;
    }

    static public function getStatus($user_id){
        $user = AppUsers::findFirst($user_id);
        return $user ? $user->status : 'public';
    }
}