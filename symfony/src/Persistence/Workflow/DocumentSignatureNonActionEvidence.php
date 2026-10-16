<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionEvidenceAdapterInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Verifies an actual locally recorded signed document, not signature
 * cryptography, and not an Action or Workflow completion flag.
 */
final readonly class DocumentSignatureNonActionEvidence implements WorkflowNonActionEvidenceAdapterInterface
{
    public function __construct(private Connection $db) {}

    public function kind(): string { return 'documents.signature.signed.v1'; }
    public function ownerDomain(): string { return 'platform.documents'; }

    public function assertVerified(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $reference,
        array $evidence,
    ): void {
        $signature=$evidence['signature_id']??null;
        $document=$evidence['document_id']??null;
        $signer=$evidence['signer_id']??null;
        if(!is_string($signature) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$signature)
            || !is_string($document) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$document)
            || !is_string($signer) || $signer==='' || strlen($signer)>191) {
            throw new DomainException('Pinned document signature scope is invalid.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT s.status,s.signature_reference,s.signer_id,s.signed_by,s.signed_by_actor_id,
                    s.signed_at,d.status AS document_status,r.created_at AS run_started,
                    st.updated_at AS step_completed
             FROM cos_document_signatures s
             INNER JOIN cos_documents d
               ON d.organization_id=s.organization_id AND d.document_id=s.document_id
             INNER JOIN cos_workflow_runs r
               ON r.organization_id=s.organization_id
             INNER JOIN cos_workflow_steps st
               ON st.organization_id=r.organization_id AND st.run_id=r.run_id
             WHERE s.organization_id=:org AND s.signature_id=:signature
               AND s.document_id=:document AND r.run_id=:run AND st.step_id=:step',
            ['org'=>$identity->organizationId,'signature'=>$signature,'document'=>$document,
             'run'=>$identity->runId,'step'=>$stepId],
        );
        $signatureRef=$row['signature_reference']??null;
        $expected=is_string($signatureRef)&&$signatureRef!==''
            ? 'documents:signature:signed:v1:'.$signature.':'.hash('sha256',$signatureRef)
            : null;
        if(!$row || $row['status']!=='signed' || $row['document_status']!=='active'
            || $row['signer_id']!==$signer
            || !is_string($row['signed_by']) || $row['signed_by']===''
            || (int)($row['signed_by_actor_id']??0)<1
            || $row['signed_at']===null
            || $expected===null || !hash_equals($expected,$reference)
            || $row['signed_at'] < $row['run_started']
            || $row['signed_at'] > $row['step_completed']) {
            throw new DomainException('Documents source cannot attest a fresh, completed signed signature.');
        }
    }
}
