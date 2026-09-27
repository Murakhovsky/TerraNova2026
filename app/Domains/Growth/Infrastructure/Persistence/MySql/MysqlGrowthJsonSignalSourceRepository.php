<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthJsonSignalSourceRepositoryInterface;
use Domains\Growth\Domain\GrowthJsonSignalSource;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthJsonSignalSourceRepository implements GrowthJsonSignalSourceRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function create(GrowthJsonSignalSource $source,int $actorId):void
    {
        $this->execute(
            'INSERT INTO tn_growth_json_signal_sources
             (organization_id,source_id,name,url,url_hash,auth_mode,credential_reference,api_key_header,
              subject_type,subject_id,signal_type,confidence,enabled,created_by,updated_by)
             VALUES(:organization_id,:source_id,:name,:url,:url_hash,:auth_mode,:credential_reference,:api_key_header,
                    :subject_type,:subject_id,:signal_type,:confidence,:enabled,:created_by,:updated_by)',
            [
                'organization_id'=>$source->organizationId->value(),'source_id'=>$source->id,'name'=>$source->name,
                'url'=>$source->url,'url_hash'=>hash('sha256',$source->url),'auth_mode'=>$source->authMode,
                'credential_reference'=>$source->credentialReference,'api_key_header'=>$source->apiKeyHeader,
                'subject_type'=>$source->subjectType,'subject_id'=>$source->subjectId,'signal_type'=>$source->signalType,
                'confidence'=>$source->confidence,'enabled'=>$source->enabled()?1:0,'created_by'=>$actorId,'updated_by'=>$actorId,
            ],
        );
    }

    public function lock(string $organizationId,string $sourceId):GrowthJsonSignalSource
    {
        $row=$this->one(
            'SELECT organization_id,source_id,name,url,auth_mode,credential_reference,api_key_header,
                    subject_type,subject_id,signal_type,confidence,enabled
             FROM tn_growth_json_signal_sources
             WHERE organization_id=:organization_id AND source_id=:source_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'source_id'=>$sourceId],
        );
        if($row===null)throw new InvalidArgumentException('Growth JSON signal source was not found.');
        return $this->hydrate($row);
    }

    public function update(GrowthJsonSignalSource $source,int $actorId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_json_signal_sources
             SET enabled=:enabled,updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND source_id=:source_id'
        );
        $statement->execute([
            'enabled'=>$source->enabled()?1:0,'updated_by'=>$actorId,
            'organization_id'=>$source->organizationId->value(),'source_id'=>$source->id,
        ]);
        if($statement->rowCount()>1)throw new InvalidArgumentException('Growth JSON signal source update changed too many rows.');
    }

    public function view(string $organizationId,string $sourceId):?array
    {
        return $this->row(
            'SELECT organization_id,source_id,name,url,auth_mode,credential_reference,api_key_header,
                    subject_type,subject_id,signal_type,confidence,enabled,created_by,updated_by,created_at,updated_at
             FROM tn_growth_json_signal_sources
             WHERE organization_id=:organization_id AND source_id=:source_id LIMIT 1',
            ['organization_id'=>$organizationId,'source_id'=>$sourceId],
        );
    }

    public function listAll(string $organizationId,int $limit=200):array
    {
        return $this->list($organizationId,false,$limit);
    }

    public function listEnabled(string $organizationId,int $limit=200):array
    {
        return $this->list($organizationId,true,$limit);
    }

    /** @return list<array<string,mixed>> */
    private function list(string $organizationId,bool $enabledOnly,int $limit):array
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Growth JSON signal source list limit is invalid.');
        $sql='SELECT organization_id,source_id,name,url,auth_mode,credential_reference,api_key_header,
                    subject_type,subject_id,signal_type,confidence,enabled,created_by,updated_by,created_at,updated_at
              FROM tn_growth_json_signal_sources
              WHERE organization_id=:organization_id'
            .($enabledOnly?' AND enabled=1':'')
            .' ORDER BY source_id LIMIT '.$limit;
        $statement=$this->connection->prepare($sql);
        $statement->execute(['organization_id'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        return array_values(array_map(fn(array $row):array=>$this->normalize($row),$rows));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):GrowthJsonSignalSource
    {
        return new GrowthJsonSignalSource(
            (string)$row['source_id'],OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['name'],(string)$row['url'],(string)$row['auth_mode'],(string)$row['credential_reference'],
            $row['api_key_header']===null?null:(string)$row['api_key_header'],
            (string)$row['subject_type'],(string)$row['subject_id'],(string)$row['signal_type'],
            (float)$row['confidence'],(bool)$row['enabled'],
        );
    }

    /** @return array<string,mixed>|null */
    private function row(string $sql,array $params):?array
    {
        $row=$this->one($sql,$params);
        return $row===null?null:$this->normalize($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row):array
    {
        $row['confidence']=(float)$row['confidence'];
        $row['enabled']=(bool)$row['enabled'];
        foreach(['created_by','updated_by'] as $field){
            if(array_key_exists($field,$row))$row[$field]=(int)$row[$field];
        }
        return $row;
    }

    private function execute(string $sql,array $params):void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }
}
