<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Contract\PortfolioNavFinancialEvidenceRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\PortfolioNavFinancialEvidencePolicy;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class MysqlPortfolioNavFinancialEvidenceRepository implements PortfolioNavFinancialEvidenceRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function append(string $organizationId,string $portfolioId,array $observation):void
    {
        if (trim($organizationId)===''||trim($portfolioId)==='') {
            throw new InvalidArgumentException('Tenant and portfolio are mandatory for NAV evidence.');
        }
        $record=PortfolioNavFinancialEvidencePolicy::normalize($observation);
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_nav_financial_evidence
             (organization_id,portfolio_id,evidence_id,kind,source_key_sha256,source_document_sha256,effective_at,record_json)
             VALUES (:org,:portfolio,:id,:kind,:source_key,:digest,:effective,:payload)'
        );
        // No update on duplicate: preserve the original primary source account.
        $statement->execute([
            'org'=>$organizationId,'portfolio'=>$portfolioId,'id'=>$record['evidence_id'],
            'kind'=>$record['kind'],'source_key'=>$record['source_key_sha256'],
            'digest'=>$record['source_document_sha256'],
            'effective'=>(new \DateTimeImmutable($record['effective_at']))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'payload'=>json_encode($record,JSON_THROW_ON_ERROR),
        ]);
    }

    public function forPortfolio(string $organizationId,string $portfolioId,int $limit=2000):array
    {
        $limit=max(1,min(2000,$limit));
        $stmt=$this->connection->prepare(
            'SELECT record_json FROM tn_capital_market_nav_financial_evidence
             WHERE organization_id=:org AND portfolio_id=:portfolio
             ORDER BY id ASC LIMIT '.($limit+1)
        );
        $stmt->execute(['org'=>$organizationId,'portfolio'=>$portfolioId]);
        $rows=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows)>$limit) {
            throw new RuntimeException('NAV_SOURCE_EVIDENCE_PAGE_TRUNCATED');
        }
        $out=[];
        foreach ($rows as $raw) {
            $value=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($value)) {
                throw new RuntimeException('NAV_SOURCE_EVIDENCE_INVALID');
            }
            $out[]=$value;
        }
        return $out;
    }
}
