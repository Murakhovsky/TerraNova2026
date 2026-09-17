<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use InvalidArgumentException;

final readonly class RetrievalResult
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $chunkId,
        public string $documentId,
        public string $sourceId,
        public string $content,
        public float $score,
        public int $tokenCount,
        public array $metadata = [],
    ) {
        if (trim($this->chunkId) === '' || trim($this->documentId) === '' || trim($this->sourceId) === '' || trim($this->content) === '') {
            throw new InvalidArgumentException('Retrieval result must be traceable to source/document/chunk.');
        }
        if ($this->score < 0.0 || $this->score > 1.0 || $this->tokenCount < 1) {
            throw new InvalidArgumentException('Retrieval result score or token count is invalid.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'source_id' => $this->sourceId,
            'content' => $this->content,
            'score' => $this->score,
            'token_count' => $this->tokenCount,
            'metadata' => $this->metadata,
        ];
    }
}
