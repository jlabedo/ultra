<?php

namespace Ultra\Mapper_Compiler;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\Path;

/**
 * Generates high-performance normalizer classes for objects and value objects.
 */
class NormalizerGenerator
{
    /**
     * @param array<class-string, true> $availableRuntimeNormalizer
     */
    public static function for(string $originClassname, string $normalizerClass, array $availableRuntimeNormalizer): GeneratedNormalizerFile
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
    private static function generate(CodeGenerator $codeGen, string $normalizerClass): GeneratedNormalizerFile
    {
        $toScalarBody = self::encloseInReturnStatement($codeGen->generateNormalization('$value'));
        $fromScalarBody = $codeGen->generateDenormalization('$value', $bodyBuilder = new MethodBodyBuilder(), Path::new());
        $bodyBuilder->setReturn($fromScalarBody);

        return new GeneratedNormalizerFile(
            $normalizerClass,
            $toScalarBody,
            $bodyBuilder->getCode(),
        );
    }

    private static function encloseInReturnStatement(string $code): string
    {
        return "return {$code};";
    }
}
