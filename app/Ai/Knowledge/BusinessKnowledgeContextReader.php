<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionSecurity;
use Illuminate\Auth\Access\AuthorizationException;

/** Produces safe, reviewed static metadata plus scoped facts. Never executes an action. */
final class BusinessKnowledgeContextReader
{
    public function __construct(
        private readonly BusinessKnowledgeRegistry $registry,
        private readonly ScopedBusinessFactReader $facts,
        private readonly ActionSecurity $security,
    ) {}

    public function forAction(AiPrincipal $principal, string $actionKey): array
    {
        $this->ensureEnabled($principal);
        // A public action key is not enough; assert permissions/feature/tenant on every request.
        $this->security->definition($actionKey, $principal);
        return [
            'action_key' => $actionKey,
            'knowledge_hash' => $this->registry->versionHash(),
            'definitions' => array_map(static fn (KnowledgeDefinition $d): array => [
                'id' => $d->id, 'version' => $d->version, 'status' => $d->status,
                'summary' => $d->summary, 'source_refs' => $d->sourceRefs,
            ], $this->registry->forAction($actionKey, reviewedOnly: true)),
            'source' => 'reviewed_business_knowledge',
            'writes_executed' => false,
        ];
    }

    public function fact(AiPrincipal $principal, string $kind, int $id): array
    {
        $this->ensureEnabled($principal);
        $envelope = $this->facts->find($principal, $kind, $id);
        return $envelope + ['knowledge_hash' => $this->registry->versionHash(), 'writes_executed' => false];
    }

    private function ensureEnabled(AiPrincipal $principal): void
    {
        if (!config('ai_business_knowledge.enabled', false)) {
            throw new AuthorizationException('Business knowledge is disabled.');
        }
        $this->security->assertEnabled($principal);
    }
}
