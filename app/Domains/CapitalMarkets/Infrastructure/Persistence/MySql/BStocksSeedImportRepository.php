<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Historical import provenance, NOT a second canonical instrument registry.
 * Rechecks decisions within transaction and refuses identity conflicts.
 */
final readonly class BStocksSeedImportRepository
{
    public function __construct(private PDO $connection) {}

    /** @return array{new:int,changed:int,unchanged:int,conflicts:int,total:int} */
    public function preview(string $org, array $seed): array
    {
        $this->validate($org,$seed);
        return $this->classify($org,$seed,false);
    }

    public function apply(string $org,int $actor,array $seed): array
    {
        if ($actor < 1) throw new InvalidArgumentException('A positive authorized actor is required.');
        $this->validate($org,$seed);
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Seed import expects a standalone transactional unit.');
        }
        // Serialize competing imports within the tenant even before any seed row exists.
        $lockName='cm-seed-'.substr(hash('sha256',$org),0,48);
        $lock=$this->connection->prepare('SELECT GET_LOCK(:lock_name,5)');
        $lock->execute(['lock_name'=>$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Seed import lock unavailable.');
        try {
        $this->connection->beginTransaction();
        try {
            $stats=$this->classify($org,$seed,true);
            if ($stats['conflicts'] > 0) {
                throw new RuntimeException('Seed identity/source-date conflicts require operator review.');
            }
            $insert=$this->connection->prepare(
                'INSERT INTO tn_capital_market_seed_import_evidence
                  (organization_id,pool_key,token_symbol,underlying_symbol,venue_symbol,
                   reported_as_of,file_sha256,import_format,row_evidence_json,imported_at)
                 VALUES (:org,:pool,:token,:underlying,:venue,:reported,:sha,:format,:raw,UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE
                   reported_as_of=VALUES(reported_as_of),file_sha256=VALUES(file_sha256),
                   import_format=VALUES(import_format),row_evidence_json=VALUES(row_evidence_json),
                   imported_at=VALUES(imported_at)'
            );
            foreach ($seed['rows'] as $row) {
                $insert->execute([
                    'org'=>$org,'pool'=>'BINANCE_BSTOCKS_SEED',
                    'token'=>$row['token_symbol'],'underlying'=>$row['underlying_symbol'],
                    'venue'=>$row['venue_symbol'],
                    'reported'=>$seed['source_as_of'],'sha'=>$seed['sha256'],
                    'format'=>$seed['format'],
                    'raw'=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                ]);
            }
            $log=$this->connection->prepare(
                'INSERT INTO tn_capital_market_seed_import_batches
                  (organization_id,actor_id,file_sha256,source_as_of,format,
                   total_rows,new_rows,changed_rows,unchanged_rows,imported_at)
                 VALUES (:org,:actor,:sha,:as_of,:format,:total,:new,:changed,:unchanged,UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE id=id'
            );
            $log->execute([
                'org'=>$org,'actor'=>$actor,'sha'=>$seed['sha256'],
                'as_of'=>$seed['source_as_of'],'format'=>$seed['format'],
                'total'=>$stats['total'],'new'=>$stats['new'],
                'changed'=>$stats['changed'],'unchanged'=>$stats['unchanged'],
            ]);
            $this->connection->commit();
            return $stats;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
        } finally {
            $release=$this->connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name'=>$lockName]);
        }
    }

    /**
     * Source-backed candidate rows for canonical draft creation; never trading authority.
     * @return list<array{row:array<string,mixed>,file_sha256:string,source_as_of:string}>
     */
    public function listForCanonicalDraft(string $org): array
    {
        if ($org==='' || strlen($org)>190) throw new InvalidArgumentException('Invalid tenant.');
        $statement=$this->connection->prepare(
            'SELECT row_evidence_json,file_sha256,reported_as_of
             FROM tn_capital_market_seed_import_evidence
             WHERE organization_id=:org AND pool_key=:pool ORDER BY token_symbol LIMIT 501'
        );
        $statement->execute(['org'=>$org,'pool'=>'BINANCE_BSTOCKS_SEED']);
        $items=[];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $evidence=json_decode((string)$row['row_evidence_json'],true,64,JSON_THROW_ON_ERROR);
            if (!is_array($evidence)||array_is_list($evidence)) throw new RuntimeException('Corrupt seed evidence.');
            $items[]=['row'=>$evidence,'file_sha256'=>(string)$row['file_sha256'],
                'source_as_of'=>(string)$row['reported_as_of']];
        }
        if (count($items)>500) throw new RuntimeException('Seed candidate pool exceeds 500.');
        return $items;
    }

    private function validate(string $org,array $seed): void
    {
        if ($org === '' || strlen($org)>190 || count($seed['rows'] ?? []) < 1
            || count($seed['rows']) > 500 || !in_array($seed['format'] ?? null,['csv','xlsx','xlsm'],true)
            || preg_match('/^[a-f0-9]{64}$/D',(string)($seed['sha256'] ?? '')) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',(string)($seed['source_as_of'] ?? '')) !== 1) {
            throw new InvalidArgumentException('Invalid seed import request.');
        }
    }

    /** @return array{new:int,changed:int,unchanged:int,conflicts:int,total:int} */
    private function classify(string $org,array $seed,bool $locking): array
    {
        $find=$this->connection->prepare(
            'SELECT underlying_symbol,venue_symbol,reported_as_of,row_evidence_json
             FROM tn_capital_market_seed_import_evidence
             WHERE organization_id=:org AND pool_key=:pool AND token_symbol=:token'
             .($locking?' FOR UPDATE':'')
        );
        $totals=['new'=>0,'changed'=>0,'unchanged'=>0,'conflicts'=>0,'total'=>count($seed['rows'])];
        foreach ($seed['rows'] as $row) {
            $find->execute(['org'=>$org,'pool'=>'BINANCE_BSTOCKS_SEED','token'=>$row['token_symbol']]);
            $existing=$find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) { ++$totals['new']; continue; }
            if ($existing['underlying_symbol']!==$row['underlying_symbol']
                || $existing['venue_symbol']!==$row['venue_symbol']
                || (string)$existing['reported_as_of'] > $seed['source_as_of']) {
                ++$totals['conflicts']; continue;
            }
            $old=json_decode((string)$existing['row_evidence_json'],true,64,JSON_THROW_ON_ERROR);
            if (!is_array($old)) throw new RuntimeException('Corrupt historical seed evidence.');
            $canonical=$row;
            ksort($old,SORT_STRING);
            ksort($canonical,SORT_STRING);
            if ($old === $canonical) { ++$totals['unchanged']; }
            else { ++$totals['changed']; }
        }
        return $totals;
    }
}
