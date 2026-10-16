<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Documents\Application\Service\VerifiedSignatureAttestationVerifier;
use Kernel\Action\Action;
use Platform\Documents\Contract\DocumentsRepositoryInterface;

/**
 * Private-only verified provider evidence inbox. No public write route exists.
 * Deployment requires an authenticated provider callback + pinned key; absent
 * those, proof ingestion is impossible and signing Action fails closed.
 */
final readonly class FederationDocumentSignatureProofInbox
{
    public function __construct(
        private Connection $db,
        private DocumentsRepositoryInterface $documents,
        private VerifiedSignatureAttestationVerifier $verifier,
        private string $trustedPublicKeyPem,
        private string $trustedIssuer,
    ) {}

    /** @return array<string,string> */
    public function ingest(
        string $org, string $runId, string $signatureId, string $proofId, string $envelopeJson,
    ): array {
        if (trim($this->trustedPublicKeyPem) === '' || trim($this->trustedIssuer) === '') {
            throw new DomainException('Trusted external document signing provider not configured.');
        }
        return $this->db->transactional(function () use ($org,$runId,$signatureId,$proofId,$envelopeJson): array {
            $run = $this->db->fetchAssociative(
                "SELECT state, created_at FROM cos_federation_runs
                 WHERE organization_id=:org AND run_id=:run FOR UPDATE",
                ['org'=>$org,'run'=>$runId],
            );
            if (!$run || !in_array($run['state'], ['running','waiting'], true)) {
                throw new DomainException('Signature evidence must originate during an active tenant-owned Federation Run.');
            }
            $request = $this->documents->findSignature($org,$signatureId);
            if (!$request || $request['status'] !== 'requested') {
                throw new DomainException('Provider signature proof requires a pending tenant-owned signature request.');
            }
            $docId = (string) $request['document_id'];
            $document = $this->documents->view($org,$docId);
            if (!$document || $document['status'] === 'archived'
                || !is_string($document['current_version_id'] ?? null)
                || !is_string($document['current_sha256'] ?? null)) {
                throw new DomainException('Signing proof requires a current, unarchived tenant-owned document version.');
            }
            $evidence = $this->verifier->verify(
                $envelopeJson,$this->trustedPublicKeyPem,$this->trustedIssuer,
                $org,$docId,$signatureId,(string)$request['signer_id'],
                (string)$document['current_version_id'],(string)$document['current_sha256'],
                null,$runId,$proofId,
            );
            if ($evidence['signed_at'] < (string)$run['created_at']
                || $evidence['signed_at'] < (string)$request['requested_at']) {
                throw new DomainException('External signing event predates the Federation Run or signature request.');
            }
            $this->db->insert('cos_federation_document_signature_proofs', [
                'organization_id'=>$org, 'signature_id'=>$signatureId,
                'proof_id'=>$evidence['proof_id'], 'run_id'=>$runId,
                'document_id'=>$docId, 'signer_id'=>$evidence['signer_id'],
                'document_version_id'=>$evidence['document_version_id'],
                'file_sha256'=>$evidence['file_sha256'], 'issuer'=>$evidence['issuer'],
                'signature_reference'=>$evidence['signature_reference'],
                'proof_payload_sha256'=>$evidence['payload_sha256'],
                'signed_at_provider'=>$evidence['signed_at'],
                'verified_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
            return $evidence;
        });
    }

    /** @return array<string,mixed> */
    public function forRunningAction(Action $action, string $proofId): array
    {
        if (!$this->db->isTransactionActive() || $proofId === ''
            || $action->type !== 'documents.signature.sign'
            || $action->targetType !== 'document_signature'
            || $action->targetId === null) {
            throw new DomainException('Verified signing requires a running Document Signature Action transaction.');
        }
        $step = $this->db->fetchAssociative(
            "SELECT s.run_id, r.created_at FROM cos_federation_steps s
             INNER JOIN cos_federation_runs r ON r.organization_id=s.organization_id
               AND r.run_id=s.run_id
             WHERE s.organization_id=:org AND s.idempotency_key=:key
               AND s.state='claimed' AND r.state IN ('running','waiting')",
            ['org'=>$action->organizationId,'key'=>substr((string)$action->idempotencyKey,4)],
        );
        if (!$step) throw new DomainException('No active Federation Step owns the signing Action.');
        $row = $this->db->fetchAssociative(
            'SELECT * FROM cos_federation_document_signature_proofs
             WHERE organization_id=:org AND signature_id=:signature AND proof_id=:proof
               AND run_id=:run AND consumed_action_id IS NULL FOR UPDATE',
            ['org'=>$action->organizationId,'signature'=>$action->targetId,
             'proof'=>$proofId,'run'=>$step['run_id']],
        );
        if (!$row || $row['signed_at_provider'] < (string)$step['created_at']) {
            throw new DomainException('No independently verified, unconsumed signer proof for this Run.');
        }
        return $row;
    }

    public function consume(Action $action, string $proofId): void
    {
        if (!$this->db->isTransactionActive() || $action->targetId === null) {
            throw new DomainException('Provider proof can only be consumed atomically.');
        }
        $updated = $this->db->executeStatement(
            'UPDATE cos_federation_document_signature_proofs
             SET consumed_action_id=:action WHERE organization_id=:org
               AND signature_id=:signature AND proof_id=:proof
               AND consumed_action_id IS NULL',
            ['action'=>$action->id,'org'=>$action->organizationId,
             'signature'=>$action->targetId,'proof'=>$proofId],
        );
        if ($updated !== 1) throw new DomainException('Signing evidence was already consumed or moved.');
    }
}
