<?php

namespace Ultra\Mapper;

use Symfony\Component\TypeInfo\Type;
use Ultra\Mapper\CodeGenerator\CodeGeneratorContext;
use Ultra\Mapper\Exception\CircularReferenceException;

class UltraNormalizer
{
    private const NAMESPACE = 'Ultra\Normalizer\Generated';

    private string $cacheDir;

    /** @var array<class-string, RuntimeNormalizerInterface> */
    private array $runtimeNormalizers = [];

    /** @var array<class-string, NormalizerInterface> */
    private array $generatedNormalizers = [];

    private DenormalizationContext $defautDenormalizationContext;

    /**
     * @var array<int, true>
     */
    private array $visitedOids = [];

    public function __construct(string $cacheDir = __DIR__.'/../../var/normalizer_cache')
    {
        $this->cacheDir = $cacheDir;
        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0o777, true);
        }
        $this->defautDenormalizationContext = new DenormalizationContext();
    }

    /**
     * Converts an object to an array using generated code for high performance.
     */
    public function toScalar(object $object): mixed
    {
        $isRootNormalizer = empty($this->visitedOids);
        $oid = spl_object_id($object);
        if (isset($this->visitedOids[$oid])) {
            throw new CircularReferenceException('Circular reference detected for object id '.$oid);
        }
        $this->visitedOids[$oid] = true;

        try {
            $normalizer = $this->generatedNormalizers[$object::class] ?? $this->runtimeNormalizers[$object::class] ?? $this->createNormalizer($object::class);

            return $normalizer->toScalar($object, $this);
        } finally {
            if ($isRootNormalizer) {
                $this->visitedOids = [];
            }
        }
    }

    /**
     * Converts an array to an object of the given class using generated code for high performance.
     */
    public function fromScalar(array|bool|float|int|string|null $data, string $className, ?DenormalizationContext $context = null): object
    {
        $normalizer = $this->generatedNormalizers[$className] ?? $this->runtimeNormalizers[$className] ?? $this->createNormalizer($className);

        return $normalizer->fromScalar($data, $className, $this, $context ?? $this->defautDenormalizationContext);
    }

    public function registerRuntimeNormalizer(RuntimeNormalizerInterface $normalizer): void
    {
        foreach ($normalizer::handledTypes() as $type) {
            $this->runtimeNormalizers[$type] = $normalizer;
        }
    }

    private function createNormalizer(string $className): NormalizerInterface
    {
        $normalizerClass = self::NAMESPACE.'\__Normalizer_'.str_replace('\\', '_', ltrim($className, '\\'));

        if (! class_exists($normalizerClass)) {
            $classBaseName = str_starts_with($normalizerClass, self::NAMESPACE.'\\')
                ? substr($normalizerClass, strlen(self::NAMESPACE) + 1)
                : $normalizerClass;
            $cacheFile = $this->cacheDir.'/'.$classBaseName.'.php';
            if (! file_exists($cacheFile)) {
                $codeGen = new CodeGeneratorBuilderRegistry($this->runtimeNormalizers)
                    ->getCodeGenerator(Type::object($className), CodeGeneratorContext::new(true, true))
                ;
                $fileGen = NormalizerGenerator::generate($codeGen, $normalizerClass);
                file_put_contents($cacheFile, $fileGen->generate());
            }
            require_once $cacheFile;
        }

        return $this->generatedNormalizers[$className] = new $normalizerClass();
    }
}
