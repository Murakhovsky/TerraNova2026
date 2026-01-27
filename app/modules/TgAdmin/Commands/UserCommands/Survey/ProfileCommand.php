<?php

/**
 * This file is part of the PHP Telegram Bot example-bot package.
 * https://github.com/php-telegram-bot/example-bot/
 *
 * (c) PHP Telegram Bot Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * User "/survey" command
 *
 * Example of the Conversation functionality in form of a simple survey.
 */

namespace Modules\TgAdmin\Commands\UserCommands;

use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Modules\TgAdmin\Commands\MyUserCommand;
use Modules\Users\Models\Company\Companies;

use Modules\TgAdmin\Renderer\UserCardBuilder;
use Modules\TgAdmin\Renderer\Buttons;
use Modules\TgAdmin\Renderer\Messages;
use Modules\Users\Models\Person\AppUsers;

class ProfileCommand extends MyUserCommand
{

    protected function constructor()
    {
        $this->name = 'profile';
        $this->description = 'User command';
        $this->usage = '/profile';
        $this->version = '1.0.0';
        $this->is_button_next = false;
        $this->model = AppUsers::class;
        $this->service = di('userService');

        parent::constructor();
    }

    protected function preStart($params = array()){

    }

    protected function setNextState($pre_state)
    {
        switch ($pre_state) {
            case 'start':
            {
                $this->notes['state'] = 'first_name';
                break;
            }
            case 'first_name':
            {
                $this->notes['state'] = 'last_name';
                break;
            }
            case 'last_name':
            {
                $this->notes['state'] = 'where_from';
                break;
            }
            case 'where_from':
            {
                $this->notes['state'] = 'contact_phone';
                break;
            }
            case 'contact_phone':
            {
                $this->notes['state'] = 'photo';
                break;
            }
            case 'company_id':
            {
//                $this->notes['state'] = 'what_need';
                $this->notes['state'] = 'photo';
                break;
            }
            case 'what_need':
            {
                $this->notes['state'] = 'contact_phone';
                break;
            }
            case 'photo':
            {
                $this->notes['state'] = 'end';
                break;
            }
            case 'end':
                //               $this->notes['state'] = 'send';
                break;
            case 'send':
                break;
        }
    }

    protected function doCommand($command){
        $command = $this->input_text == $this->usage ? 'start' : $this->key_data[2];
        switch ($command){
            case "addProfilePhoto":{
                $photo = Request::getUserProfilePhotos([
                    'user_id' => $this->user_id,
                ]);

                //download new image
                $photo_id = end($photo->getResult()->photos[0])['file_id'];
                $ServerResponse = Request::getFile(['file_id' => $photo_id]);
                if ($ServerResponse->isOk()) {

                    if (Request::downloadFile($ServerResponse->getResult())) {
//                        $photo_uri = $this->getTelegram()->getDownloadPath() . '/' . $ServerResponse->getResult()->file_path;
//                        $this->notes['photo_url'] = EstateObject::sendPhotoToStorage($photo_uri);

                        $this->notes["photo"] = '/TelegramFiles/Download/' . $ServerResponse->getResult()->file_path;
                    }else{
                        throw new TelegramException('Не можу завантажити зображення.');
                    }
                }else{
                    throw new TelegramException('Не можу отримати посилання на зображення.');
                }
//                    Request::sendPhoto([
//                        'chat_id' => $this->chat_id,
//                        'photo' => $this->notes["photo"]
//                    ]);

                return true;
            }
            default:{
                return parent::doCommand($command);
            }
        }

    }

    protected function setParam()
    {
        $lastState = $this->getLastPreState();
        $inline_answer = isset($this->key_data) ? $this->key_data[2] : false;

        if ($photo = $this->message->getPhoto()) {

            //download new image
            $ServerResponse = Request::getFile(['file_id' => end($photo)->getFileId()]);
            if ($ServerResponse->isOk()) {

                if (Request::downloadFile($ServerResponse->getResult())) {
                    $this->notes['photo'] = '/TelegramFiles/Download/' . $ServerResponse->getResult()->file_path;
                }else{
                    throw new TelegramException('Не можу завантажити зображення.');
                }
            }else{
                throw new TelegramException('Не можу отримати посилання на зображення.');
            }
            Request::deleteMessage([
                'chat_id' => $this->chat_id,
                'message_id' => $this->message_id
            ]);
        }

        else{
            return parent::setParam();
        }
        return true;
    }

    protected function getPreOutMessage()
    {
        $text = Messages::getUserCardMessage($this->notes);

//        $text .= '5.📨  ' . ($user_name ? '@' . $user_name : 'Створіть в налаштуваннях власний Username') . PHP_EOL;
        $text .= PHP_EOL . PHP_EOL . '-------------------' . PHP_EOL;
        $text .= $this->page_messages[$this->notes['state']];

        return mb_convert_encoding($text, "UTF-8");
    }

    protected function end($default_data = array())
    {
        $default_data["id"] = $this->user_id;
        $default_data["username"] = $this->user_name;
        $default_data["status"] = $this->notes["status"] ?? 'registered';
        $default_data["company_id"] = $this->notes["company_id"] == 'new' ? null : $this->notes["company_id"];

        $user_id = parent::end($default_data);
        if($this->notes["company_id"] == 'new'){
            return 'company';
        }
        return false;
    }

    private function validate_text($text, $stage = '')
    {
        if (!$text) {
            return false;
        }
        if ($stage = 'phone' && !preg_match('/^0\d{9}$/', $text)) {
            return false;
        }
        return true;
    }

    protected function getReplyMarkup()
    {
        $page = $this->notes['state'];
        $inline_keyboard = array();

        switch ($page) {
            case 'send':
            {
                return Keyboard::remove();
                break;
            }
            case 'last_name':
            {
                $name_word_arr = explode(' ', $this->notes['first_name']);
                if (count($name_word_arr) > 1) {
                    $keyboard_buttons = array(
                        Buttons::getButton($name_word_arr[0], $this->name . ';last_name;' . $name_word_arr[0]),
                        Buttons::getButton($name_word_arr[1], $this->name . ';last_name;' . $name_word_arr[1])
                    );

                    $inline_keyboard[] = $keyboard_buttons;
                }
                break;
            }
            case 'where_from':
            {
                $inline_keyboard = array([
                    Buttons::getButton($this->t("lviv"), $this->name . ';where_from;lviv',),
                    Buttons::getButton($this->t("kyiv"), $this->name . ';where_from;kyiv',)
                ], [
                    Buttons::getButton($this->t("dnipro"), $this->name . ';where_from;dnipro',),
                    Buttons::getButton($this->t("odessa"), $this->name . ';where_from;odessa',),
                ], [
                    Buttons::getButton($this->t("vinnitsa"), $this->name . ';where_from;vinnitsa',),
                    Buttons::getButton($this->t("kharkiv"), $this->name . ';where_from;kharkiv',),
                ], [
                    Buttons::getButton($this->t("frankivsk"), $this->name . ';where_from;frankivsk',),
                    Buttons::getButton($this->t("uzgorod"), $this->name . ';where_from;uzgorod',),
                ], [
                    Buttons::getButton($this->t("ternopil"), $this->name . ';where_from;ternopil',),
                    Buttons::getButton($this->t("chernivtsi"), $this->name . ';where_from;chernivtsi',),
                ]);
                break;
            }
            case 'contact_phone':
            {
                $open_message = Messages::sendMessage(
                    $this->chat_id,
                    'Натисніть на кнопку <b>Надіслати мій номер</b>, або відправте повідомленням починаючи з "+".',
                    Buttons::getKeyboard(Buttons::getPageButtons($this->name, $page))
                );

                $this->notes['o_message_id'] = $open_message->isOk() ? $open_message->getResult()->message_id : '';
                break;
            }
            case 'company_id':{

                $companies = Companies::find();
                if(!$companies) break;

//                $keyboard_buttons = array();
                $inline_keyboard[] = array(Buttons::getButton("🆕 В мене своє агентство", $this->name . ';company_id;new'));
                foreach ($companies as $company){

                    $inline_keyboard[] = array(Buttons::getButton($company->name, $this->name . ';company_id;' . $company->id));
                }

                break;
            }
            default:
            {
                $inline_keyboard = Buttons::getPageButtons($this->name, $page);
            }
        }

        $inline_keyboard = array_merge($inline_keyboard, Buttons::getSurveyMenu($this->name, $page, $this->notes[$page]));

//        return Buttons::getInlineKeyboard(array_merge(
//            in_array($page, $no_key) ? array() : Buttons::getPageButtons($this->name, $page),
//            Buttons::getSurveyMenu($this->name, $page, $this->notes[$page])
//        ));
        return Buttons::getInlineKeyboard($inline_keyboard);

    }

    protected function getMessageData($notes)
    {
        $card = new UserCardBuilder($notes);
        $text = $card->getPreOutMessage();
        $reply_markup = $this->getReplyMarkup();

        if(!$text){ throw new TelegramException('Can\'t send message. Text is empty.');}
        if(!$reply_markup){ throw new TelegramException('Can\'t send message. The buttons group is empty.');}
//        if(!($reply_markup instanceof InlineKeyboard)){ throw new TelegramException('Can\'t send message. Wrong buttons group.');}

        return array(
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $reply_markup,
//            'disable_web_page_preview' => true,
        );
    }
}