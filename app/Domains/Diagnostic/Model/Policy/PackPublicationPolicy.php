<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model\Policy;

use DomainException;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Methodology\Validation\PackValidator;

final readonly class PackPublicationPolicy
{
    public function __construct(private PackValidator $validator = new PackValidator())
    {
    }

    public function assertSatisfied(MethodologyPack $methodology): void
    {
        $validation = $this->validator->validate($methodology);
        if (!$validation->isValid()) {
            throw new DomainException('Invalid diagnostic methodology: ' . implode('; ', array_map(
                static fn ($issue): string => $issue->path . ': ' . $issue->message,
                $validation->errors,
            )));
        }
    }
}
