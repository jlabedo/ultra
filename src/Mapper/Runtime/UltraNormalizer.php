<?php

declare(strict_types=1);

namespace Ultra\Mapper;

use Ultra\Mapper\Exception\CircularReferenceException;
use Ultra\Mapper_Compiler\NormalizerGenerator;

class UltraNormalizer
{
    private string $cacheDir;

    /** @var array<class-string, RuntimeNormalizerInterface> */
    private array $runtimeNormalizers = [];

    /** @var array<class-string, NormalizerInterface> */
    private array $generatedNormalizers = [];

    private DenormalizationContext $defaultDenormalizationContext;

    /**
     * @var array<int, true>
     */
    private array $activeOids = [];

    public function __construct(
        string $cacheDir = __DIR__ . '/../../../var/normalizer_cache',
        private string $generationNamespace = GeneratedNormalizerName::DEFAULT_NAMESPACE,
    ) {
        $this->cacheDir = $cacheDir;
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0o777, true);
        }
        $this->defaultDenormalizationContext = new DenormalizationContext();
    }

    /**
     * Converts an object to an array using generated code for high performance.
     */
    public function toScalar(object $object): mixed
    {
        $oid = spl_object_id($object);
        if (isset($this->activeOids[$oid])) {
            throw new CircularReferenceException('Circular reference detected for object id ' . $oid);
        }
        $this->activeOids[$oid] = true;

        try {
            $normalizer =
                $this->generatedNormalizers[$object::class]
                ?? $this->runtimeNormalizers[$object::class]
                ?? $this->createNormalizer($object::class);

            return $normalizer->toScalar($object, $this);
        } finally {
            unset($this->activeOids[$oid]);
        }
    }

    /**
     * Converts an array to an object of the given class using generated code for high performance.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    public function fromScalar(
        array|bool|float|int|string|null $data,
        string $className,
        ?DenormalizationContext $context = null,
    ): object {
        $normalizer =
            $this->generatedNormalizers[$className]
            ?? $this->runtimeNormalizers[$className]
            ?? $this->createNormalizer($className);

        return $normalizer->fromScalar($data, $className, $this, $context ?? $this->defaultDenormalizationContext);
    }

    public function registerRuntimeNormalizer(RuntimeNormalizerInterface $normalizer): void
    {
        foreach ($normalizer::handledTypes() as $type) {
            $this->runtimeNormalizers[$type] = $normalizer;
        }
    }

    /** @param class-string $className */
    private function createNormalizer(string $className): NormalizerInterface
    {
        /** @var class-string<NormalizerInterface> $normalizerClass The generated class implements NormalizerInterface. */
        $normalizerClass = GeneratedNormalizerName::forType($className, $this->generationNamespace);

        if (!class_exists($normalizerClass, false)) {
            $cacheFile = $this->cacheDir . '/' . GeneratedNormalizerName::cacheFileName($normalizerClass);
            if (!file_exists($cacheFile)) {
                if (!class_exists(NormalizerGenerator::class)) {
                    throw new \RuntimeException(
                        "Cannot generate normalizer for class '{$className}' on the fly because the ultra/mapper-compiler package is not installed. Try running \"composer require ultra/mapper-compiler\".",
                    );
                }
                $runtimeTypes = array_fill_keys(array_keys($this->runtimeNormalizers), true);
                $fileGen = NormalizerGenerator::for($className, $normalizerClass, $runtimeTypes);
                file_put_contents($cacheFile, $fileGen->generate());
            }

            require_once $cacheFile;
        }

        // @mago-expect analyser:unsafe-instantiation
        return $this->generatedNormalizers[$className] = new $normalizerClass();
    }
}
