<?php
declare(strict_types=1);

namespace Platform\Documents\Contract;

interface DocumentsRepositoryInterface
{
    /** @param array<string,mixed> $document @param array<string,mixed> $file @param array<string,mixed> $version */
    public function createDocument(array $document, array $file, array $version): void;

    /** @param array<string,mixed> $file @param array<string,mixed> $version */
    public function createVersion(string $organizationId, string $documentId, array $file, array $version): void;

    public function attach(
        string $organizationId,
        string $relationId,
        string $documentId,
        string $relatedType,
        string $relatedId,
        int $actorId,
    ): bool;

    /** @return array<string,mixed>|null */
    public function findRelation(string $organizationId, string $relationId): ?array;

    /** @return array<string,mixed>|null */
    public function findTemplate(string $organizationId, string $templateId): ?array;

    public function createSignatureRequest(
        string $organizationId,
        string $signatureId,
        string $documentId,
        string $signerId,
        int $actorId,
    ): bool;

    /** @return array<string,mixed>|null */
    public function findSignature(string $organizationId, string $signatureId): ?array;

    public function sign(
        string $organizationId,
        string $signatureId,
        string $signedBy,
        string $signatureReference,
        int $actorId,
    ): bool;

    public function archive(string $organizationId, string $documentId, int $actorId): bool;

    /** @return array<string,mixed>|null */
    public function view(string $organizationId, string $documentId): ?array;

    public function nextVersionNumber(string $organizationId, string $documentId): int;
}
