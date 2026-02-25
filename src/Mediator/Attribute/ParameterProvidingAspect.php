<?php

declare(strict_types=1);

namespace Ultra\Mediator\Attribute;

/**
 * Extension of AspectAttribute for around advices that inject handler parameters at runtime.
 *
 * When the MediatorCompilerPass detects this interface on an aspect attribute it calls
 * compileParameterResolution() to determine:
 *
 *  1. Which non-message handler parameters this advice will supply (claimedDepIndices).
 *     Those slots are excluded from service-locator resolution; the compiler adds them as
 *     extra parameters to the $proceed arrow function instead.
 *
 *  2. A serializable runtimeConfig array that is embedded verbatim as a PHP literal in the
 *     generated dispatch method and passed as the last argument to the advice method:
 *
 *       method(callable $proceed, object $message, array $context, array $config): mixed
 *
 *     The advice uses $config to know what to load/resolve before calling $proceed and
 *     what to commit after it returns.
 *
 * Example — a generic load-and-save around aspect:
 *
 *   #[Attribute(Attribute::TARGET_METHOD)]
 *   final class LoadAndSave implements ParameterProvidingAspect {
 *       public function __construct(private readonly string $repositoryClass) {}
 *
 *       public function getAdviceDescriptor(): AdviceDescriptor {
 *           return AdviceDescriptor::around(LoadAndSaveAspect::class, 'execute');
 *       }
 *
 *       public function compileParameterResolution(array $depParams): array {
 *           $indices = [];
 *           foreach ($depParams as $i => $param) {
 *               $type = $param->getType();
 *               if ($type instanceof \ReflectionNamedType
 *                   && is_a($type->getName(), Entity::class, true)) {
 *                   $indices[] = $i;
 *               }
 *           }
 *           return ['indices' => $indices, 'config' => ['repo' => $this->repositoryClass]];
 *       }
 *   }
 *
 *   // Aspect service:
 *   final class LoadAndSaveAspect {
 *       public function execute(callable $proceed, object $message, array $context, array $config): mixed {
 *           $entity = $this->locator->get($config['repo'])->load($message);
 *           $result  = $proceed($message, $context, $entity);
 *           $this->locator->get($config['repo'])->save($entity);
 *           return $result;
 *       }
 *   }
 */
interface ParameterProvidingAspect extends AspectAttribute
{
    /**
     * Called at compile time to determine which handler parameters this advice owns
     * and what configuration the advice service needs at runtime to resolve them.
     *
     * @param  \ReflectionParameter[]  $depParams  non-message parameters of the handler method
     * @return array{indices: list<int>, config: array}
     */
    public function compileParameterResolution(array $depParams): array;
}
