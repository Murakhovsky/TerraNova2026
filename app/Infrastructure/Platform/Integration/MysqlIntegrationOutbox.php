<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Integration;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use InvalidArgumentException;
use Platform\Integration\Contract\IntegrationOutboxInterface;

final readonly class MysqlIntegrationOutbox implements IntegrationOutboxInterface
{
    public function __construct(private PdoConnection $database) {}

    public function enqueue(
        string $integration,
        string $eventType,
        string $entityType,
        ?int $entityId,
        array $payload,
        string $dedupeKey,
    ):string {
        $integration=$this->bounded($integration,'integration',60);
        $eventType=$this->bounded($eventType,'eventType',100);
        $entityType=$this->bounded($entityType,'entityType',60);
        $dedupeKey=$this->bounded($dedupeKey,'dedupeKey',190);
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $statement=$this->database->connection()->prepare(
            'INSERT INTO tn_integration_outbox (integration,event_type,entity_type,entity_id,payload,dedupe_key) '
            .'VALUES (:integration,:event_type,:entity_type,:entity_id,:payload,:dedupe_key) '
            .'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $statement->execute([
            'integration'=>$integration,
            'event_type'=>$eventType,
            'entity_type'=>$entityType,
            'entity_id'=>$entityId,
            'payload'=>$encoded,
            'dedupe_key'=>$dedupeKey,
        ]);

        $id=(int)$this->database->connection()->lastInsertId();
        if($id<1){
            throw new InvalidArgumentException('Integration outbox enqueue did not return a durable row id.');
        }
        return 'tn-outbox-'.$id;
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit){
            throw new InvalidArgumentException($field.' is invalid.');
        }
        return $value;
    }
}
