<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Telegram\Commands\SystemCommands;

use Modules\TgAdmin\Models\Company\Companies;
use Modules\TgAdmin\Models\Company\Employees;
use Modules\TgAdmin\Models\Estate\Objects;
use Modules\TgAdmin\Models\Featuring\ReObjects;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\InlineQuery\InlineQueryResultArticle;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Modules\TgAdmin\Renderer\ObjectCardBuilder;
use Person\ColdPhones;
use Modules\TgAdmin\Models\Person\Contacts;
use Phalcon\Mvc\Model\Query\Builder;
use Modules\TgAdmin\Models\Realty\Realty;
use Modules\TgAdmin\Models\Realty\RealtyReamak;
use Modules\TgAdmin\Models\Request\RequestJoinRealty;
use Modules\TgAdmin\Models\Request\Requests;
use Request\TelegramEbRequest;
use Modules\TgAdmin\Models\Request\Shows;
use Modules\TgAdmin\Models\Service\Lists;
use Modules\TgAdmin\Models\Service\ListIstems;
use Modules\TgAdmin\Featuring\Cards;
use Modules\TgAdmin\Featuring\Messages;

/**
 * Inline query command
 */
class InlinequeryCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'inlinequery';

    /**
     * @var string
     */
    protected $description = 'Reply to inline query';

    /**
     * @var string
     */
    protected $version = '1.2';

    /**
     * Command execute method
     *
     * @return mixed
     */
    protected $inline_query;
    protected $user_id;

    public function execute(): ServerResponse
    {
        $this->inline_query = $this->getInlineQuery();
        $query = $this->inline_query->getQuery();
        $this->user_id = $this->inline_query->getFrom()->getId();
        $inline_query_id = $this->inline_query->getId();
//      sid_61e6fcce4ec0c

        $results = self::getInlineError('Нічого не знайдено');
        if ($query == ''){
            $results = $this->getInlineCommandList();
        }
        $query_arr = explode('_', $query);
        $prefix = $query_arr[0];
        $param = $query_arr[1];

        switch ($prefix){
            case 'mol':{
                $objects_data = Objects::searchRealty(array(
                    'user_id' => $this->user_id
                ), 1, 10);
                Messages::sendMeMessage(count($objects_data));
                $results = ObjectCardBuilder::getInlineArticleArr($objects_data, ['user_id' => $this->user_id, 'is_full_description' => true]);
                break;
            }// my object list
            case 'mrl':{
                $company_id = Employees::getCompanyByUserId($this->user_id);

                if($company_id) {
                    $request_data = Requests::find([
                        'accountable_id = :a_id: AND company_id = :c_id: AND status = :s:',
                        'bind' => [
                            'a_id' => $this->user_id,
                            'c_id' => $company_id,
                            's' => 'in_work'
                        ],
                        'order' => 'id DESC',
                        'limit' => 50
                    ]);

                    if(count($request_data)) {
                        $results = $this->getInlineArticleArr($request_data);
                    }
                }
                break;
            } // my requests list

            case 'vib':{
//                Bot::
//
                break;
            }
            case 'soid': // search object by ID
            case 'said': // search advert by ID
            {
                $request_id = $query_arr[2];
                $request = TelegramEbRequest::getRequestByID($param);
                if ($request) {
                    $params = ['request_id' => $request_id];
                    if ($request->accoutable_id == $this->user_id || $request->author_id == $this->user_id) {
                        $filters = $request->toArray();

                        $filters['user_id'] = null;
                        if ($prefix == 'soid') {
                            $filters['source'] = 'objects';
                            $functionGetInlineData = 'getInlineDataObject';
                        } else {
                            $filters['source'] = 'advert';
                            $params['category_type'] = ucfirst($filters['category']) . ucfirst($filters['type']);
                            $params['category'] = $filters['category'];
                            $params['type'] = $filters['type'];
                            $functionGetInlineData = 'getInlineDataAdvert';
                        }

                        $realty_arr = Realty::searchRealty($filters, 1, 50);
                        if (count($realty_arr)) {
                            $results = $this->getInlineArticleArr($realty_arr, $functionGetInlineData, $params);
                        } else {
    //                            $results = self::getInlineError('*опа!');
                        }
                    } else {
                        $results = self::getInlineError('У вас немає доступу до цього пошуку');
                    }
                } else {
                    $results = self::getInlineError('Даного пошуку не існує');
                }
                break;
            }
            case 'mid':{
                break;
            }
            case 'fid': // get favourite by ID
                {

                if(!$param || !preg_match( '/^\d{1,5}$/', $param)){
                    $results = self::getInlineError('Параметр не вірний');
                    break;
                }

                $favourite = Lists::findFirstById($param);
                if (!$favourite){
                    $results = self::getInlineError('Список відсутній');
                    break;
                }

                if($favourite->user_id != $this->user_id && $favourite->is_private == 1){
                    $results = self::getInlineError('Список приватний');
                    break;
                }

                $realtyItems = Objects::getObjectsByListId($param);

                if (!$realtyItems) {
                    $results = self::getInlineError('Список пустий');
                    break;
                }

                $results = $this->getInlineArticleArr($realtyItems);
                break;
            }
            case 'aid': // get advert by ID
                {
                if(!$param){return Request::emptyResponse();}
                $params = explode(':', $param);
                $advert_model = 'Parser\Advert\Realty' . $params[0];
//                $advert_id = $params[1];
                if(!class_exists($advert_model)) {
                    $results = self::getInlineError('Не правильний тип оголошення');
                    break;
                }
                $advert = $advert_model::getAdvertByID($params[1])->toArray();
                if ($advert) {
                    $advert["category_type"] = $params[0];
                    $category_type = strtolower($advert["category_type"]);
                    $advert["category"] = substr($category_type, 0,4);
                    $advert["type"] = str_replace($advert["category"], '', $category_type);

                    $advert["currency"] = $advert["category"] == 'rent' ? 'UAH' : 'USD';
                    $inlineQueryObjects = $this->getInlineDataAdvert($advert);
                    $inlineQueryObjects["id"] = 1;
                    $inlineQueryObjects["parse_mode"] = 'HTML';

                    $results = array(new InlineQueryResultArticle($inlineQueryObjects));
                }
                break;
            }
            case 'reid':{
                if(!$param){return Request::emptyResponse();}
                $realty = ReObjects::getEstateModel("object")::findFirstById($param);

                if($realty) {
                    $inlineQueryObjects = $this->getInlineDataObject($realty);
                    $inlineQueryObjects["id"] = 1;
                    $inlineQueryObjects["parse_mode"] = 'HTML';

                    $results = array(new InlineQueryResultArticle($inlineQueryObjects));
                }
                break;
            }
            case 'prol':{ // deprecated
                    $objects_data = Realty::searchRealty(array(
                        'source' => 'prykhystok',
                        'user_id' => $this->user_id
                    ), 1, 46);

                    $results = $this->getInlineArticleArr($objects_data, 'getInlineDataPrykhustok');
                break;
            }

            case 'mfl':{ // my favourite list
                $request_data = Lists::find([
                    "user_id = :u_id: AND status <> :not_a:",
                    'bind' => [
                        'u_id' => $this->user_id,
                        'not_a' => 'not_active'
                    ],
                    'order' => 'id DESC',
                ]);
                $results = $this->getInlineArticleArr($request_data);
                break;
            }
            case 'mshl':{ // my showing list
                $showing_data = Shows::find(array(
                    "author_id = '" . $this->user_id . "' OR accountable_id = '". $this->user_id ."'",
                    'order' => 'showing_at ASC',
                ));
                $results = $this->getInlineArticleArr($showing_data);
                break;
            }
            case 'rol':{ // request objects list
                $join_list = RequestJoinRealty::find("request_id='" . $param . "'");
                if(!$join_list){ return self::getInlineError('Зв\'язків не знайдено');}
                $objects_data = array();
                foreach($join_list as $join) {

                    $objects_data[] = ReObjects::getEstateModel("object")::findFirstById($join->object_id );
                }
                if(!$objects_data){ return self::getInlineError('Об\'єкти могли бути видалені');}
                $results = $this->getInlineArticleArr($objects_data, 'getInlineDataObject');
                break;
            }
            case 'maol':{ // my archive object list
                    $archive_data = Realty::searchRealty(array(
                        'source' => 'archive',
                        'user_id' => $this->user_id
                    ), 1, 96);
                    $results = $this->getInlineArticleArr($archive_data, 'getInlineDataObject');
                break;
            }
            case 'aol':{ // all object list
                $date_from = $param ?? '2023-03-01';
                $price = $query_arr[2] ?? '10-1000000';
                $price_arr = explode('-', $price);

                $objects_data = ReObjects::getEstateModel("object")::find([
                    'user_id = :u_id: AND published_at > :ua: AND price_USD > :p_f: AND price_USD < :p_t:',
                    'bind' => [
                        'p_f' => $price_arr[0],
                        'p_t' => $price_arr[1],
                        'u_id' => 1930354236,
                        'ua' => $date_from,
                    ],
                    'order' => 'status desc, published_at',
                    'limit' => 50
                ]);
//                Messages::sendMeMessage(count($objects_data));
                $params["data_type"] = 'object';
                $results = $this->getInlineArticleArr($objects_data, 'getInlineDataObject', $params);
                break;
            }
            case 'raol':{ // reamak all object list

//                $date_from = $param ?? '2022-12-03';
                $price_USD = $param ?? '100000';

                $reamak_new_data = RealtyReamak::find([
//                    '(status = :status1: AND user_id = :u_id:) OR ( status = :status2: AND updated_at > :ua:)',
//                    'status = :status: AND updated_at > :ua:',
                    'status = :status: AND price_USD > :pu:',
                    'bind' => [
                        'status' => 'new',
//                        'u_id' => $this->user_id,
//                        'ua' => $date_from,
                        'pu' => $price_USD,
                    ],
                    'order' => 'status desc, updated_at desc',
                    'limit' => 50
                ]);

                $params["data_type"] = 'reamak';
                    $results = $this->getInlineArticleArr($reamak_new_data, 'getInlineDataObject', $params);
                break;
            }
            case 'rnol':{ // reamak not called object list

                $date_from = $param ?? '2022-12-03';

                $reamak_new_data = RealtyReamak::find([
//                    'status = :status: AND user_id = :u_id:',
                    'status = :status: AND user_id = :u_id:',

                    'bind' => [
                        'status' => 'not_called',
                        'u_id' => $this->user_id,
                    ],
                    'order' => 'status desc, updated_at',
                    'limit' => 50
                ]);

                $params["data_type"] = 'reamak';
                    $results = $this->getInlineArticleArr($reamak_new_data, 'getInlineDataObject', $params);
                break;
            }
            case 'rrol':{ // reamak reminder object list

                $date_from = $param ?? '2022-12-03';

                $reamak_new_data = RealtyReamak::find([
//                    'status = :status: AND user_id = :u_id:',
                    'status = :status: AND user_id = :u_id:',

                    'bind' => [
                        'status' => 'reminder',
                        'u_id' => $this->user_id,
                    ],
                    'order' => 'status desc, updated_at',
                    'limit' => 50
                ]);

                $params["data_type"] = 'reamak';
                    $results = $this->getInlineArticleArr($reamak_new_data, 'getInlineDataObject', $params);
                break;
            }
            case 'rool':{ // reamak owner object list
                $phone = $param ?? '1';
                $original_id = $query_arr[2] ?? false;

                $archive_data = RealtyReamak::find([
                    'contact_phone = :phone:',
                    'bind' => [
                        'phone' => $phone,
                    ],
                    'order' => 'updated_at',
                    'limit' => 50
                ]);
                $params["data_type"] = 'reamak';
                $params["original_id"] = $original_id;
                $results = $this->getInlineArticleArr($archive_data, 'getInlineDataObject', $params);
                break;
            }
            case 'mll':{ // my leads list
                $employee = Employees::findFirst('user_id = "' . $this->user_id . '"');
                if(!$employee){break;}
                $leads_data = ColdPhones::find([
                    'accountable_id = :a_id: AND ' .
                    'company_id = :c_id: AND ' .
                    'request_id = :r_id: AND ' .
                    '(status = :s1: OR status = :s2: OR status = :s3:)',
                    'bind' => [
                        'a_id' => $this->user_id ,
                        'c_id' => $employee->company_id ,
                        'r_id' => 0,
                        's1' => 'waiting',
                        's2' => 'not_called',
                        's3' => 'called',
                    ],
                    'order' => 'status DESC, id DESC',
                    'limit'      => 50,
                ]);
//                $leads_data = ColdPhones::find([
//                    'accountable_id = "' . $this->user_id . '" AND company_id = "' . $employee->company_id  . '"'
//                ]);

                $results = $this->getInlineArticleArr($leads_data, 'getInlineDataLead');

                break;
            }
            case 'mrlo':{ // my requests list by object ID
                $company_id = Employees::getCompanyByUserId($this->user_id);
                if($company_id) {
                    $joins = RequestJoinRealty::find('object_id='.$param);
                    $request_data = array();
                    foreach ($joins as $join) {
                        $request_data[] = TelegramEbRequest::findFirst([
                            'accountable_id = :a_id: AND company_id = :c_id: AND status = :s:',
                            'bind' => [
                                'a_id' => $this->user_id,
                                'c_id' => $company_id,
                                's' => 'in_work'
                            ],
                            'order' => 'id DESC',
                            'limit' => 50
                        ]);
                    }
                    if(count($request_data)) {
                        $results = $this->getInlineArticleArr($request_data, 'getInlineDataRequest');
                    }
                }
                break;
            }
            case 'cl':{ // company lists
                $request_data = Companies::find(array(
                        'order' => 'name DESC',
                ));
                $results = $this->getInlineArticleArr($request_data, 'getInlineDataCompany');
                break;
            }
            case 'cel': //company employees list
            {
                $company_id = explode(',', $param)[0];
                $company_employees_data = Employees::getEmployeesJoinUsers($company_id);

//                $user_data = TelegramEbUser::findFirst('id = ' . $this->user_id . '');
                $company_employees_arr = array();
                $company_e_query_arr = array();
                foreach ($company_employees_data as $key => $item) {
                    if(!$item->e_company_id || ($item->e_company_id && $item->e_company_id != $item->company_id)){
                        array_unshift($company_e_query_arr, $item);
                    }else{
                        array_push($company_employees_arr, $item);
                    }
                }
                $is_company_owner = Companies::findFirst("id = '" . $company_id . "' AND admin_id = '" . $this->user_id . "'");
                if($is_company_owner) {
                    $company_employees_arr = array_merge($company_e_query_arr, $company_employees_arr);
                }
                $results = $this->getInlineArticleArr($company_employees_arr, 'getInlineDataEmployee');

                break;
            }
            case 'ctl': // contact telephone list
            {

//                Messages::sendMeMessage(strlen($param));
                if (strlen($param) < 6) {
                    $results = array(new InlineQueryResultArticle(array(
                        "id" => 1,
                        "parse_mode" => 'HTML',
                        "title" => "Пошук контактів за номером",
                        "description" => "Для пошуку введіть 6 перших цифр номеру телефону після ctl_",
                        'message_text' => "Для пошуку введіть 6 перших цифр номеру телефону після @EstateBookBot ctl_",
                    )));
                } else {
                    $request_data = ContactsRE::find(array(
                        'conditions' => 'phone LIKE :phone:',
                        'bind' => [
                            'phone' => $param . '%',
                        ],
                    ));
                    $results = $this->getInlineArticleArr($request_data, 'getInlineDataContact');
                }
                break;
            }
            case 'arl':{ // all requests list
                $request_data = TelegramEbRequest::find(array(
                    'conditions' => "is_private = 'no' OR accountable_id = '" . $this->user_id . "'",
                    'order' => 'id DESC',
                    'limit' => 50
                ));
                $results = $this->getInlineArticleArr($request_data, 'getInlineDataRequest');
                break;
            }
            case 'ul':{ // user list

                $data = array(
                    'order' => 'id DESC',
                );
                if($param){
                    $data['conditions'] = ' ';
                }
                $user_data = AppUsers::find($data);

                $results = $this->getInlineArticleArr($user_data, 'getInlineDataUsers');
                break;
            }
            case 'sсadverts':{ // search objects by params
                $type = array(
                    'kv' => 'flat',
                    'ho' => 'house',
                    'com' => 'commerce',
                    'gro' => 'ground',
                );
                $params = explode(';', $param);
                $search_data = array('source' => 'advert');
                if($params[1]){
                    $search_data['category'] = $params[1];
                }
                if($params[2]){
                    $search_data['type'] = $type[$params[2]];
                }
                if($params[3]){
                    $search_data['advert_code'] = (int)$params[3];
                }


                if(!$search_data['category'] || !$search_data['type']){
                    Messages::sendInfoMessage($this->user_id,
                        '❗️<b>Категорія</b> та <b>Тип об\'єкту</b> повинні бути визначені!');
                    return Request::emptyResponse();
                }
                if(!$search_data['advert_code'] || strlen($params[3]) < 7){
                    $results = self::getInlineError('Код повинен мати від 7 цифр');
                    break;
                }

                $advert_data = Realty::searchRealty($search_data, 1, 46);
//                Request::sendMessage([
//                    'chat_id' => 522625209,
//                    'text' => json_encode($objects_data)
//                ]);
                $params = ['category' => $search_data['category'], 'type' => $search_data['type']];
                $results = $this->getInlineArticleArr($advert_data, 'getInlineDataAdvert', $params);
                break;
            }
            case 'sobjects':{ // search objects by params
                $regions = array(
                    'gal' => 'galician',
                    'shev' => 'shevchenkivsky',
                    'fran' => 'frankivsky',
                    'sykh' => 'sykhivsky',
                    'lych' => 'lychakivsky',
                    'zal' => 'zaliznychny',
                );
                $floors = array(
                    '2' => 'Від 2го',
                    '0' => 'Не останній',
                    '1-4' => 'До 4го',
                    '1-9' => 'До 9го',
                    '5' => 'Від 5го',
                    '10' => 'Від 10го',
                );
                $type = array(
                    'kv' => 'flat',
                    'ho' => 'house',
                    'com' => 'commerce',
                    'gro' => 'ground',
                );
                $params = explode(';', $param);
                $search_data = array('source' => 'objectsTG' );
                if($params[1]){
                    $search_data['category'] = $params[1];
                }
                if($params[2]){
                    $search_data['type'] = $type[$params[2]];
                }
                if($params[3]){
                    $search_data['rooms'] = $params[3];
                }
                if($params[4]){
                    $search_data['region'] = $regions[$params[4]];
                }
                if($params[5]){
                    $search_data['square'] = $params[5];
                }
                if($params[6]){
                    $search_data['floor'] = $params[6];
                }
                if($params[7]){
                    $search_data['price'] = $params[7];
                }

                $objects_data = Realty::searchRealty($search_data, 1, 39);
                $results = $this->getInlineArticleArr($objects_data, 'getInlineDataObject');
                break;
            }
            case 'sadverts':{ // search adverts by params
                $regions = array(
                    'gal' => 'galician',
                    'shev' => 'shevchenkivsky',
                    'fran' => 'frankivsky',
                    'sykh' => 'sykhivsky',
                    'lych' => 'lychakivsky',
                    'zal' => 'zaliznychny',
                );
                $floors = array(
                    '2' => 'Від 2го',
                    '0' => 'Не останній',
                    '1-4' => 'До 4го',
                    '1-9' => 'До 9го',
                    '5' => 'Від 5го',
                    '10' => 'Від 10го',
                );
                $type = array(
                    'kv' => 'flat',
                    'ho' => 'house',
                    'com' => 'commerce',
                    'gro' => 'ground',
                );
                $params = explode(';', $param);
                $search_data = array('source' => 'advert');
                if($params[1]){
                    $search_data['category'] = $params[1];
                }
                if($params[2]){
                    $search_data['type'] = $type[$params[2]];
                }
                if($params[3]){
                    $search_data['rooms'] = $params[3];
                }
                if($params[4]){
                    $search_data['region'] = $regions[$params[4]];
                }
                if($params[5]){
                    $search_data['square'] = $params[5];
                }
                if($params[6]){
                    $search_data['floor'] = $params[6];
                }
                if($params[7]){
                    $search_data['price'] = $params[7];
                }

                if(!$search_data['category'] || !$search_data['type']){
                    $error_message = Request::sendMessage(
                        array(
                            'chat_id' => $this->user_id,
                            'text' => '❗️<b>Категорія</b> та <b>Тип об\'єкту</b> повинні бути визначені!',
                            'parse_mode' => 'HTML',
                        )
                    );
                    sleep(4);
                    Request::deleteMessage(
                        array(
                            'chat_id' => $this->user_id,
                            'message_id' => $error_message->getResult()->message_id,
                        )
                    );
                    return Request::emptyResponse();
                }
                $params = ['category' => $search_data['category'], 'type' => $search_data['type']];
                $objects_data = Realty::searchRealty($search_data, 1, 46);
//                Request::sendMessage([
//                    'chat_id' => 522625209,
//                    'text' => json_encode($objects_data)
//                ]);
                $results = $this->getInlineArticleArr($objects_data, 'getInlineDataAdvert', $params);
                break;
            }
            // search prykhystok
            case 'searchp': // deprecated
                {
                $city = array(
                    'gal' => 'galician',
                    'shev' => 'shevchenkivsky',
                    'fran' => 'frankivsky',
                    'sykh' => 'sykhivsky',
                    'lych' => 'lychakivsky',
                    'zal' => 'zaliznychny',
                );
//                $region = array(
//                    'gal' => 'galician',
//                    'shev' => 'shevchenkivsky',
//                    'fran' => 'frankivsky',
//                    'sykh' => 'sykhivsky',
//                    'lych' => 'lychakivsky',
//                    'zal' => 'zaliznychny',
//                );
//                $floors = array(
//                    '2' => 'Від 2го',
//                    '0' => 'Не останній',
//                    '1-4' => 'До 4го',
//                    '1-9' => 'До 9го',
//                    '5' => 'Від 5го',
//                    '10' => 'Від 10го',
//                );
//                $type = array(
//                    'kv' => 'flat',
//                    'ho' => 'house',
//                    'com' => 'commerce',
//                    'gro' => 'ground',
//                );

                $params = explode(';', $param);
                $search_data = array('source' => 'prykhystok');
                if($params[1]){
                    $search_data['city'] = $params[1];
                }
                if($params[2]){
                    $search_data['beds'] = $params[2];
                }
                if($params[3]){
                    $search_data['term'] = $params[3];
                }
                if($params[4]){
                    $search_data['type'] = $params[4];
                }
                if($params[5]){
                    $search_data['region'] = $params[5];
                }
                if($params[6]){
                    $search_data['price'] = $params[6];
                }

                $objects_data = Realty::searchRealty($search_data, 1, 96);
                $results = $this->getInlineArticleArr($objects_data, 'getInlineDataPrykhustok');
                break;
            }
            default:{break;}
        }

        $answer = array(
            'inline_query_id' => $inline_query_id,
            'results' => '[' . implode(',', $results) . ']',
            'cache_time' => 1,
//            'next_offset' => 2,
//            'switch_pm_text' => 'params',
//            'switch_pm_parameter' => '/params',
        );
        return Request::answerInlineQuery($answer);
    }




    private function getInlineDataContact($user_data, $params = array()){

        $result = array();

        $result["title"] = $user_data->name ?? 'No name';
        $result["description"] = $user_data->agency ?? ($user_data->type ?? 'Опис відсутній');

//        $result["thumb_url"] = $user_data->photo ?? 'https://estatebook.club/TelegramFiles/Download/thumbnails/user.jpg';

        $result['message_text'] =  $user_data->name ?  '<b>' . $user_data->name .  '</b>' . PHP_EOL : '';
        $result['message_text'] .=  $user_data->phone ?  $user_data->phone . PHP_EOL  . PHP_EOL : '';
        $result['message_text'] .=  $user_data->agency ?  $user_data->agency . PHP_EOL: '';
        $result['message_text'] .=  $user_data->type ?  $user_data->type . PHP_EOL . PHP_EOL : "";
        $result['message_text'] .= $user_data->description ? $user_data->description : 'Опис відсутній';
//        $result['reply_markup'] = array();

        return $result;
    }


    private function getInlineCommandList(){
        return array(
            new InlineQueryResultArticle([
                'id' => '001',
                'title' => 'Можливості Estate Book Bot',
                'message_text' => '@EstateBookBot',
                'description' => '⬇⬇⬇⬇⬇⬇⬇⬇⬇⬇⬇⬇',
//                'reply_markup' => new InlineKeyboard(
//                    array(
//                        Buttons::getButton('Додати чат до заявки', 'menu;requestFCCh;show;')
//                    )
//                )
//                'thumb_url' => 'https://www.estatebook.club/img/logos/logo.png',
//                'thumb_width' => 163,
//                'thumb_height' => 182,
//                'url' => 'estatebook.club',

            ]),
            new InlineQueryResultArticle([
                'id' => '004',
                'title' => '@EstateBookBot mol',
                'message_text' => 'Відкрити список моїх об\'єктів у',
                'description' => 'Відкрити список моїх об\'єктів' . PHP_EOL . ' (my object list)',
                'reply_markup' => new InlineKeyboard(
                    array(
                        new InlineKeyboardButton(['text' => 'даному чаті ', 'switch_inline_query_current_chat' => "mol"]),
                        new InlineKeyboardButton(['text' => 'іншому чаті', 'switch_inline_query' => 'mol'])
                    )
                )
            ]),
            new InlineQueryResultArticle([
                'id' => '005',
                'title' => '@EstateBookBot mrl',
                'message_text' => 'Відкрити список моїх запитів у:',
                'description' => 'Відкрити список моїх запитів' . PHP_EOL . ' (my request list)',
                'reply_markup' => new InlineKeyboard(
                    array(
                        new InlineKeyboardButton(['text' => 'даному чаті ', 'switch_inline_query_current_chat' => "mrl"]),
                        new InlineKeyboardButton(['text' => 'іншому чаті', 'switch_inline_query' => 'mrl'])
                    )
                )
            ]),
            new InlineQueryResultArticle([
                'id' => '006',
                'title' => '@EstateBookBot mfl',
                'message_text' => 'Відкрити список моїх обраних у',
                'description' => 'Відкрити список моїх обраних' . PHP_EOL . ' (my favourite list)',
                'reply_markup' => new InlineKeyboard(
                    array(
                        new InlineKeyboardButton(['text' => 'даному чаті ', 'switch_inline_query_current_chat' => "mfl"]),
                        new InlineKeyboardButton(['text' => 'іншому чаті', 'switch_inline_query' => 'mfl'])
                    )
                )
            ]),
            new InlineQueryResultArticle([
                'id' => '002',
                'title' => '@EstateBookBot sid_*************',
                'message_text' => '/request@EstateBookBot',
                'description' => 'Пошук оголошень по збереженому запиту з допомогою Search ID',


            ]),
            new InlineQueryResultArticle([
                'id' => '003',
                'title' => '@EstateBookBot reid_***',
                'message_text' => '@EstateBookBot',
                'description' => 'Знайти оголошення з допомогою RE ID',
//                'thumb_url' => 'https://www.estatebook.club/img/logos/logo.png',
            ]),
            new InlineQueryResultArticle([
                'id' => '008',
                'title' => '@EstateBookBot uid_***',
                'message_text' => '@EstateBookBot /find',
                'description' => 'Пошук користувача з допомогою User ID',
//                'thumb_url' => 'https://www.estatebook.club/img/logos/logo.png',
            ])
        );
    }

    public static function getInlineError($text): array
    {
        return array(
            new InlineQueryResultArticle([
                'id' => '001',
                'title' => '❗️' . $text,
                'message_text' => '@estatebookbot',
//                'description' => 'Нічого не знайдено',
//                'thumb_url' => 'https://www.estatebook.club/img/logos/logo.png',
            ])
        );
    }
}