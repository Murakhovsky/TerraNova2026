<?php

namespace Modules\Games\Templates\Services;

use Modules\Game\Models\Challenge;
use Modules\Economy\Services\EconomyService;

class GameService
{
    public function getRandomChallenge(int $userId): array
    {
        // повертаємо challange + random answers
    }

    public function checkAnswer(int $userId, int $challengeId, string $answer): array
    {
        $challenge = Challenge::findFirst($challengeId);
        $correct = $challenge->correct_answer === $answer;

        if ($correct) {
            EconomyService::rewardXp($userId, 10);
            EconomyService::rewardCoins($userId, 3, 'Answer quiz');
        }

        return ['correct'=>$correct];
    }

    public function getLeaderboard(): array
    {
        return \Modules\User\Models\User::query()
            ->columns(['id','username','xp','level'])
            ->orderBy('xp DESC')
            ->limit(20)
            ->execute()
            ->toArray();
    }
}
