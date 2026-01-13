<?php

namespace Ultra\Mapper\CodeGenerator;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Path;

readonly class IdentityCodeGenerator implements CodeGenerator
{
    public function __construct(private Type $type, private bool $typeCheck) {}

    public function generateNormalization(string $objectVar): string
    {
        return $objectVar;
    }

    public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
    {
        if ($this->typeCheck && $this->type instanceof BuiltinType) {
            $typeName = $this->type->getTypeIdentifier()->value;
            $typeCheck = '';
            $expected = '';
            if ('int' === $typeName) {
                $typeCheck = "is_int({$dataVar})";
                $expected = 'int';
            } elseif ('string' === $typeName) {
                $typeCheck = "is_string({$dataVar})";
                $expected = 'string';
            } elseif ('float' === $typeName) {
                $typeCheck = "is_float({$dataVar})";
                $expected = 'float';
            } elseif ('bool' === $typeName) {
                $typeCheck = "is_bool({$dataVar})";
                $expected = 'bool';
            }
            $denormalizationException = DenormalizationException::class;
            if ($typeCheck) {
                return <<<PHP
                    ({$typeCheck}
                        ? {$dataVar}
                        : throw new \\{$denormalizationException}(
                            "Type mismatch at path {{$path->generateRuntimeContextCall()}}: expected {$expected}, got " . gettype({$dataVar})
                        )
                    )
                    PHP;
            }
        }

        return $dataVar;
    }

    public function getExpectedDenormalizedType(): Type
    {
        return $this->type;
    }
}
