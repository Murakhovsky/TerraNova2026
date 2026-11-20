<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;
use Kernel\Module\ActiveModuleResolver;
use PDO;
use PDOException;

/**
 * Immutable operator-only staging; never creates native Product/Variant/Price,
 * Stock movements, external mappings, Actions, Goals or Workflow.
 */
final readonly class ShopifyImportPreviewService
{
    public function __construct(
        private PDO $connection,
        private ShopifyObservationStore $observations,
        private ActiveModuleResolver $modules,
    ) {}

    /** @return array<string,mixed> */
    public function stage(string $org,string $conn,string $gid,string $actor,string $expectedHash):array
    {
        if($org===''||strlen($org)>40||!preg_match('/^[a-f0-9]{32}$/D',$conn)
            ||!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)
            ||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)
            ||$actor===''||strlen($actor)>100) {
            throw new DomainException('Shopify preview requires bounded, pinned operator scope.');
        }
        if(!$this->modules->isEnabled($org,'product_management')) {
            throw new DomainException('Product Management must be enabled for this tenant.');
        }
        $connection=$this->observations->connection($org,$conn);
        if(!in_array($connection['mode'],['shopify_authoritative','observation_only'],true)) {
            throw new DomainException('Shopify preview requires read-only ownership.');
        }
        $currency=$connection['shop_currency']??null;
        if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)
            ||!self::fresh($connection['shop_currency_checked_at']??null)) {
            throw new DomainException('Missing recent Shopify-confirmed shop currency.');
        }
        $snapshot=$this->observations->productSnapshot($org,$conn,$gid);
        if($snapshot===null||(int)$snapshot['is_complete']!==1
            ||!self::fresh($snapshot['observed_at']??null)
            ||!is_string($snapshot['payload_hash'])
            ||!hash_equals($expectedHash,$snapshot['payload_hash'])) {
            throw new DomainException('Shopify snapshot is incomplete, stale or changed.');
        }
        // The original digest belongs to the observed Shopify JSON bytes.
        // MySQL JSON may normalize its textual representation; source_hash remains pinned.
        $product=json_decode((string)$snapshot['payload_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($product)||array_is_list($product))throw new DomainException('Invalid Shopify snapshot JSON.');
        $normalized=ShopifyImportCandidateNormalizer::normalize($product,$currency);
        if($normalized['gid']!==$gid)throw new DomainException('Shopify preview identity mismatch.');
        if($this->observations->mapping($org,$conn,'product',$gid)!==null) {
            throw new DomainException('Shopify Product already has canonical mapping. Manual reconciliation required.');
        }
        foreach($normalized['variants'] as $variant) {
            if($this->observations->mapping($org,$conn,'variant',$variant['gid'])!==null) {
                throw new DomainException('Shopify Variant is already mapped.');
            }
            $existing=$this->query('SELECT variant_id FROM pm_variants WHERE organization_id=? AND sku=? LIMIT 1',
                [$org,$variant['sku']])->fetchColumn();
            if($existing!==false) {
                throw new DomainException('Canonical SKU already belongs to a Product; manual identity review required.');
            }
        }
        $json=json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $digest=ShopifyImportCandidateNormalizer::fingerprint($normalized);
        $id=substr(hash('sha256',$org."\0".$conn."\0".$gid."\0".$expectedHash),0,32);
        try {
            $this->query('INSERT INTO pm_shopify_import_previews
                (organization_id,preview_id,connection_id,product_gid,source_hash,
                 normalized_digest,normalized_json,currency,source_observed_at,actor_ref)
                VALUES (?,?,?,?,?,?,?,?,?,?)',[
                $org,$id,$conn,$gid,$expectedHash,$digest,$json,$currency,
                $snapshot['observed_at'],$actor,
            ]);
        }catch(PDOException $e) {
            if($e->getCode()!=='23000')throw $e;
            $existing=$this->query('SELECT source_hash,normalized_digest,currency,state
                FROM pm_shopify_import_previews WHERE organization_id=? AND preview_id=?',
                [$org,$id])->fetch(PDO::FETCH_ASSOC);
            if($existing===false || $existing['state']!=='staged'
                ||!hash_equals((string)$existing['source_hash'],$expectedHash)
                ||!hash_equals((string)$existing['normalized_digest'],$digest)
                ||$existing['currency']!==$currency) {
                throw new DomainException('Shopify immutable preview identity collision.');
            }
        }
        return [
            'status'=>'staged_only','preview_id'=>$id,'connection_id'=>$conn,
            'product_gid'=>$gid,'source_hash'=>$expectedHash,
            'normalized_digest'=>$digest,'currency'=>$currency,
            'product_name'=>$normalized['name'],'variants'=>$normalized['variants'],
            'requires'=>'independent_approved_canonical_import',
        ];
    }

    private static function fresh(mixed $timestamp):bool
    {
        if(!is_string($timestamp)||$timestamp==='')return false;
        $at=strtotime($timestamp.' UTC');
        return $at!==false && $at>=time()-86400 && $at<=time()+60;
    }

    /** @param list<mixed> $parameters */
    private function query(string $sql,array $parameters):\PDOStatement
    {
        $stmt=$this->connection->prepare($sql);
        $stmt->execute($parameters);
        return $stmt;
    }
}
