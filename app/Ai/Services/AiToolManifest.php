<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use InvalidArgumentException;

/**
 * Registry and discovery service for registered ERP domain tools.
 */
class AiToolManifest
{
    /**
     * @var array<string, ErpToolHandlerInterface>
     */
    private array $handlers = [];

    /**
     * Register a tool handler.
     */
    public function register(ErpToolHandlerInterface $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    /**
     * Check if a tool key is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    /**
     * Retrieve a registered tool handler by key.
     */
    public function get(string $key): ErpToolHandlerInterface
    {
        if (!$this->has($key)) {
            throw new InvalidArgumentException("Tool handler [{$key}] is not registered in AiToolManifest.");
        }

        return $this->handlers[$key];
    }

    /**
     * Get all registered handlers.
     *
     * @return array<string, ErpToolHandlerInterface>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    /**
     * Filter handlers authorized for a given principal.
     *
     * @return array<string, ErpToolHandlerInterface>
     */
    public function forPrincipal(AiPrincipal $principal): array
    {
        return array_filter(
            $this->handlers,
            fn(ErpToolHandlerInterface $handler) => $principal->hasTool($handler->key())
        );
    }
}

