<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ArrayShapeType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Ultra\CodeGeneration\PhpCode;
use Ultra\Mapper\Exception\DenormalizationException;
use Ultra\Mapper\Path;

class UnionPropertyCodeGeneratorBuilder implements CodeGeneratorBuilder
{
    public function supports(Type $type, CodeGeneratorContext $context): bool
    {
        return $type instanceof UnionType;
    }

    public function build(
        Type $type,
        CodeGeneratorBuilderRegistry $registry,
        CodeGeneratorContext $context,
    ): CodeGenerator {
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

        $wrappedType = $type instanceof NullableType ? $type->getWrappedType() : null;
        $isNullableArray =
            $wrappedType instanceof CollectionType
            && !$wrappedType instanceof ArrayShapeType
            && $wrappedType->isIdentifiedBy(TypeIdentifier::ARRAY);

        return new class($typeCodeGens, $isNullableArray) implements CodeGenerator {
            /** @var array<array{type: Type, codegen: CodeGenerator}> */
            private array $typeCodeGens;

            /** @param array<array{type: Type, codegen: CodeGenerator}> $typeCodeGens */
            public function __construct(
                array $typeCodeGens,
                private bool $isNullableArray,
            ) {
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
                        $cases[] = "    {$objectVar} instanceof \\{$className} => {$codegen->generateNormalization(
     $objectVar,
 )}";
                        continue;
                    }
                    if ($subType instanceof CollectionType && $this->isNullableArray) {
                        $cases[] = "    is_array({$objectVar}) => {$codegen->generateNormalization($objectVar)}";
                        continue;
                    }
                    if ($subType instanceof BuiltinType) {
                        $hasBuiltinType = true;
                        continue;
                    }
                    throw new \RuntimeException('Not implemented: ' . $subType::class);
                }
                $cases[] = $hasBuiltinType
                    ? "    default => {$objectVar}"
                    : "    default => throw new \\InvalidArgumentException('Value does not match any type in union')";

                return "match (true) {\n" . implode(",\n", $cases) . "\n}";
            }

            public function generateDenormalization(string $dataVar, MethodBodyBuilder $bodyBuilder, Path $path): string
            {
                $result = $bodyBuilder->newVariable();
                $branches = [];
                $keyword = 'if';
                foreach ($this->typeCodeGens as $entry) {
                    $codegen = $entry['codegen'];
                    $condition = $this->getDiscriminatorCondition($dataVar, $codegen->getExpectedDenormalizedType());
                    $branchBody = $bodyBuilder->newScope();
                    $value = $codegen->generateDenormalization($dataVar, $branchBody, $path);
                    $branchBody->addStatement("{$result} = {$value}");
                    $code = PhpCode::from($branchBody->getCode())->indent();
                    $branches[] = "{$keyword} ({$condition}) {\n{$code}\n}";
                    $keyword = 'elseif';
                }
                $denormalizationException = DenormalizationException::class;
                $branches[] = <<<PHP
                    else {
                        throw new \\{$denormalizationException}('Data does not match any type in union at path ' . {$path->generateRuntimeContextCall()});
                    }
                    PHP;
                $bodyBuilder->addStatement(implode(' ', $branches));

                return $result;
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
                    $conditions = ["is_array({$var})"];
                    foreach ($type->getShape() as $key => $field) {
                        if ($field['optional']) {
                            continue;
                        }
                        $literal = PhpCode::exportValue($key);
                        $conditions[] = "\\array_key_exists({$literal}, {$var})";
                    }

                    return implode(' && ', $conditions);
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
                $types = array_map(
                    static fn($entry) => $entry['codegen']->getExpectedDenormalizedType(),
                    $this->typeCodeGens,
                );

                return Type::union(...$types);
            }
        };
    }
}
