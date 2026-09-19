<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Documents;

use PDO;
use Platform\Documents\Contract\DocumentsRepositoryInterface;

final readonly class MysqlDocumentsRepository implements DocumentsRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createDocument(array $document, array $file, array $version): void
    {
        $this->execute(
            'INSERT INTO cos_documents
             (organization_id,document_id,title,status,current_version_id,created_by,updated_by)
             VALUES(:organization_id,:document_id,:title,:status,NULL,:created_by,:updated_by)',
            $document,
        );
        $this->insertFile($file);
        $this->insertVersion($version);
        $this->execute(
            'UPDATE cos_documents SET current_version_id=:version_id,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND document_id=:document_id',
            [
                'version_id'=>$version['version_id'],
                'organization_id'=>$document['organization_id'],
                'document_id'=>$document['document_id'],
            ],
        );
    }

    public function createVersion(string $organizationId, string $documentId, array $file, array $version): void
    {
        $this->assertDocumentLocked($organizationId,$documentId);
        $this->insertFile($file);
        $this->insertVersion($version);
        $this->execute(
            'UPDATE cos_documents
             SET current_version_id=:version_id,updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND document_id=:document_id AND status<>\'archived\'',
            [
                'version_id'=>$version['version_id'],
                'updated_by'=>$version['created_by'],
                'organization_id'=>$organizationId,
                'document_id'=>$documentId,
            ],
        );
    }

    public function attach(
        string $organizationId,
        string $relationId,
        string $documentId,
        string $relatedType,
        string $relatedId,
        int $actorId,
    ): bool {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO cos_document_relations
             (organization_id,relation_id,document_id,related_type,related_id,created_by)
             VALUES(:organization_id,:relation_id,:document_id,:related_type,:related_id,:created_by)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'relation_id'=>$relationId,
            'document_id'=>$documentId,
            'related_type'=>$relatedType,
            'related_id'=>$relatedId,
            'created_by'=>$actorId,
        ]);
        return $statement->rowCount()===1;
    }

    public function findRelation(string $organizationId, string $relationId): ?array
    {
        return $this->one(
            'SELECT organization_id,relation_id,document_id,related_type,related_id,created_by,created_at
             FROM cos_document_relations
             WHERE organization_id=:organization_id AND relation_id=:relation_id LIMIT 1',
            ['organization_id'=>$organizationId,'relation_id'=>$relationId],
        );
    }

    public function findTemplate(string $organizationId, string $templateId): ?array
    {
        return $this->one(
            'SELECT organization_id,template_id,name,mime_type,body,filename_pattern,active
             FROM cos_document_templates
             WHERE organization_id=:organization_id AND template_id=:template_id AND active=1 LIMIT 1',
            ['organization_id'=>$organizationId,'template_id'=>$templateId],
        );
    }

    public function createSignatureRequest(
        string $organizationId,
        string $signatureId,
        string $documentId,
        string $signerId,
        int $actorId,
    ): bool {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO cos_document_signatures
             (organization_id,signature_id,document_id,signer_id,status,requested_by,requested_at)
             VALUES(:organization_id,:signature_id,:document_id,:signer_id,\'requested\',:requested_by,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'signature_id'=>$signatureId,
            'document_id'=>$documentId,
            'signer_id'=>$signerId,
            'requested_by'=>$actorId,
        ]);
        return $statement->rowCount()===1;
    }

    public function findSignature(string $organizationId, string $signatureId): ?array
    {
        return $this->one(
            'SELECT organization_id,signature_id,document_id,signer_id,status,requested_by,requested_at,
                    signed_by,signed_by_actor_id,signature_reference,signed_at,updated_at
             FROM cos_document_signatures
             WHERE organization_id=:organization_id AND signature_id=:signature_id LIMIT 1',
            ['organization_id'=>$organizationId,'signature_id'=>$signatureId],
        );
    }

    public function sign(
        string $organizationId,
        string $signatureId,
        string $signedBy,
        string $signatureReference,
        int $actorId,
    ): bool {
        $statement=$this->connection->prepare(
            'UPDATE cos_document_signatures
             SET status=\'signed\',signed_by=:signed_by,signed_by_actor_id=:actor_id,
                 signature_reference=:signature_reference,signed_at=NOW(6),updated_at=NOW(6)
             WHERE organization_id=:organization_id AND signature_id=:signature_id AND status=\'requested\''
        );
        $statement->execute([
            'signed_by'=>$signedBy,
            'actor_id'=>$actorId,
            'signature_reference'=>$signatureReference,
            'organization_id'=>$organizationId,
            'signature_id'=>$signatureId,
        ]);
        return $statement->rowCount()===1;
    }

    public function archive(string $organizationId, string $documentId, int $actorId): bool
    {
        $statement=$this->connection->prepare(
            'UPDATE cos_documents
             SET status=\'archived\',archived_at=NOW(6),updated_by=:actor_id,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND document_id=:document_id AND status<>\'archived\''
        );
        $statement->execute([
            'actor_id'=>$actorId,
            'organization_id'=>$organizationId,
            'document_id'=>$documentId,
        ]);
        return $statement->rowCount()===1;
    }

    public function view(string $organizationId, string $documentId): ?array
    {
        $document=$this->one(
            'SELECT d.organization_id,d.document_id,d.title,d.status,d.current_version_id,d.created_by,
                    d.updated_by,d.archived_at,d.created_at,d.updated_at,
                    v.version_number AS current_version_number,v.source_type AS current_version_source,
                    f.file_id AS current_file_id,f.storage_key AS current_storage_key,f.mime_type AS current_mime_type,
                    f.size_bytes AS current_size_bytes,f.sha256 AS current_sha256
             FROM cos_documents d
             LEFT JOIN cos_document_versions v
               ON v.organization_id=d.organization_id AND v.version_id=d.current_version_id
             LEFT JOIN cos_document_files f
               ON f.organization_id=v.organization_id AND f.file_id=v.file_id
             WHERE d.organization_id=:organization_id AND d.document_id=:document_id LIMIT 1',
            ['organization_id'=>$organizationId,'document_id'=>$documentId],
        );
        if($document===null)return null;

        $document['versions']=$this->all(
            'SELECT v.version_id,v.version_number,v.source_type,v.created_by,v.created_at,
                    f.file_id,f.storage_key,f.mime_type,f.size_bytes,f.sha256
             FROM cos_document_versions v
             INNER JOIN cos_document_files f
               ON f.organization_id=v.organization_id AND f.file_id=v.file_id
             WHERE v.organization_id=:organization_id AND v.document_id=:document_id
             ORDER BY v.version_number DESC',
            ['organization_id'=>$organizationId,'document_id'=>$documentId],
        );
        $document['relations']=$this->all(
            'SELECT relation_id,related_type,related_id,created_by,created_at
             FROM cos_document_relations
             WHERE organization_id=:organization_id AND document_id=:document_id
             ORDER BY created_at,id',
            ['organization_id'=>$organizationId,'document_id'=>$documentId],
        );
        $document['signatures']=$this->all(
            'SELECT signature_id,signer_id,status,requested_by,requested_at,signed_by,signed_by_actor_id,
                    signature_reference,signed_at,updated_at
             FROM cos_document_signatures
             WHERE organization_id=:organization_id AND document_id=:document_id
             ORDER BY requested_at,signature_id',
            ['organization_id'=>$organizationId,'document_id'=>$documentId],
        );
        return $document;
    }

    public function nextVersionNumber(string $organizationId, string $documentId): int
    {
        $this->assertDocumentLocked($organizationId,$documentId);
        $statement=$this->connection->prepare(
            'SELECT COALESCE(MAX(version_number),0)+1
             FROM cos_document_versions
             WHERE organization_id=:organization_id AND document_id=:document_id'
        );
        $statement->execute(['organization_id'=>$organizationId,'document_id'=>$documentId]);
        return max(1,(int)$statement->fetchColumn());
    }

    private function assertDocumentLocked(string $organizationId,string $documentId): void
    {
        $statement=$this->connection->prepare(
            'SELECT status FROM cos_documents
             WHERE organization_id=:organization_id AND document_id=:document_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['organization_id'=>$organizationId,'document_id'=>$documentId]);
        $status=$statement->fetchColumn();
        if($status===false)throw new \InvalidArgumentException('Document was not found.');
        if((string)$status==='archived')throw new \InvalidArgumentException('Archived Document cannot be modified.');
    }

    private function insertFile(array $file): void
    {
        $this->execute(
            'INSERT INTO cos_document_files
             (organization_id,file_id,storage_key,mime_type,size_bytes,sha256,created_by)
             VALUES(:organization_id,:file_id,:storage_key,:mime_type,:size_bytes,:sha256,:created_by)',
            $file,
        );
    }

    private function insertVersion(array $version): void
    {
        $this->execute(
            'INSERT INTO cos_document_versions
             (organization_id,version_id,document_id,version_number,file_id,source_type,created_by)
             VALUES(:organization_id,:version_id,:document_id,:version_number,:file_id,:source_type,:created_by)',
            $version,
        );
    }

    private function execute(string $sql,array $params): void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    /** @return list<array<string,mixed>> */
    private function all(string $sql,array $params): array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
}
