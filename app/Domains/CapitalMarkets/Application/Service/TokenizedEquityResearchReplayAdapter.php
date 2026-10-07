<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\ResearchReplayAdapterInterface;
use Domains\CapitalMarkets\Domain\Research\ReplayDataGuard;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final readonly class TokenizedEquityResearchReplayAdapter implements ResearchReplayAdapterInterface
{
    public function __construct(
        private TokenizedEquityHistoricalReplayService $replay,
        private ReplayDataGuard $guard,
    ){}

    public function supports(string $hypothesisCode):bool
    {
        return in_array(strtoupper(trim($hypothesisCode)),['H1','H2'],true);
    }

    public function replay(string $organizationId,string $hypothesisCode,array $configuration):array
    {
        $code=strtoupper(trim($hypothesisCode));
        if(!$this->supports($code))throw new InvalidArgumentException('Tokenized Equity replay supports H1/H2 only.');
        $this->guard->assertTransactionCosts($configuration);
        foreach(['buy_fee_rate','sell_fee_rate'] as $required){
            if(!array_key_exists($required,$configuration))throw new InvalidArgumentException($required.' is required for Tokenized Equity replay.');
        }

        $raw=$this->replay->replay($organizationId,$configuration);
        $rows=[];$total=Decimal::fromString('0');$positive=0;$validated=0;
        foreach((array)($raw['observations']??[]) as $observation){
            if(strtoupper((string)($observation['hypothesis']??''))!==$code)continue;
            $pnl=Decimal::fromString((string)($observation['expected_pnl']??'0'));
            $total=DecimalMath::add($total,$pnl);
            if($pnl->isPositive())$positive++;
            $executable=(bool)($observation['executable']??false);
            if($executable)$validated++;
            $rows[]=[
                'snapshot_id'=>$observation['snapshot_id']??null,
                'status'=>$executable?'VALIDATED':'REJECTED',
                'executable'=>$executable,
                'expected_net_pnl'=>$pnl->value(),
                'reasons'=>(array)($observation['issues']??[]),
                'evidence'=>[
                    'gross_edge_bps'=>$observation['gross_edge_bps']??null,
                    'expected_net_edge_bps'=>$observation['expected_net_edge_bps']??null,
                    'market_regime'=>'NORMAL',
                ],
            ];
        }
        $count=count($rows);
        $countDecimal=Decimal::fromString((string)max(1,$count));
        return [
            'hypothesis'=>$code,
            'sample_count'=>$count,
            'skipped_count'=>max(0,(int)($raw['observation_count']??0)-$count),
            'positive_count'=>$positive,
            'validated_count'=>$validated,
            'positive_rate'=>$count===0?'0':DecimalMath::divide(Decimal::fromString((string)$positive),$countDecimal,12)->value(),
            'validated_rate'=>$count===0?'0':DecimalMath::divide(Decimal::fromString((string)$validated),$countDecimal,12)->value(),
            'expected_pnl_total'=>$total->value(),
            'expected_pnl_average'=>$count===0?'0':DecimalMath::divide($total,$countDecimal,12)->value(),
            'execution_fidelity'=>'MEDIUM',
            'production_economics_reused'=>true,
            'dataset_hash'=>$raw['dataset_hash']??null,
            'rows'=>$rows,
        ];
    }
}
