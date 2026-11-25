<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Service;

use DomainException;
use Domains\ProductManagement\Domain\ExtendedProductCsvRow;
use Domains\ProductManagement\Domain\AttributeRules;
use Domains\ProductManagement\Application\Contract\ProductMediaFileReferenceInterface;
use Domains\ProductManagement\Domain\ProductRules;
use PDO;
use PDOException;
use Throwable;

/**
 * Stages validated CSV without mutating Product. Commit is internal-only until
 * canonical Action/Policy binding is added. Each row has stable idempotency.
 */
final readonly class CsvProductImportService
{
    public function __construct(private PDO $connection,private ProductManagementService $products,
        private ?ProductAssemblyService $assembly=null,private ?ProductMediaFileReferenceInterface $files=null) {}

    /** @return array<string,mixed> */
    public function preview(string $org, string $csv, string $actor, ?string $key = null): array
    {
        if ($org===''||strlen($org)>190||$actor===''||strlen($actor)>190
            ||strlen($csv)>2097152||$csv==='') throw new DomainException('Invalid CSV staging context or size.');
        if (str_contains($csv,"\0")) throw new DomainException('NUL byte forbidden.');
        $digest=hash('sha256',$csv);
        $batchId=substr(hash('sha256',$org."\n".'csv'."\n".$digest),0,32);
        if ($key!==null) {
            if ($key===''||strlen($key)>191) throw new DomainException('Bounded CSV idempotency key required.');
            $request=$this->request($org,$key);
            if ($request!==null) {
                if (!hash_equals((string)$request['payload_digest'],$digest)) {
                    throw new DomainException('CSV idempotency key reused for another payload.');
                }
                return $this->report($org,(string)$request['batch_id']);
            }
        }
        $existing=$this->batch($org,$batchId);
        if ($existing!==null) {
            if ($key!==null) $this->linkRequest($org,$key,$batchId,$digest);
            return $this->report($org,$batchId);
        }
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
            $extended=$header===ExtendedProductCsvRow::HEADER;
            if (!$extended&&$header!==['sku','name','type','uom','track_inventory']) {
                throw new DomainException('CSV header must match legacy or extended format.');
            }
            if($extended&&($this->assembly===null||$this->files===null))throw new DomainException('Extended assembly import not available.');
            $rows=[];$seen=[];$no=0;
            while (($fields=fgetcsv($input,0,$separator))!==false) {
                if ($fields===[null]) continue;
                if (++$no>5000) throw new DomainException('CSV staging limited to 5000 items per batch.');
                $status='ready';$error=null;$payload=[];
                try {
                    if (count($fields)!==($extended?11:5)) throw new DomainException('Wrong column count');
                    foreach ($fields as $v) if (preg_match('/^[=+@]/',$v)) throw new DomainException('Spreadsheet formula prefix forbidden.');
                    $sku=ProductRules::sku($fields[0]);
                    if (isset($seen[$sku])) throw new DomainException('Duplicate SKU in file');
                    $type=ProductRules::productType($fields[2]?:'stock');
                    $track=filter_var($fields[4],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
                    if ($track===null) throw new DomainException('Invalid track_inventory boolean');
                    $payload=['sku'=>$sku,'name'=>ProductRules::name($fields[1]),'type'=>$type,'uom'=>$fields[3]?:'pcs','track_inventory'=>$track];
                    if($extended){
                        $payload['assembly']=ExtendedProductCsvRow::assembly($payload,$fields);
                        $this->validateAssemblyReferences($org,$payload['assembly']);
                    }
                    $skus=$extended?array_column($payload['assembly']['variants'],'sku'):[$sku];
                    foreach($skus as $candidate){
                        if(isset($seen[$candidate]))throw new DomainException('Duplicate SKU in file');
                        $seen[$candidate]=true;
                        $match=$this->query('SELECT variant_id FROM pm_variants WHERE organization_id=? AND sku=?',[$org,$candidate])->fetch(PDO::FETCH_ASSOC);
                        if($match!==false){$status='duplicate';$error='SKU already exists in tenant';}
                    }
                } catch (Throwable $e) {
                    $status='invalid';$error=mb_substr($e->getMessage(),0,128);
                    $payload=['raw'=>$fields];
                }
                $rows[]=['row_no'=>$no,'sku'=>$payload['sku']??null,'payload'=>$payload,'status'=>$status,'error'=>$error];
            }
            if ($no===0) throw new DomainException('CSV contains no rows.');
            // Immutable original preview evidence is checked again when an
            // independently approved Action executes, not trusted from input.
            $hashes=[];
            foreach ($rows as &$row) {
                $row['staged_hash']=self::rowDigest($row['row_no'],$row['sku'],$row['payload'],$row['status'],$row['error']);
                $hashes[]=$row['staged_hash'];
            }
            unset($row);
            $rowsDigest=hash('sha256',implode("\\n",$hashes));
            $this->connection->beginTransaction();
            try {
                $this->query('INSERT INTO pm_import_batches (organization_id,batch_id,source,digest,actor_ref,rows_digest) VALUES (?,?,?,?,?,?)',[$org,$batchId,'csv',$digest,$actor,$rowsDigest]);
                foreach ($rows as $row) {
                    $this->query('INSERT INTO pm_import_rows (organization_id,batch_id,row_no,sku,payload_json,status,error_code,staged_hash,staged_status,staged_error_code) VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [$org,$batchId,$row['row_no'],$row['sku'],json_encode($row['payload'],JSON_THROW_ON_ERROR),$row['status'],$row['error'],$row['staged_hash'],$row['status'],$row['error']]);
                }
                if ($key!==null) $this->linkRequest($org,$key,$batchId,$digest);
                $this->connection->commit();
            } catch (Throwable $e) {
                if ($this->connection->inTransaction()) $this->connection->rollBack();
                // A concurrent identical CSV may have staged the same digest.
                // Recover only after checking the authoritative batch + request.
                if ($e instanceof PDOException && $e->getCode()==='23000') {
                    $request=$key!==null?$this->request($org,$key):null;
                    if ($request!==null) {
                        if (!hash_equals((string)$request['payload_digest'],$digest)) {
                            throw new DomainException('Conflicting CSV idempotency payload.');
                        }
                        return $this->report($org,(string)$request['batch_id']);
                    }
                    if ($this->batch($org,$batchId)!==null) {
                        if ($key!==null) $this->linkRequest($org,$key,$batchId,$digest);
                        return $this->report($org,$batchId);
                    }
                }
                throw $e;
            }
            return $this->report($org,$batchId);
        } finally { fclose($input); }
    }

    private function validateAssemblyReferences(string $org,array $assembly):void
    {
        $defs=[];
        foreach($assembly['category_ids'] as $category){
            if($this->query('SELECT category_id FROM pm_categories WHERE organization_id=? AND category_id=?',[$org,$category])->fetchColumn()===false)
                throw new DomainException('Category missing in tenant.');
            foreach($this->products->categoryAttributes($org,$category) as $def){
                if(isset($defs[$def['code']]))$def['is_required']=$def['is_required']||$defs[$def['code']]['is_required'];
                $defs[$def['code']]=$def;
            }
        }
        if($assembly['attributes']!==[])foreach(AttributeRules::changes($assembly['attributes']) as $code=>$value){
            if(!isset($defs[$code])||($value===null&&$defs[$code]['is_required']))throw new DomainException('Attribute not applicable or required.');
            AttributeRules::value($defs[$code],$value);
        }
        $links=[];$covers=[];
        foreach($assembly['media'] as $image){
            $this->files->verifiedImage($org,$image['file_id']);
            $scope=$image['sku']??'product';$link=$scope.':'.$image['file_id'];
            if(isset($links[$link])||($image['role']==='cover'&&isset($covers[$scope])))throw new DomainException('Duplicate image link or cover.');
            $links[$link]=true;if($image['role']==='cover')$covers[$scope]=true;
        }
    }

    /** Canonical representation survives MySQL JSON object key normalization. */
    public static function rowDigest(int $no,?string $sku,array $payload,string $status,?string $error):string
    {
        $normalize=static function(mixed $value)use(&$normalize):mixed{
            if(!is_array($value))return $value;
            if(!array_is_list($value))ksort($value,SORT_STRING);
            foreach($value as $key=>$item)$value[$key]=$normalize($item);
            return $value;
        };
        return hash('sha256',json_encode([$no,$sku,$normalize($payload),$status,$error],JSON_THROW_ON_ERROR));
    }

    /**
     * Independent human Plan proposal reads validated, pinned CSV evidence.
     * No Product writes, no Action submit, no approving on behalf of a user.
     * @return list<array{row_no:int,sku:string,hash:string}>
     */
    public function reviewForPlan(string $org,string $batchId,string $digest,string $rowsDigest):array
    {
        if($org===''||strlen($org)>64||!preg_match('/^[a-f0-9]{32}$/D',$batchId)
            ||!preg_match('/^[a-f0-9]{64}$/D',$digest)
            ||!preg_match('/^[a-f0-9]{64}$/D',$rowsDigest)) {
            throw new DomainException('Invalid Product CSV Plan review identity.');
        }
        $batch=$this->batch($org,$batchId);
        if($batch===null||$batch['state']!=='staged'
            ||($batch['plan_id']??null)!==null
            ||!hash_equals((string)$batch['digest'],$digest)
            ||!is_string($batch['rows_digest'])
            ||!hash_equals($batch['rows_digest'],$rowsDigest)){
            throw new DomainException('CSV Plan review requires unclaimed immutable staged batch.');
        }
        $rows=$this->stagedRows($org,$batchId,false);
        $this->verifySnapshot($rows,$rowsDigest);
        $ready=[];
        foreach($rows as $row){
            if($row['staged_status']!=='ready'||$row['status']!=='ready'
                ||!is_string($row['sku'])||$row['sku']==='') {
                throw new DomainException('CSV Plan requires every row ready; resolve duplicates and invalid rows first.');
            }
            $ready[]=['row_no'=>(int)$row['row_no'],'sku'=>$row['sku'],'hash'=>$row['staged_hash']];
        }
        return $ready;
    }

    /** @return array<string,mixed>|null */
    private function request(string $org,string $key): ?array
    {
        $row=$this->query('SELECT batch_id,payload_digest FROM pm_import_requests WHERE organization_id=? AND idempotency_key=?',[$org,$key])->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    private function linkRequest(string $org,string $key,string $batchId,string $digest): void
    {
        try {
            $this->query('INSERT INTO pm_import_requests (organization_id,idempotency_key,batch_id,payload_digest) VALUES (?,?,?,?)',
                [$org,$key,$batchId,$digest]);
        } catch (PDOException $e) {
            if ($e->getCode()!=='23000') throw $e;
            $stored=$this->request($org,$key);
            if ($stored===null || !hash_equals((string)$stored['payload_digest'],$digest)
                || (string)$stored['batch_id']!==$batchId) {
                throw new DomainException('CSV preview idempotency conflict.');
            }
        }
    }

    /**
     * Only called from the approved canonical Action worker. No UI, cron,
     * preview endpoint or Capability direct command can invoke this operation.
     *
     * Row-level Product creation owns its own durable receipt + Outbox transaction.
     * A crash before the row receipt update is recovered by the SAME row key.
     * Failures quarantine the batch; no automatic blind replay.
     * @return array<string,mixed>
     */
    public function applyApproved(string $org,string $batchId,string $digest,string $rowsDigest,
        string $actor,string $actionKey,string $correlation):array
    {
        if($org===''||strlen($org)>40
            ||!preg_match('/^[a-f0-9]{32}$/D',$batchId)
            ||!preg_match('/^[a-f0-9]{64}$/D',$digest)
            ||!preg_match('/^[a-f0-9]{64}$/D',$rowsDigest)
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',$actionKey)
            ||!ctype_digit($actor)||strlen($actor)>100
            ||$correlation===''||strlen($correlation)>191) {
            throw new DomainException('Invalid approved Product CSV Action identity.');
        }
        // MySQL advisory lock is connection-scoped and released on process
        // death. Concurrent worker connections must never run the same
        // approved batch together, even when they share an Action key.
        // This is NOT an executor: it only fences this Domain's writer.
        $lockName='pm-csv:'.substr(hash('sha256',$org.':'.$batchId),0,48);
        $lock=$this->query('SELECT GET_LOCK(?,0)',[$lockName])->fetchColumn();
        if((int)$lock!==1) {
            throw new DomainException('Product CSV already has an active writer; retry requires reconciliation.');
        }
        try {
        // Claim the original approved snapshot under a row lock. This method
        // must never be called within another active PDO transaction.
        $this->connection->beginTransaction();
        try {
            $batch=$this->query('SELECT * FROM pm_import_batches WHERE organization_id=? AND batch_id=? FOR UPDATE',
                [$org,$batchId])->fetch(PDO::FETCH_ASSOC);
            if($batch===false||$batch['source']!=='csv'
                ||!hash_equals((string)$batch['digest'],$digest)
                ||!is_string($batch['rows_digest'])||!hash_equals($batch['rows_digest'],$rowsDigest)
                ||!in_array($batch['state'],['staged','applying','applied'],true)
                ||($batch['action_key']!==null&&!hash_equals((string)$batch['action_key'],$actionKey))) {
                throw new DomainException('Product CSV approved digest, Action fence or state mismatch.');
            }
            $rows=$this->stagedRows($org,$batchId,true);
            $this->verifySnapshot($rows,$rowsDigest);
            if($batch['state']==='staged') {
                $this->query("UPDATE pm_import_batches SET state='applying',action_key=? WHERE organization_id=? AND batch_id=? AND state='staged'",
                    [$actionKey,$org,$batchId]);
            }
            $this->connection->commit();
        }catch(Throwable $e){
            if($this->connection->inTransaction())$this->connection->rollBack();
            throw $e;
        }
        $applied=[];
        foreach($rows as $row){
            if($row['staged_status']!=='ready')continue;
            $rowNo=(int)$row['row_no'];
            $key='pm-csv:'.hash('sha256',$org.':'.$batchId.':'.$rowNo);
            if($row['status']==='applied') {
                $this->assertNativeRow($org,$row,$key);
                $applied[]=(string)$row['product_id'];
                continue;
            }
            if($row['status']!=='ready') {
                throw new DomainException('Product CSV contains a row requiring manual reconciliation.');
            }
            try {
                $input=json_decode((string)$row['payload_json'],true,flags:JSON_THROW_ON_ERROR);
                if(!is_array($input)||array_is_list($input)||($input['sku']??null)!==$row['sku']) {
                    throw new DomainException('Invalid approved CSV row payload.');
                }
                if(isset($input['assembly'])){
                    if($this->assembly===null||$this->files===null)throw new DomainException('Extended import unavailable.');
                    $result=$this->assembly->assemble($org,$input['assembly'],$key,$actor,$correlation);
                    $this->assembly->assertReadback($org,$input['assembly'],$result);
                }else $result=$this->products->createProduct($org,$input,$key,$actor,$correlation);
                $native=$this->products->getProduct($org,(string)($result['product_id']??''));
                $this->assertNative($native,$row['sku'],$input);
                $this->connection->beginTransaction();
                try {
                    $updated=$this->query("UPDATE pm_import_rows SET status='applied',product_id=?,receipt_key=?,applied_at=UTC_TIMESTAMP(6)
                        WHERE organization_id=? AND batch_id=? AND row_no=? AND staged_status='ready' AND status='ready' AND product_id IS NULL AND receipt_key IS NULL",
                        [$result['product_id'],$key,$org,$batchId,$rowNo]);
                    if($updated->rowCount()!==1)throw new DomainException('CSV row receipt state conflict.');
                    $this->connection->commit();
                }catch(Throwable $error){
                    if($this->connection->inTransaction())$this->connection->rollBack();
                    throw $error;
                }
                $applied[]=(string)$result['product_id'];
            }catch(Throwable $e){
                // The native Product may already exist if this process crashed
                // between its transaction and this batch receipt. Do not
                // pretend a failed row was never written.
                $this->query("UPDATE pm_import_rows SET status='failed',error_code='manual_reconciliation'
                    WHERE organization_id=? AND batch_id=? AND row_no=? AND status='ready'",
                    [$org,$batchId,$rowNo]);
                $this->query("UPDATE pm_import_batches SET state='blocked'
                    WHERE organization_id=? AND batch_id=? AND action_key=? AND state='applying'",
                    [$org,$batchId,$actionKey]);
                throw new DomainException('CSV row requires manual native Product receipt reconciliation.',previous:$e);
            }
        }
        // The Action result attests independently read native Product records,
        // not only mutable CSV status columns.
        $completed=$this->stagedRows($org,$batchId,false);
        $this->verifySnapshot($completed,$rowsDigest);
        foreach($completed as $row){
            if($row['staged_status']==='ready'){
                if($row['status']!=='applied')throw new DomainException('CSV batch not completely applied.');
                $key='pm-csv:'.hash('sha256',$org.':'.$batchId.':'.(int)$row['row_no']);
                $this->assertNativeRow($org,$row,$key);
            }
        }
        $this->query("UPDATE pm_import_batches SET state='applied'
            WHERE organization_id=? AND batch_id=? AND action_key=? AND state IN ('applying','applied')",
            [$org,$batchId,$actionKey]);
        return ['batch_id'=>$batchId,'verified_count'=>count($applied),
            'product_ids'=>$applied,'state'=>'applied','rows_digest'=>$rowsDigest];
        } finally {
            // RELEASE_LOCK releases only this connection's acquired named lock.
            // No MySQL transaction is permitted to remain active on exit.
            if($this->connection->inTransaction())$this->connection->rollBack();
            $this->query('SELECT RELEASE_LOCK(?)',[$lockName]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function stagedRows(string $org,string $batchId,bool $lock):array
    {
        return $this->query('SELECT row_no,sku,payload_json,status,error_code,product_id,receipt_key,
            staged_hash,staged_status,staged_error_code
            FROM pm_import_rows WHERE organization_id=? AND batch_id=? ORDER BY row_no'.($lock?' FOR UPDATE':''),
            [$org,$batchId])->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string,mixed>> $rows */
    private function verifySnapshot(array $rows,string $expectedDigest):void
    {
        if($rows===[]||count($rows)>5000)throw new DomainException('Approved Product CSV has invalid row count.');
        $hashes=[];$last=0;
        foreach($rows as $row) {
            $no=(int)$row['row_no'];
            $originalStatus=$row['staged_status']??null;
            if($no!==$last+1||!in_array($originalStatus,['ready','duplicate','invalid'],true)
                ||!is_string($row['staged_hash']??null)) {
                throw new DomainException('Product CSV row numbering or original stage is missing.');
            }
            $last=$no;
            $payload=json_decode((string)$row['payload_json'],true,flags:JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new DomainException('Product CSV payload object is missing.');
            $hash=self::rowDigest($no,$row['sku'],$payload,$originalStatus,$row['staged_error_code']);
            if(!hash_equals($row['staged_hash'],$hash)) {
                throw new DomainException('Immutable CSV staged row differs from approved evidence.');
            }
            if($originalStatus==='ready'){
                if(!in_array($row['status'],['ready','applied','failed'],true)) {
                    throw new DomainException('Unexpected approved CSV row state.');
                }
            }elseif($row['status']!==$originalStatus){
                throw new DomainException('Non-ready CSV row was modified after staging.');
            }
            $hashes[]=$hash;
        }
        if(!hash_equals($expectedDigest,hash('sha256',implode("\\n",$hashes)))) {
            throw new DomainException('Immutable CSV rowset differs from approved digest.');
        }
    }

    /** @param array<string,mixed> $row */
    private function assertNativeRow(string $org,array $row,string $key):void
    {
        if(!is_string($row['product_id'])||!preg_match('/^[a-f0-9]{32}$/D',$row['product_id'])
            ||$row['receipt_key']!==$key) {
            throw new DomainException('CSV row lacks matching native Product receipt.');
        }
        $input=json_decode((string)$row['payload_json'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertNative($this->products->getProduct($org,$row['product_id']),$row['sku'],$input);
        if(isset($input['assembly'])){
            $native=$this->query('SELECT operation,result_json FROM pm_operation_receipts WHERE organization_id=? AND idempotency_key=?',[$org,$key])->fetch(PDO::FETCH_ASSOC);
            if($this->assembly===null||$native===false||$native['operation']!=='product.assemble')throw new DomainException('Assembly receipt unavailable.');
            $receipt=json_decode($native['result_json'],true,64,JSON_THROW_ON_ERROR);
            if(($receipt['product_id']??null)!==$row['product_id'])throw new DomainException('Assembly receipt Product mismatch.');
            $this->assembly->assertReadback($org,$input['assembly'],$receipt);
        }
    }

    /** @param array<string,mixed>|null $product @param array<string,mixed> $input */
    private function assertNative(?array $product,?string $sku,array $input):void
    {
        if($product===null||$product['name']!==($input['name']??null)
            ||$product['product_type']!==($input['type']??null)
            ||$product['status']==='archived') {
            throw new DomainException('Native Product no longer matches approved CSV row.');
        }
        $matched=false;
        foreach($product['variants']??[] as $variant) {
            if($variant['sku']===$sku){$matched=true;break;}
        }
        if(!$matched)throw new DomainException('Native Variant SKU no longer matches approved CSV.');
    }

    /**
     * Private CSV staging lookup. Application is restricted to applyApproved,
     * reached solely by a canonical, independently approved Kernel Action.
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
        $rows=$this->query('SELECT row_no,sku,status,error_code,product_id,payload_json FROM pm_import_rows WHERE organization_id=? AND batch_id=? ORDER BY row_no',[$org,$batchId])->fetchAll(PDO::FETCH_ASSOC);
        $counts=[];
        foreach($rows as &$r){
            $counts[$r['status']]=($counts[$r['status']]??0)+1;
            $payload=json_decode($r['payload_json'],true,64,JSON_THROW_ON_ERROR);
            unset($r['payload_json']);
            if(isset($payload['assembly']))$r['assembly']=$payload['assembly'];
        }unset($r);
        return ['batch_id'=>$batchId,'state'=>$batch['state'],'digest'=>$batch['digest'],'rows_digest'=>$batch['rows_digest'],
            'goal_id'=>$batch['goal_id']??null,'plan_id'=>$batch['plan_id']??null,
            'counts'=>$counts,'rows'=>$rows];
    }

    /** @param list<mixed> $params */
    private function query(string $sql,array $params): \PDOStatement
    {
        $stmt=$this->connection->prepare($sql);$stmt->execute($params);return $stmt;
    }
}
