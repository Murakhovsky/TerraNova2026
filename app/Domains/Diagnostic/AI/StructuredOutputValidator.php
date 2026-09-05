<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;
use UnexpectedValueException;

final class StructuredOutputValidator
{
    public function validate(array $output, array $schema): array
    {
        foreach(($schema['required']??[]) as $field) if(!array_key_exists($field,$output)) throw new UnexpectedValueException('AI schema missing field: '.$field);
        foreach(($schema['properties']??[]) as $field=>$definition) if(array_key_exists($field,$output)) {
            $expected=$definition['type']??null; $actual=$this->type($output[$field]);
            if($expected!==null && $actual!==$expected) throw new UnexpectedValueException(sprintf('AI schema field %s expects %s, got %s.',$field,$expected,$actual));
        }
        return $output;
    }
    private function type(mixed $value): string { return match(true){is_array($value)=>array_is_list($value)?'array':'object',is_string($value)=>'string',is_int($value),is_float($value)=>'number',is_bool($value)=>'boolean',$value===null=>'null',default=>'unknown'}; }
}
