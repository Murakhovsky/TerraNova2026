<?php

/**
 * FinancialModule.php
 * Незалежний фінансовий модуль для Telegram-бота та Web-сервісів
 * Використовує Phalcon 5 + MySQL
 */
namespace Modules\Economy\Controllers;

use Phalcon\Mvc\Controller;
use Phalcon\Db\Adapter\Pdo\Mysql as DbAdapter;
use Phalcon\Http\Response;
use Modules\Economy\Models\Wallets;

class WalletController extends Controller
{
    public function initialize()
    {
        // Ініціалізація БД, конфігурації тощо
    }

    // GET /wallet/{user_id}
    public function getBalanceAction($user_id)
    {
        $wallet = Wallets::findFirstByUserId($user_id);
        if (!$wallet) {
            return $this->response->setJsonContent(['error' => 'Wallet not found']);
        }
        return $this->response->setJsonContent([
            'coins' => $wallet->coins,
            'xp' => $wallet->xp,
            'level' => $wallet->level,
            'token_balance' => $wallet->token_balance
        ]);
    }

    // POST /wallet/earn
    public function earnAction()
    {

        $data = $this->request->getJsonRawBody();
        $user_id = $data->user_id;
        $xp = $data->xp;
        $coins = $data->coins;
        $meta = $data->meta ?? null;

        $wallet = Wallets::findFirstByUserId($user_id);
        if (!$wallet) {
            return $this->response->setJsonContent(['error' => 'Wallet not found']);
        }

        $wallet->xp += $xp;
        $wallet->coins += $coins;

        $new_level = LevelRules::getLevelByXP($wallet->xp);
        $wallet->level = $new_level;

        $wallet->save();

        Transactions::log($user_id, 'game_reward', $coins, 'coin', $meta);
        XPLog::log($user_id, $xp, $meta);

        return $this->response->setJsonContent([
            'success' => true,
            'new_balance' => [
                'coins' => $wallet->coins,
                'xp' => $wallet->xp,
                'level' => $wallet->level
            ]
        ]);
    }

    // POST /wallet/spend
    public function spendAction()
    {
        $data = $this->request->getJsonRawBody();
        $user_id = $data->user_id;
        $amount = $data->amount;
        $description = $data->description;

        $wallet = Wallets::findFirstByUserId($user_id);
        if ($wallet->coins < $amount) {
            return $this->response->setJsonContent(['error' => 'Insufficient funds']);
        }

        $wallet->coins -= $amount;
        $wallet->save();

        Transactions::log($user_id, 'purchase', $amount, 'coin', $description);

        return $this->response->setJsonContent(['success' => true, 'coins' => $wallet->coins]);
    }

    // POST /wallet/convert
    public function convertAction()
    {
        $data = $this->request->getJsonRawBody();
        $user_id = $data->user_id;
        $direction = $data->direction; // 'coin_to_token' або 'token_to_coin'
        $rate = 0.001; // приклад курсу

        $wallet = Wallets::findFirstByUserId($user_id);

        if ($direction === 'coin_to_token') {
            $coin_amount = $data->amount;
            if ($wallet->coins < $coin_amount) {
                return $this->response->setJsonContent(['error' => 'Insufficient coins']);
            }
            $token_amount = $coin_amount * $rate;
            $wallet->coins -= $coin_amount;
            $wallet->token_balance += $token_amount;
        } elseif ($direction === 'token_to_coin') {
            $token_amount = $data->amount;
            if ($wallet->token_balance < $token_amount) {
                return $this->response->setJsonContent(['error' => 'Insufficient tokens']);
            }
            $coin_amount = $token_amount / $rate;
            $wallet->token_balance -= $token_amount;
            $wallet->coins += $coin_amount;
        } else {
            return $this->response->setJsonContent(['error' => 'Invalid direction']);
        }

        $wallet->save();

        CoinConversions::log($user_id, $direction, $coin_amount ?? null, $token_amount ?? null, $rate);

        return $this->response->setJsonContent([
            'success' => true,
            'coins' => $wallet->coins,
            'token_balance' => $wallet->token_balance
        ]);
    }
}

// Додаткові моделі: Wallets, Transactions, XPLog, CoinConversions, LevelRules мають стандартну ORM-структуру
