<?php

namespace Ultra\Mapper;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Ultra\Mapper\CodeGenerator\CodeGenerator;
use Ultra\Mapper\CodeGenerator\MethodBodyBuilder;

/**
 * Generates high-performance normalizer classes for objects and value objects.
 */
class NormalizerGenerator
{
    private const string GENERATED_NAMESPACE = 'Ultra\Normalizer\Generated';

    /**
     * Generate a normalizer class for the given target class.
     *
     * @param string $normalizerClass The FQCN of the generated normalizer
     */
    public static function generate(CodeGenerator $codeGen, string $normalizerClass): FileGenerator
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
            self::GENERATED_NAMESPACE,
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
