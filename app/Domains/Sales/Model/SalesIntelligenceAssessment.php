<?php
declare(strict_types=1);
namespace Domains\Sales\Model;
use InvalidArgumentException;
use Kernel\Agent\AgentResult;

final readonly class SalesIntelligenceAssessment
{
    public function __construct(public string $dealHealth,public string $riskLevel,public array $riskReasons,public string $opportunityLevel,public string $customerIntent,public array $objections,public array $missingInformation,public string|array $nextBestAction,public string $recommendedTiming,public float $confidence)
    {if($dealHealth===''||$riskLevel===''||$opportunityLevel===''||$customerIntent===''||$recommendedTiming===''||$confidence<0||$confidence>1)throw new InvalidArgumentException('Invalid Sales intelligence assessment.');}
    public static function fromDecision(AgentResult $decision,array $allowedActions):self
    {
        $data=$decision->evidence['sales_intelligence']??null;if(!is_array($data))throw new InvalidArgumentException('Missing sales_intelligence evidence.');
        foreach(['risk_reasons','objections','missing_information'] as $field)if(!isset($data[$field])||!is_array($data[$field]))throw new InvalidArgumentException('Sales intelligence '.$field.' must be an array.');
        $next=$data['next_best_action']??'';$type=is_array($next)?(string)($next['type']??''):(string)$next;if($type!==''&&!in_array($type,$allowedActions,true))throw new InvalidArgumentException('Sales intelligence contains an unknown action.');
        return new self((string)($data['deal_health']??''),(string)($data['risk_level']??''),$data['risk_reasons'],(string)($data['opportunity_level']??''),(string)($data['customer_intent']??''),$data['objections'],$data['missing_information'],$next,(string)($data['recommended_timing']??''),$decision->confidence);
    }
}
