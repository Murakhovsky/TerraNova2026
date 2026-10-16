<?php
declare(strict_types=1);

/**
 * Native MySQL staging/identity constraints. Does not mark a Goal complete
 * or substitute for approved Action -> Property DB -> independent Outcome E2E.
 */
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql',
    'host'=>(string)(getenv('DB_HOST')?:'mysql'),
    'port'=>(int)(getenv('DB_PORT')?:3306),
    'dbname'=>(string)(getenv('DB_DATABASE')?:'cos'),
    'user'=>(string)(getenv('DB_USERNAME')?:'cos'),
    'password'=>(string)(getenv('DB_PASSWORD')?:''),
    'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(6));
$org1='bulk-test-'.$token;
$org2='bulk-other-'.$token;
$a='bulk-'.bin2hex(random_bytes(12));
$b='bulk-'.bin2hex(random_bytes(12));
$c='bulk-'.bin2hex(random_bytes(12));
$digest=hash('sha256',$token);
$check=static function(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
};
$insertBatch=static function(string $org,string $id) use($db,$digest):void {
    $db->insert('cos_property_bulk_batches',[
        'organization_id'=>$org,'batch_id'=>$id,'owner_id'=>'manager-42',
        'digest'=>$digest,'state'=>'staged','created_at'=>gmdate('Y-m-d H:i:s'),
        'updated_at'=>gmdate('Y-m-d H:i:s'),
    ]);
};
$claim=static function(string $org,string $id,string $fingerprint) use($db):void {
    $db->insert('cos_property_bulk_identity_claims',[
        'organization_id'=>$org,'batch_id'=>$id,
        'fingerprint'=>$fingerprint,'created_at'=>gmdate('Y-m-d H:i:s'),
    ]);
};
try {
    $insertBatch($org1,$a);
    $insertBatch($org1,$b);
    $insertBatch($org2,$c);
    for ($index=0;$index<10;$index++) {
        $payload=['type_code'=>'house','city'=>'Львів',
            'address'=>'Test Street '.($index+1),'area_total'=>120.0];
        $db->insert('cos_property_bulk_items',[
            'organization_id'=>$org1,'batch_id'=>$a,'item_index'=>$index,
            'fingerprint'=>hash('sha256','property-'.$token.'-'.$index),
            'payload_json'=>json_encode($payload,JSON_THROW_ON_ERROR),
            'status'=>'staged','updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
    }
    $check((int)$db->fetchOne(
        'SELECT COUNT(*) FROM cos_property_bulk_items
         WHERE organization_id=:org AND batch_id=:id',
        ['org'=>$org1,'id'=>$a])===10,'Ten staged Property rows were not persisted.');
    $check((int)$db->fetchOne(
        'SELECT COUNT(*) FROM tn_property_assets WHERE organization_id=:org',
        ['org'=>$org1])===0,'Staging unexpectedly created native Property Assets.');
    $fp=hash('sha256','property-'.$token.'-0');
    $claim($org1,$a,$fp);
    try {
        $claim($org1,$b,$fp);
        throw new RuntimeException('Concurrent same-tenant duplicate was not fenced.');
    } catch (UniqueConstraintViolationException) {
        // Expected: one fingerprint can be claimed by exactly one batch.
    }
    $claim($org2,$c,$fp);
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM cos_property_bulk_identity_claims
        WHERE fingerprint=:fp',['fp'=>$fp])===2,
        'Tenant scope leaked or prevented an independent organization.');
    $stored=(string)$db->fetchOne('SELECT digest FROM cos_property_bulk_batches
        WHERE organization_id=:org AND batch_id=:id',
        ['org'=>$org1,'id'=>$a]);
    $check(hash_equals($digest,$stored),'Staging digest was not persisted.');
} finally {
    foreach([$org1,$org2] as $org) {
        $db->executeStatement('DELETE FROM cos_property_bulk_identity_claims WHERE organization_id=:org',['org'=>$org]);
        $db->executeStatement('DELETE FROM cos_property_bulk_items WHERE organization_id=:org',['org'=>$org]);
        $db->executeStatement('DELETE FROM cos_property_bulk_batches WHERE organization_id=:org',['org'=>$org]);
    }
}
echo "Property bulk real MySQL tenant/duplicate fencing PASS.\n";
