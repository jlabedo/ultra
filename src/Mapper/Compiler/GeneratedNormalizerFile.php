<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

use Ultra\CodeGeneration\PhpCode;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\NormalizerInterface;
use Ultra\Mapper\UltraNormalizer;

/**
 * Minimal generated PHP file representation for compiled normalizers.
 */
readonly class GeneratedNormalizerFile
{
    public function __construct(
        private string $normalizerClass,
        private string $toScalarBody,
        private string $fromScalarBody,
    ) {}

    public function generate(): string
    {
        [$namespace, $shortName] = self::splitClassName($this->normalizerClass);
        $namespaceDeclaration = $namespace === null ? '' : "\nnamespace {$namespace};\n";

        return sprintf(<<<'PHP'
            <?php
            %s
            class %s implements \%s
            {
                public function toScalar(mixed $value, \%s $normalizer): array|bool|float|int|string|null
                {
            %s
                }

                public function fromScalar(array|bool|float|int|string|null $value, string $type, \%s $normalizer, \%s $context): object
                {
            %s
                }
            }

            PHP, $namespaceDeclaration, $shortName, NormalizerInterface::class, UltraNormalizer::class, PhpCode::from($this->toScalarBody)->indent(2), UltraNormalizer::class, DenormalizationContext::class, PhpCode::from($this->fromScalarBody)->indent(2));
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function splitClassName(string $className): array
    {
        $className = ltrim($className, '\\');
        $lastSeparator = strrpos($className, '\\');

        if ($lastSeparator === false) {
            return [null, $className];
        }

        return [
            substr($className, 0, $lastSeparator),
            substr($className, $lastSeparator + 1),
        ];
    }
}
