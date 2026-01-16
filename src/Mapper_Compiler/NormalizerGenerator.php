<?php

namespace Ultra\Mapper_Compiler;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\NormalizerInterface;
use Ultra\Mapper\Path;
use Ultra\Mapper\UltraNormalizer;

/**
 * Generates high-performance normalizer classes for objects and value objects.
 */
class NormalizerGenerator
{
    /**
     * @param array<class-string, mixed> $availableRuntimeNormalizer
     */
    public static function for(string $originClassname, string $normalizerClass, array $availableRuntimeNormalizer): FileGenerator
    {
        $codeGen = new CodeGeneratorBuilderRegistry($availableRuntimeNormalizer)
            ->getCodeGenerator(Type::object($originClassname), CodeGeneratorContext::new(inline: true, typeCheck: true))
        ;

        return self::generate($codeGen, $normalizerClass);
    }

    /**
     * Generate a normalizer class for the given target class.
     *
     * @param string $normalizerClass The FQCN of the generated normalizer
     */
    private static function generate(CodeGenerator $codeGen, string $normalizerClass): FileGenerator
    {
        $toScalarBody = self::encloseInReturnStatement($codeGen->generateNormalization('$value'));
        $fromScalarBody = $codeGen->generateDenormalization('$value', $bodyBuilder = new MethodBodyBuilder(), Path::new());
        $bodyBuilder->setReturn($fromScalarBody);
        $classGen = self::createNormalizerClass(
            $normalizerClass,
            $toScalarBody,
            $bodyBuilder->getCode(),
        );
        $fileGen = new FileGenerator();
        $fileGen->setClass($classGen);

        return $fileGen;
    }

    /**
     * Create a normalizer class with the given methods and configuration.
     */
    private static function createNormalizerClass(
        string $normalizerClass,
        string $toScalarBody,
        string $fromScalarBody,
    ): ClassGenerator {
        $toScalar = new MethodGenerator(
            'toScalar',
            [
                ['name' => 'value', 'type' => 'mixed'],
                ['name' => 'normalizer', 'type' => UltraNormalizer::class],
            ],
            MethodGenerator::FLAG_PUBLIC,
            $toScalarBody
        )->setReturnType('array|string|int|float|bool|null');

        $fromScalar = new MethodGenerator(
            'fromScalar',
            [
                ['name' => 'value', 'type' => 'array|string|int|float|bool|null'],
                ['name' => 'type', 'type' => 'string'],
                ['name' => 'normalizer', 'type' => UltraNormalizer::class],
                ['name' => 'context', 'type' => DenormalizationContext::class],
            ],
            MethodGenerator::FLAG_PUBLIC,
            $fromScalarBody,
        )->setReturnType('object');

        return new ClassGenerator(
            $normalizerClass,
            null,
            null,
            null,
            [NormalizerInterface::class],
            [],
            [$toScalar, $fromScalar]
        );
    }

    private static function encloseInReturnStatement(string $code): string
    {
        return "return {$code};";
    }
}
