<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

use Closure;

final readonly class ActionFieldRequirement
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $allowedSources
     * @param list<ActionFieldRequirement> $itemRequirements
     */
    public function __construct(
        public string $fieldPath,
        public string $label,
        public string $dataType = 'string', // entity_reference, decimal_string, integer, date, string, list
        public ?string $entityType = null, // supplier, warehouse, product, unit, customer, account
        public string $inputControl = 'text', // entity_select, text, number, date, select
        public ?string $helpText = null,
        public ?Closure $requiredWhen = null,
        public array $dependencies = [],
        public string $resolutionPolicy = 'exact_or_candidates', // exact_or_candidates, exact_only, none
        public array $allowedSources = ['user', 'erp_default', 'extraction'],
        public ?Closure $defaultPolicy = null,
        public bool $isList = false,
        public array $itemRequirements = [],
        public bool $sensitive = false,
    ) {
    }

    public function isRequired(array $slots, WorkflowTaskContext $context): bool
    {
        if ($this->requiredWhen === null) {
            return true;
        }

        return (bool) ($this->requiredWhen)($slots, $context);
    }

    public function resolveDefault(WorkflowTaskContext $context, array $slots): mixed
    {
        if ($this->defaultPolicy === null) {
            return null;
        }

        return ($this->defaultPolicy)($context, $slots);
    }
}
