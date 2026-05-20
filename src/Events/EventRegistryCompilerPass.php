<?php

declare(strict_types=1);

namespace Ultra\Events;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Events\Attribute\DomainIdentifier;

class EventRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $eventMap = [];
        $domainIdentifierMap = [];
        $domainIdentifierRequiresScope = [];
        foreach ($container->findTaggedResourceIds(EventsExtension::EVENT_TAG) as $serviceId => $tags) {
            $class = $container->getDefinition($serviceId)->getClass();
            foreach ($tags as $tag) {
                $name = $tag['name'] ?? $class;
                if (isset($eventMap[$name]) && $eventMap[$name] !== $class) {
                    throw new \LogicException(sprintf(
                        'Duplicate #[Event] name "%s": "%s" and "%s".',
                        $name,
                        $eventMap[$name],
                        $class,
                    ));
                }
                $eventMap[$name] = $class;
            }

            $compiledDomainIdentifiers = $this->compileDomainIdentifiers($class);
            if ([] !== $compiledDomainIdentifiers['identifiers']) {
                $domainIdentifierMap[$class] = $compiledDomainIdentifiers['identifiers'];
                $domainIdentifierRequiresScope[$class] = $compiledDomainIdentifiers['requiresScope'];
            }
        }

        $container->setDefinition(EventRegistryInterface::class, new Definition(
            CompiledEventRegistry::class,
            [$eventMap, $domainIdentifierMap, $domainIdentifierRequiresScope]
        ));
    }

    /**
     * @return array{identifiers: array<string, string>, requiresScope: bool}
     */
    private function compileDomainIdentifiers(string $eventClass): array
    {
        $eventRef = new \ReflectionClass($eventClass);
        $domainIdentifiers = [];
        $requiresScope = false;

        foreach ($eventRef->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attribute) {
                $domainIdentifiers[$attribute->newInstance()->name] = $property->getName();
                $requiresScope = $requiresScope || !$property->isPublic();
            }
        }

        return [
            'identifiers' => $domainIdentifiers,
            'requiresScope' => $requiresScope,
        ];
    }
}
