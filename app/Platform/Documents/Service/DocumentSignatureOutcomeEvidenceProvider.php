<?php
declare(strict_types=1);

namespace Platform\Documents\Service;

use DateTimeImmutable;
use DomainException;
use Platform\Documents\Contract\DocumentSignatureOutcomeReadModelInterface;
use Platform\Orchestration\Goal\GoalOutcomeEvidenceProviderInterface;

/** Read-only, verifiable Domain business fact; never an Action completion counter. */
final readonly class DocumentSignatureOutcomeEvidenceProvider implements GoalOutcomeEvidenceProviderInterface
{
    public const CRITERION = 'documents.signatures_recorded';

    public function __construct(private DocumentSignatureOutcomeReadModelInterface $readModel) {}

    public function domain(): string
    {
        return 'platform.documents';
    }

    public function supports(string $criterionId): bool
    {
        return $criterionId === self::CRITERION;
    }

    public function observe(string $organizationId, string $criterionId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$this->supports($criterionId) || $organizationId === '' || $to <= $from) {
            throw new DomainException('Unsupported trusted evidence metric or scope.');
        }
        $count = $this->readModel->count($organizationId, $from, $to);
        if ($count < 0) {
            throw new DomainException('Negative trusted Domain metric.');
        }
        $start = $from->format('Y-m-d\TH:i:s.uP');
        $end = $to->format('Y-m-d\TH:i:s.uP');
        $hash = hash('sha256', implode("\0", [
            'documents.signatures_recorded.v1', $organizationId, $criterionId, $start, $end, (string) $count,
        ]));
        return [
            'value' => $count,
            'evidence' => ['documents:recorded_signatures:v1:' . $hash],
            'source' => 'platform.documents.cos_document_signatures.signed.v1',
            'window_start' => $start,
            'window_end' => $end,
        ];
    }
}
