<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Path;

class CollectionCodeGenerator implements CodeGenerator
{
    public function __construct(private CodeGenerator $next) {}

    public function generateNormalization(string $objectVar): string
    {
        return 'array_map(fn($v) => '.$this->next->generateNormalization('$v').", {$objectVar})";
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        $collectorVar = $bodyBuilder->newVariable();
        $keyVar = $bodyBuilder->newVariable();
        $valueVar = $bodyBuilder->newVariable();
        $internalBodyBuilder = $bodyBuilder->newScope();
        $next = $this->next->generateDenormalization($valueVar, $internalBodyBuilder, $path->addVariable($keyVar));
        $bodyBuilder->addStatement(<<<PHP
            {$collectorVar} = [];
            foreach ({$dataVar} as {$keyVar} => {$valueVar}) {
                {$internalBodyBuilder->getCode()}
                {$collectorVar}[{$keyVar}] = {$next};
            }
            PHP);

        return $collectorVar;
    }

    public function getExpectedDenormalizedType(): Type
    {
        return Type::array($this->next->getExpectedDenormalizedType());
    }
}
