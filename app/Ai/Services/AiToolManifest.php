<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\AiToolRegistration;
use App\Ai\Tools\GenericSdkTool;
use InvalidArgumentException;

/** Explicit registry shared by discovery and read execution. */
class AiToolManifest
{
    /** @var array<string, AiToolRegistration> */
    private array $registrations = [];
    private array $providerNames = [];

    public function register(AiToolRegistration $registration): void
    {
        $handler = $registration->handler;
        $key = $handler->key();
        $alias = strtolower($registration->providerName);
        if (!preg_match('/^[a-z][a-z0-9_.]*\.v[1-9]\d*$/D', $key) || $this->has($key)
            || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $registration->providerName) || isset($this->providerNames[$alias])
            || $handler->risk() !== 'read_only' || trim($handler->description()) === ''
            || !in_array('ai.use', $handler->requiredPermissions(), true)) {
            throw new InvalidArgumentException("Invalid or duplicate read capability [{$key}].");
        }

        $schema = $handler->schema();
        $fields = (array) ($schema['properties'] ?? []);
        if (($schema['type'] ?? null) !== 'object' || ($schema['additionalProperties'] ?? null) !== false
            || array_diff($schema['required'] ?? [], array_keys($fields)) !== []) {
            throw new InvalidArgumentException("Read capability [{$key}] requires a closed object schema.");
        }

        foreach ($fields as $name => $field) {
            $constraints = match ($field['type'] ?? null) {
                'string' => ['maxLength', 'format'],
                'integer' => ['minimum', 'maximum'],
                'boolean' => [],
                default => null,
            };
            if ($constraints === null || in_array($name, ['user_id', 'company_id', 'branch_id', 'financial_year_id'], true)
                || array_diff(array_keys($field), ['type', 'description', 'enum', 'default', ...$constraints]) !== []) {
                throw new InvalidArgumentException("Unsupported schema field [{$name}] on capability [{$key}].");
            }
        }

        $this->registrations[$key] = $registration;
        $this->providerNames[$alias] = true;
    }

    public function has(string $key): bool
    {
        return isset($this->registrations[$key]);
    }

    public function get(string $key): ErpToolHandlerInterface
    {
        return $this->registration($key)->handler;
    }

    /** @return array<string, AiToolRegistration> */
    public function all(): array
    {
        return $this->registrations;
    }

    /** @return list<GenericSdkTool> */
    public function sdkTools(AiPrincipal $principal): array
    {
        $tools = [];
        foreach ($this->registrations as $key => $registration) {
            if ($principal->hasTool($key)) {
                $tools[] = $this->sdkTool($key, $principal);
            }
        }

        return $tools;
    }

    public function sdkTool(string $key, AiPrincipal $principal): GenericSdkTool
    {
        return new GenericSdkTool($principal, $this->registration($key), app(ErpToolExecutor::class));
    }

    private function registration(string $key): AiToolRegistration
    {
        return $this->registrations[$key] ?? throw new InvalidArgumentException("Read capability [{$key}] is not registered.");
    }
}
