<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Service;

use DomainException;
use Domains\ProductManagement\Domain\ProductRules;
use PDO;
use Throwable;

/**
 * Stages validated CSV without mutating Product. Commit is internal-only until
 * canonical Action/Policy binding is added. Each row has stable idempotency.
 */
final readonly class CsvProductImportService
{
    public function __construct(private PDO $connection,private ProductManagementService $products) {}

    /** @return array<string,mixed> */
    public function preview(string $org, string $csv, string $actor): array
    {
        if ($org===''||strlen($org)>190||$actor===''||strlen($actor)>190
            ||strlen($csv)>2097152||$csv==='') throw new DomainException('Invalid CSV staging context or size.');
        if (str_contains($csv,"\0")) throw new DomainException('NUL byte forbidden.');
        $digest=hash('sha256',$csv);
        $batchId=substr(hash('sha256',$org."\n".'csv'."\n".$digest),0,32);
        $existing=$this->batch($org,$batchId);
        if ($existing!==null) return $this->report($org,$batchId);
        $input=fopen('php://temp','w+');
        if ($input===false) throw new DomainException('Cannot stage CSV input.');
        try {
            fwrite($input,preg_replace('/^\xEF\xBB\xBF/','',$csv));
            rewind($input);
            $first=fgets($input);
            if ($first===false) throw new DomainException('CSV missing header.');
            $separator=substr_count($first,';')>substr_count($first,',')?';':',';
            rewind($input);
            $header=fgetcsv($input,0,$separator);
            if ($header!==['sku','name','type','uom','track_inventory']) {
                throw new DomainException('CSV header must be sku,name,type,uom,track_inventory.');
            }
            $rows=[];$seen=[];$no=0;
            while (($fields=fgetcsv($input,0,$separator))!==false) {
                if ($fields===[null]) continue;
                if (++$no>5000) throw new DomainException('CSV staging limited to 5000 items per batch.');
                $status='ready';$error=null;$payload=[];
                try {
                    if (count($fields)!==5) throw new DomainException('Wrong column count');
                    foreach ($fields as $v) if (preg_match('/^[=+@]/',$v)) throw new DomainException('Spreadsheet formula prefix forbidden.');
                    $sku=ProductRules::sku($fields[0]);
                    if (isset($seen[$sku])) throw new DomainException('Duplicate SKU in file');
                    $seen[$sku]=true;
                    $type=ProductRules::productType($fields[2]?:'stock');
                    $track=filter_var($fields[4],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
                    if ($track===null) throw new DomainException('Invalid track_inventory boolean');
                    $payload=['sku'=>$sku,'name'=>ProductRules::name($fields[1]),'type'=>$type,'uom'=>$fields[3]?:'pcs','track_inventory'=>$track];
                    $match=$this->query('SELECT variant_id FROM pm_variants WHERE organization_id=? AND sku=?',[$org,$sku])->fetch(PDO::FETCH_ASSOC);
                    if ($match!==false) {$status='duplicate';$error='SKU already exists in tenant';}
                } catch (Throwable $e) {
                    $status='invalid';$error=mb_substr($e->getMessage(),0,128);
                    $payload=['raw'=>$fields];
                }
                $rows[]=['row_no'=>$no,'sku'=>$payload['sku']??null,'payload'=>$payload,'status'=>$status,'error'=>$error];
            }
            if ($no===0) throw new DomainException('CSV contains no rows.');
            $this->connection->beginTransaction();
            try {
                $this->query('INSERT INTO pm_import_batches (organization_id,batch_id,source,digest,actor_ref) VALUES (?,?,?,?,?)',[$org,$batchId,'csv',$digest,$actor]);
                foreach ($rows as $row) {
                    $this->query('INSERT INTO pm_import_rows (organization_id,batch_id,row_no,sku,payload_json,status,error_code) VALUES (?,?,?,?,?,?,?)',
                        [$org,$batchId,$row['row_no'],$row['sku'],json_encode($row['payload'],JSON_THROW_ON_ERROR),$row['status'],$row['error']]);
                }
                $this->connection->commit();
            } catch (Throwable $e) {
                if ($this->connection->inTransaction()) $this->connection->rollBack();
                throw $e;
            }
            return $this->report($org,$batchId);
        } finally { fclose($input); }
    }

    /**
     * No CSV apply method is exposed until a validated Kernel Action admission
     * and approval-evidence verifier are bound. Staging remains read-only.
     * Never accept caller-supplied approval strings as sufficient authority.
     */
    /** @return array<string,mixed>|null */
    private function batch(string $org,string $batchId): ?array
    {
        $row=$this->query('SELECT * FROM pm_import_batches WHERE organization_id=? AND batch_id=?',[$org,$batchId])->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    /** @return array<string,mixed> */
    public function report(string $org,string $batchId): array
    {
        $batch=$this->batch($org,$batchId);
        if ($batch===null) throw new DomainException('Unknown batch.');
        $rows=$this->query('SELECT row_no,sku,status,error_code,product_id FROM pm_import_rows WHERE organization_id=? AND batch_id=? ORDER BY row_no',[$org,$batchId])->fetchAll(PDO::FETCH_ASSOC);
        $counts=[];
        foreach ($rows as $r) $counts[$r['status']]=($counts[$r['status']]??0)+1;
        return ['batch_id'=>$batchId,'state'=>$batch['state'],'counts'=>$counts,'rows'=>$rows];
    }

    /** @param list<mixed> $params */
    private function query(string $sql,array $params): \PDOStatement
    {
        $stmt=$this->connection->prepare($sql);$stmt->execute($params);return $stmt;
    }
}
