<?php
namespace Terra\Services;

use Modules\Economy\Models\Wallets;
use Terra\Models\Transactions;

class WalletService
{
    public function credit(int $userId, float $amount, string $currency = 'coin', string $reason = 'bonus'): void
    {
        $wallet = $this->getOrCreateWallet($userId, $currency);
        $wallet->balance += $amount;
        $wallet->save();

        $this->createTransaction($userId, $amount, 'credit', $currency, $reason);
    }

    public function debit(int $userId, float $amount, string $currency = 'coin', string $reason = 'payment'): bool
    {
        $wallet = $this->getOrCreateWallet($userId, $currency);

        if ($wallet->balance < $amount) {
            return false;
        }

        $wallet->balance -= $amount;
        $wallet->save();

        $this->createTransaction($userId, $amount, 'debit', $currency, $reason);
        return true;
    }

    protected function getWallet(int $userId, string $currency): Wallets
    {
        return Wallets::findFirstOrCreate( $userId, $currency);
    }

    public function transfer(int $fromUserId, int $toUserId, string $currency, float $amount, string $reason): bool
    {
        $db = $this->di->get('db');
        $db->begin();

        try {
            // Зменшуємо баланс відправника
            $fromWallet = Wallets::findFirstOrCreate($fromUserId, $currency);
            if ($fromWallet->balance < $amount) {
                throw new Exception('Недостатньо коштів');
            }
            $fromWallet->balance -= $amount;
            if (!$fromWallet->save()) {
                throw new Exception('Помилка збереження балансу відправника');
            }

            // Додаємо транзакцію відправника
            $debit = new Transactions();
            $debit->user_id = $fromUserId;
            $debit->currency = $currency;
            $debit->amount = $amount;
            $debit->type = 'debit';
            $debit->reason = $reason;
            if (!$debit->save()) {
                throw new Exception('Помилка збереження транзакції відправника');
            }

            // Збільшуємо баланс отримувача
            $toWallet = Wallets::findFirstOrCreate($toUserId,$currency);
            $toWallet->balance += $amount;
            if (!$toWallet->save()) {
                throw new Exception('Помилка збереження балансу отримувача');
            }

            // Додаємо транзакцію отримувача
            $credit = new Transactions();
            $credit->user_id = $toUserId;
            $credit->currency = $currency;
            $credit->amount = $amount;
            $credit->type = 'credit';
            $credit->reason = $reason;
            if (!$credit->save()) {
                throw new Exception('Помилка збереження транзакції отримувача');
            }

            $db->commit();
            return true;

        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    public static function getBalanceByUser(int $user_id):int
    {
        if(!($wallet = Wallets::findFirst("user_id = '$user_id'"))){
            $wallet = new Wallets();
            $wallet->create($user_id);
        };
        return $wallet->balance;
    }

}
