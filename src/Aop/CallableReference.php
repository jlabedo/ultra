<?php

declare(strict_types=1);

namespace Ultra\Aop;

use Psr\Container\ContainerInterface;

/**
 * Represents a callable reference that can be resolved from a PSR container
 * or called directly as a static method or function.
 */
class CallableReference
{
    public const TYPE_CONTAINER = 'container';
    public const TYPE_STATIC = 'static';
    public const TYPE_FUNCTION = 'function';

    /**
     * @param string      $type        One of TYPE_CONTAINER, TYPE_STATIC, or TYPE_FUNCTION
     * @param string      $className   Fully qualified class name
     * @param string      $methodName  Method or function name
     * @param null|string $containerId Container service ID (only for TYPE_CONTAINER)
     */
    public function __construct(
        private readonly string $type,
        private readonly string $className,
        private readonly string $methodName,
        private readonly ?string $containerId = null
    ) {
        if (self::TYPE_CONTAINER === $type && null === $containerId) {
            throw new \InvalidArgumentException('Container ID is required for container type callable');
        }
        if (self::TYPE_CONTAINER !== $type && null !== $containerId) {
            throw new \InvalidArgumentException('Container ID should only be provided for container type callable');
        }
    }

    /**
     * Create a callable reference from a container service.
     */
    public static function fromContainer(string $containerId, string $className, string $methodName): self
    {
        return new self(self::TYPE_CONTAINER, $className, $methodName, $containerId);
    }

    /**
     * Create a callable reference for a static method.
     */
    public static function fromStatic(string $className, string $methodName): self
    {
        return new self(self::TYPE_STATIC, $className, $methodName);
    }

    /**
     * Create a callable reference for a global function.
     */
    public static function fromFunction(string $functionName): self
    {
        return new self(self::TYPE_FUNCTION, '', $functionName);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getContainerId(): ?string
    {
        return $this->containerId;
    }

    /**
     * Resolve and call this reference using the provided container.
     */
    public function call(ContainerInterface $container, mixed ...$args): mixed
    {
        return match ($this->type) {
            self::TYPE_CONTAINER => $container->get($this->containerId)->{$this->methodName}(...$args),
            self::TYPE_STATIC => ($this->className)::{$this->methodName}(...$args),
            self::TYPE_FUNCTION => ($this->methodName)(...$args),
        };
    }

    /**
     * Get metadata for code generation.
     */
    public function getMetadata(): array
    {
        return [
            'type' => $this->type,
            'className' => $this->className,
            'methodName' => $this->methodName,
            'containerId' => $this->containerId,
        ];
    }
}
