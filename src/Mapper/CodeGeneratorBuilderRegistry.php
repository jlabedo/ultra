<?php

namespace Ultra\Mapper;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Ultra\Mapper\CodeGenerator\BackedEnumPropertyCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\CodeGenerator;
use Ultra\Mapper\CodeGenerator\CodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\CodeGeneratorContext;
use Ultra\Mapper\CodeGenerator\CollectionCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\DateTimeCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\IdentityCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\NormalizerCallCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\ObjectCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\RuntimeNormalizerCallCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\UnionPropertyCodeGeneratorBuilder;
use Ultra\Mapper\CodeGenerator\ValueObjectPropertyCodeGeneratorBuilder;

/**
 * Generates high-performance normalizer classes for objects and value objects.
 */
class CodeGeneratorBuilderRegistry
{
    private readonly TypeResolver $typeResolver;
    /** @var CodeGeneratorBuilder[] */
    private array $builders = [];

    /**
     * @param array<class-string, RuntimeNormalizerInterface> $runtimeNormalizers
     */
    public function __construct(array $runtimeNormalizers = [])
    {
        $this->typeResolver = TypeResolver::create();
        // Register default builders in order of specificity
        $this->registerBuilder(new RuntimeNormalizerCallCodeGeneratorBuilder($runtimeNormalizers));
        $this->registerBuilder(new DateTimeCodeGeneratorBuilder());
        $this->registerBuilder(new BackedEnumPropertyCodeGeneratorBuilder());
        $this->registerBuilder(new ValueObjectPropertyCodeGeneratorBuilder());
        $this->registerBuilder(new ObjectCodeGeneratorBuilder());
        $this->registerBuilder(new NormalizerCallCodeGeneratorBuilder());
        $this->registerBuilder(new CollectionCodeGeneratorBuilder());
        $this->registerBuilder(new UnionPropertyCodeGeneratorBuilder());
        $this->registerBuilder(new IdentityCodeGeneratorBuilder());
    }

    public function registerBuilder(CodeGeneratorBuilder $builder): void
    {
        $this->builders[] = $builder;
    }

    public function getCodeGenerator(mixed $type, CodeGeneratorContext $context): CodeGenerator
    {
        if (! $type instanceof Type) {
            $type = $this->typeResolver->resolve($type);
        }
        foreach ($this->builders as $builder) {
            if ($builder->supports($type, $context)) {
                return $builder->build($type, $this, $context);
            }
        }

        throw new \LogicException('No builder found for type '.get_class($type));
    }
}
