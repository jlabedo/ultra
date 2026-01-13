<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;

class CollectionCodeGenerator implements CodeGenerator
{
    public function __construct(private CodeGenerator $next) {}

    public function generateNormalization(string $objectVar): string
    {
        return 'array_map(fn($v) => '.$this->next->generateNormalization('$v').", {$objectVar})";
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder): string
    {
        return "array_map(fn(\$k, \$v) => {$this->next->generateDenormalization('$v', $bodyBuilder)}, array_keys({$dataVar}), {$dataVar})";
    }

    public function getExpectedDenormalizedType(): Type
    {
        return Type::array($this->next->getExpectedDenormalizedType());
    }
}
