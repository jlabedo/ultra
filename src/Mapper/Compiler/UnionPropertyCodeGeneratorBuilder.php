<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ArrayShapeType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Path;

class UnionPropertyCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return $type instanceof UnionType;
    }

    public function build(Type $type, CodeGeneratorBuilderRegistry $registry, CodeGeneratorContext $context): CodeGenerator
    {
        if (!$type instanceof UnionType) {
            throw new \InvalidArgumentException('Type must be UnionType');
        }
        $types = $type->getTypes();
        $typeCodeGens = [];
        foreach ($types as $subType) {
            $typeCodeGens[] = [
                'type' => $subType,
                'codegen' => $registry->getCodeGenerator($subType, $context),
            ];
        }

        return new class($typeCodeGens) implements CodeGenerator {
            /** @var array<array{type: Type, codegen: CodeGenerator}> */
            private array $typeCodeGens;

            public function __construct(array $typeCodeGens)
            {
                $this->typeCodeGens = $typeCodeGens;
            }

            public function generateNormalization(string $objectVar): string
            {
                $cases = [];
                $hasBuiltinType = false;
                foreach ($this->typeCodeGens as $entry) {
                    $subType = $entry['type'];
                    $codegen = $entry['codegen'];
                    if ($subType instanceof ObjectType) {
                        $className = $subType->getClassName();
                        $cases[] = "    {$objectVar} instanceof \\{$className} => {$codegen->generateNormalization($objectVar)}";
                    } elseif ($subType instanceof BuiltinType) {
                        $hasBuiltinType = true;
                    } else {
                        throw new \RuntimeException('Not implemented: '.$subType::class);
                    }
                }
                if ($hasBuiltinType) {
                    $cases[] = "    default => {$objectVar}";
                } else {
                    $cases[] = "    default => throw new \\InvalidArgumentException('Value does not match any type in union')";
                }

                return "match (true) {\n".implode(",\n", $cases)."\n}";
            }

            public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
            {
                $denormalizationException = DenormalizationException::class;
                $cases = [];
                foreach ($this->typeCodeGens as $entry) {
                    $type = $entry['codegen']->getExpectedDenormalizedType();
                    $codegen = $entry['codegen'];
                    $cond = $this->getDiscriminatorCondition($dataVar, $type);
                    $cases[] = [
                        'cond' => $cond,
                        'code' => $codegen->generateDenormalization($dataVar, $bodyBuilder, $path),
                    ];
                }
                $lines = [];
                $lines[] = 'match (true) {';
                foreach ($cases as $case) {
                    $lines[] = "    {$case['cond']} => {$case['code']},";
                }
                $lines[] = "    default => throw new \\{$denormalizationException}('Data does not match any type in union at path ' . {$path->generateRuntimeContextCall()}),";
                $lines[] = '}';

                return implode("\n", $lines);
            }

            private function getDiscriminatorCondition(string $var, Type $type): string
            {
                // Null
                if ($type instanceof BuiltinType && 'null' === $type->getTypeIdentifier()->value) {
                    return "{$var} === null";
                }
                // Int
                if ($type instanceof BuiltinType && 'int' === $type->getTypeIdentifier()->value) {
                    return "is_int({$var})";
                }
                // String
                if ($type instanceof BuiltinType && 'string' === $type->getTypeIdentifier()->value) {
                    return "is_string({$var})";
                }
                // Float
                if ($type instanceof BuiltinType && 'float' === $type->getTypeIdentifier()->value) {
                    return "is_float({$var})";
                }
                // Bool
                if ($type instanceof BuiltinType && 'bool' === $type->getTypeIdentifier()->value) {
                    return "is_bool({$var})";
                }
                // Array shape (object)
                if ($type instanceof ArrayShapeType) {
                    $shape = $type->getShape();
                    if (!empty($shape)) {
                        $isset = implode(' && ', array_map(fn ($k) => "isset({$var}[{$k}])", array_keys($shape)));

                        return "is_array({$var}) && {$isset}";
                    }

                    return "is_array({$var})";
                }
                // Array (collection)
                if ($type instanceof CollectionType) {
                    return "is_array({$var})";
                }

                // Fallback
                return 'true';
            }

            public function getExpectedDenormalizedType(): Type
            {
                $types = array_map(fn ($entry) => $entry['codegen']->getExpectedDenormalizedType(), $this->typeCodeGens);

                return Type::union(...$types);
            }
        };
    }
}
