<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Engine;

use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Input\DiagnosticSessionInputFactory;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Methodology\Result\DiagnosticResult;
use Domains\Diagnostic\Model\DiagnosticSession;
use InvalidArgumentException;

final readonly class DiagnosticRunner
{
    public function __construct(
        private MethodologyEngine $engine = new MethodologyEngine(),
        private DiagnosticSessionInputFactory $inputFactory = new DiagnosticSessionInputFactory(),
    ) {
    }

    public function evaluate(DiagnosticInput|DiagnosticSession $source, MethodologyPack $pack): DiagnosticResult
    {
        if ($source instanceof DiagnosticSession) {
            if ($source->packId() !== $pack->id || $source->packVersion() !== $pack->version) {
                throw new InvalidArgumentException('Diagnostic session and methodology pack identity/version do not match.');
            }
            $source = $this->inputFactory->create($source);
        }
        return $this->engine->evaluate($source, $pack);
    }
}
