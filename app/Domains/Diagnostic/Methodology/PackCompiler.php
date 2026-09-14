<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Methodology;
use Domains\Diagnostic\Methodology\Loader\PackLoader;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
use Domains\Diagnostic\Methodology\Validation\PackValidator;
use InvalidArgumentException;
final class PackCompiler
{
    public function __construct(private readonly PackLoader $loader=new PackLoader(),private readonly PackValidator $validator=new PackValidator()){}
    /** @param array<string,mixed>|string|MethodologyPack $source */
    public function compile(array|string|MethodologyPack $source): CompiledDiagnosticPack
    {
        $pack=$source instanceof MethodologyPack?$source:$this->loader->load($source); $validation=$this->validator->validate($pack);
        if(!$validation->isValid()) throw new InvalidArgumentException('Diagnostic pack compilation failed: '.implode('; ',array_map(fn($i)=>$i->code.' at '.$i->path,$validation->errors)));
        return new CompiledDiagnosticPack($pack);
    }
}
