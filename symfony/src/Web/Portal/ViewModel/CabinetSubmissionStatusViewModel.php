<?php
declare(strict_types=1);

namespace App\Web\Portal\ViewModel;

final readonly class CabinetSubmissionStatusViewModel
{
    public function __construct(
        public int $submissionId,
        public string $title,
        public string $message,
    ) {}
}
