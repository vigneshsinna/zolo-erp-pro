<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;
use Illuminate\Support\Facades\Validator;

final class ProductImportRequirementsProvider implements ActionRequirementsProviderInterface
{
    public function actionKey(): string
    {
        return 'setup.products.import.v1';
    }

    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array
    {
        return [
            new ActionFieldRequirement('batch_id', 'Reviewed import batch', resolutionPolicy: 'none'),
            new ActionFieldRequirement('accepted_rows', 'Selected row numbers', dataType: 'array', inputControl: 'json', resolutionPolicy: 'none'),
            new ActionFieldRequirement('review_hash', 'Import review checksum', resolutionPolicy: 'none'),
        ];
    }

    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array
    {
        $validator = Validator::make($resolvedInputs, app(ProductImportInputSchema::class)->rules());
        return $validator->fails() ? array_map(static fn (array $messages): string => $messages[0], $validator->errors()->toArray()) : [];
    }
}
