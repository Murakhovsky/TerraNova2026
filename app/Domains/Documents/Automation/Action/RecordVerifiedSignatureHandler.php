<?php
declare(strict_types=1);

namespace Domains\Documents\Automation\Action;

use App\Persistence\Federation\FederationDocumentSignatureProofInbox;
use App\Persistence\Federation\FederationOutcomeOriginRecorder;
use Doctrine\DBAL\Connection;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Platform\Documents\Contract\DocumentsRepositoryInterface;
use Platform\Documents\Service\DocumentsRuntimeService;
use Throwable;

/**
 * Records a provider-verified human signature; does NOT sign as the user.
 * Proof was cryptographically attested after the Federation Run began, bound
 * to current document version + content SHA and consumed exactly once.
 */
final readonly class RecordVerifiedSignatureHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'documents.signature.sign';

    public function __construct(
        private Connection $db,
        private DocumentsRepositoryInterface $documents,
        private DocumentsRuntimeService $runtime,
        private FederationDocumentSignatureProofInbox $proofs,
        private FederationOutcomeOriginRecorder $origins,
    ) {}

    public function supports(string $actionType): bool { return $actionType === self::TYPE; }

    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        $proofId = $action->parameters['proof_id'] ?? null;
        if ($action->targetType !== 'document_signature'
            || !is_string($action->targetId) || $action->targetId === ''
            || !is_string($proofId) || !preg_match('/^[a-zA-Z0-9:_-]{8,191}$/D', $proofId)) {
            return ExecutionResult::failure('Verified signing Action requires a signature target and fixed external proof reference.');
        }
        try {
            return $this->db->transactional(function () use ($action,$proofId): ExecutionResult {
                $verified = $this->proofs->forRunningAction($action,$proofId);
                $org = $action->organizationId;
                $request = $this->documents->findSignature($org,$action->targetId);
                if (!$request || $request['status'] !== 'requested'
                    || $request['document_id'] !== $verified['document_id']
                    || $request['signer_id'] !== $verified['signer_id']
                    || !is_numeric($request['requested_by'])
                    || (int)$request['requested_by'] <= 0) {
                    throw new DomainException('Signature request no longer matches provider-verified signer evidence.');
                }
                $document = $this->documents->view($org,(string)$request['document_id']);
                if (!$document || $document['status'] === 'archived'
                    || $document['current_version_id'] !== $verified['document_version_id']
                    || $document['current_sha256'] !== $verified['file_sha256']) {
                    throw new DomainException('Signed document version or file content changed since provider verification.');
                }
                // Preserve all existing Documents audit/event/idempotency
                // behavior. requested_by is the COS record-keeper actor;
                // signed_by represents the independently attested signer.
                $signed = $this->runtime->sign(
                    $org,(int)$request['requested_by'],$action->id,
                    $action->targetId,(string)$verified['signer_id'],
                    (string)$verified['signature_reference'],
                    $this->idempotencyKey($action),
                );
                if (($signed['status'] ?? null) !== 'signed'
                    || ($signed['replayed'] ?? false) === true) {
                    throw new DomainException('Provider evidence did not produce a new canonical signed record.');
                }
                $this->proofs->consume($action,$proofId);
                $this->origins->record($action,'documents',$action->targetId);
                return ExecutionResult::success([
                    'signature_id'=>$action->targetId,
                    'status'=>'signed',
                    'attestation_verified'=>true,
                    'proof_id'=>$proofId,
                ]);
            });
        } catch (Throwable $failure) {
            return ExecutionResult::failure('Verified Documents signature Action rejected: '.$failure->getMessage());
        }
    }
}
