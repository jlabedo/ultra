<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

use Ultra\DecisionEngine\Attribute\BindTo;
use Ultra\DecisionEngine\Attribute\DomainIdentifier;
use Ultra\DecisionEngine\ReducerTrait;

final class CommandMetadataResolver
{
    private const DECIDE_METHOD = 'decide';
    private const CHECK_METHOD = 'check';

    public function resolve(string $commandClass): CommandMetadata
    {
        $ref = new \ReflectionClass($commandClass);

        $decideMethod = $ref->getMethod(self::DECIDE_METHOD);
        $checkMethod = $ref->hasMethod(self::CHECK_METHOD)
            ? $ref->getMethod(self::CHECK_METHOD)
            : null;

        $stateParameters = [];
        $serviceParameters = [];

        foreach ($decideMethod->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type === null) {
                continue;
            }
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $typeClass = $type->getName();
            if ($this->isStateClass($typeClass)) {
                $stateParameters[] = $this->resolveStateParameter($parameter, $typeClass, $ref);
            } else {
                $serviceParameters[] = new ServiceParameterMetadata(
                    $parameter->getName(),
                    $typeClass,
                );
            }
        }

        $this->validateCommandProperties($ref, $stateParameters);

        return new CommandMetadata(
            $decideMethod,
            $checkMethod,
            $stateParameters,
            $serviceParameters,
        );
    }

    private function isStateClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $ref = new \ReflectionClass($class);

        if ($ref->getTraitNames() !== [] && in_array(ReducerTrait::class, $ref->getTraitNames(), true)) {
            return true;
        }

        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attr) {
                return true;
            }
        }

        return false;
    }

    private function resolveStateParameter(\ReflectionParameter $parameter, string $stateClass, \ReflectionClass $commandRef): StateParameterMetadata
    {
        $domainIdentifierName = $this->getDomainIdentifierName($stateClass);

        $bindTo = null;
        foreach ($parameter->getAttributes(BindTo::class) as $attr) {
            $bindTo = $attr->newInstance();
            break;
        }
        $commandProperty = $bindTo !== null
            ? $bindTo->property
            : $domainIdentifierName . 'Id';

        $type = $parameter->getType();
        $isNullable = $type !== null && $type->allowsNull();

        return new StateParameterMetadata(
            $parameter->getName(),
            $stateClass,
            $domainIdentifierName,
            $commandProperty,
            $isNullable,
        );
    }

    private function getDomainIdentifierName(string $stateClass): string
    {
        $ref = new \ReflectionClass($stateClass);
        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attr) {
                return $attr->newInstance()->name;
            }
        }

        throw new \LogicException(sprintf(
            'State class %s has no property with #[DomainIdentifier].',
            $stateClass,
        ));
    }

    /**
     * @param list<StateParameterMetadata> $stateParameters
     */
    private function validateCommandProperties(\ReflectionClass $commandRef, array $stateParameters): void
    {
        $commandProps = [];
        foreach ($commandRef->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $commandProps[$prop->getName()] = true;
        }

        foreach ($stateParameters as $stateParam) {
            if (!isset($commandProps[$stateParam->commandProperty])) {
                throw new \LogicException(sprintf(
                    'Command %s has no property "%s" required for state parameter "%s" (domain identifier "%s"). '
                    . 'Either add the property or use #[BindTo("propertyName")] to bind to a different command property.',
                    $commandRef->getName(),
                    $stateParam->commandProperty,
                    $stateParam->parameterName,
                    $stateParam->domainIdentifierName,
                ));
            }
        }
    }
}
