<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

/**
 * Generates high-performance normalizer classes for objects and value objects.
 */
class CodeGeneratorBuilderRegistry
{
    private readonly TypeResolver $typeResolver;

    /** @var CodeGeneratorBuilder[] */
    private array $builders = [];

    /**
     * @param array<class-string, true> $runtimeNormalizers
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
        if (!$type instanceof Type) {
            $type = $this->typeResolver->resolve($type);
        }
        foreach ($this->builders as $builder) {
            if ($builder->supports($type, $context)) {
                return $builder->build($type, $this, $context);
            }
        }

        throw new \LogicException('No builder found for type ' . $type::class);
    }
}
