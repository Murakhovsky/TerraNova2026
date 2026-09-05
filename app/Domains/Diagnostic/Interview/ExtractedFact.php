<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use InvalidArgumentException;
final readonly class ExtractedFact { public const PROVENANCE=['OBSERVED','REPORTED','CALCULATED','DERIVED','INFERRED','ESTIMATED','ASSUMED']; public function __construct(public string $key,public mixed $value,public string $valueType,public string $provenance,public float $confidence,public array $evidenceIds=[]){if($key===''||!in_array($provenance,self::PROVENANCE,true)||$confidence<0||$confidence>1)throw new InvalidArgumentException('Invalid extracted fact.');} }
