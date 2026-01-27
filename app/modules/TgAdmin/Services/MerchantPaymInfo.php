<?php


namespace Modules\TgAdmin\Services;


use Modules\TgAdmin\Featuring\Messages;
use Phalcon\Mvc\Model\Manager;
use TelegramModels\Finance\TelegramFinBasket;
use TelegramModels\Finance\TelegramFinMaxProdInUserStatus;
use TelegramModels\Finance\TelegramFinMoney;
use TelegramModels\Finance\TelegramFinProduct;
use TelegramModels\Finance\TelegramFinTransaction;
use TelegramModels\TelegramEbUser;

class MerchantPaymInfo
{
    public $reference; // Номер чека, замовлення, тощо; визначається мерчантом
    public $destination; // Призначення платежу
    public $basketOrder; // Склад замовлення, використовується для відображення кошика замовлення

    public function __construct($destination, $reference)
    {
        $this->reference = (string)$reference;
        $this->destination = $destination;
        $this->basketOrder = array();
    }

    public static function create_transaction($prod_code, $user_id, $counterparty_id = 0){
        define("USER_ID", $user_id);
        define("COUNTERPARTY_ID", $counterparty_id);
        define('COMPANY_ID', 594711080);
        define('BENEFICIARY_ID', 522625209);

        $product = TelegramFinProduct::findFirst("code = '" . $prod_code . "'");
        if(!$product){return array("message" => "Внутрішня помилка: продукт не знайдено.","is_ok" => false);}

        $sender_id = constant($product->sender);
        $receiver_id = constant($product->receiver);

        // user access to product
        $user = TelegramEbUser::getThisByID($user_id);
        $product_for_user_group = TelegramFinMaxProdInUserStatus::findFirst(
            "user_status = '" . $user->status . "' AND product_code = '" . $prod_code . "'"
        );
        if(!$product_for_user_group){
            return array("message" => "Внутрішня помилка: Цей продукт для вас не визначено!", "is_ok" => false,);
        }
        if($product_for_user_group->product_max_count == 0){
            return array("message" => "Ви не можете купили цю послугу!", "is_ok" => false);
        }
        if($product->type == 'pay') {
            // set basket
            $user_basket = TelegramFinBasket::findFirst(
                "user_id = '" . $user_id . "' AND product_code = '" . $prod_code . "'"
            );
            if (!$user_basket) {
                $user_basket = new TelegramFinBasket();
                $user_basket->set_data([
                    "user_id" => $user_id,
                    "product_code" => $prod_code,
                    "product_count" => 0,
                    "period" => $product->period
                ]);
            }

            if ($product_for_user_group->product_max_count <= $user_basket->product_count) {
                return array("message" => "Ви купили максимальну кількість!", "is_ok" => false);
            }
        }

        // is money
        $sender_wallet = TelegramFinMoney::findFirst("user_id = '" . $sender_id . "'");
        if($sender_wallet->status == 'locked'){
            return array("message" => "У вас заблокований рахунок :(","is_ok" => false);
        }
        if($sender_wallet->count < $product->price){
            return array("message" => "У вас не достатньо REcoin для оплати :(","is_ok" => false);
        }

        // set transaction
        $transaction = new TelegramFinTransaction();
        $transaction->set_data([
            "sender_id" => $sender_id,
            "receiver_id" => $receiver_id,
            "amount" => $product->price,
            "product_code" => $product->code,
            "type" => $product->type,      // [send, receive, pay, mine, top-up]
            "status" => "processing", // [processing, success, failure]
            "description" => "",
        ]);
        if(!$transaction->save()){
            return array("message" => Messages::getModelSaveErrText($transaction), "is_ok" => false);
        };
        // start transaction
        $is_sender_wallet_saved = $sender_wallet->take_coins($product->price);

        $receiver_wallet = TelegramFinMoney::findFirst("user_id = '" . $receiver_id . "'");
        $is_receiver_wallet_saved = $receiver_wallet->add_coins($product->price);

        if($is_sender_wallet_saved && $is_receiver_wallet_saved){
            $transaction->status = "success";
            $transaction->description = "Операція пройшла успішно";
            $transaction->save();
        }else{
            $transaction->status = "failure";
            $transaction->description = "Помилка БД";
            if($is_sender_wallet_saved){
                $sender_wallet->count = (int)$sender_wallet->count + (int)$product->price;
                $sender_wallet->save();
            }
            if($is_receiver_wallet_saved){
                $receiver_wallet->count = (int)$receiver_wallet->count - (int)$product->price;
                $receiver_wallet->save();
            }
            $transaction->save();
            return array("message" => "Внутрішня помилка: один з гаманців не достуний.", "is_ok" => false);
        }

        if($product->type == 'pay') { // increment product count
            $user_basket->product_count++;
            $user_basket->save();
        }


        if($prod_code == 1001){ // change status
            $user->status = "paid";
            $user->save();
        }
        return array("message" => $transaction->description, "is_ok" => true);
    }
}