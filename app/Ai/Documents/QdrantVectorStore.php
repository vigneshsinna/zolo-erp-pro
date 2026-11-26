<?php

declare(strict_types=1);

namespace App\Ai\Documents;

use App\Ai\DTOs\AiPrincipal;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class QdrantVectorStore
{
    public function upsert(array $points): void
    {
        foreach ($points as $point) {
            $this->validateVector($point['vector']);
        }
        $this->client()->put($this->path().'/points?wait=true', ['points' => $points])->throw();
    }

    public function search(AiPrincipal $principal, array $vector, int $limit = 5): array
    {
        $this->validateVector($vector);
        $must = [];
        foreach (['company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
            'actor_id' => $principal->actorId, 'financial_year_id' => $principal->financialYearId,
            'classification' => 'sop', 'embedding_model' => config('ai_documents.qdrant.embedding_model')] as $key => $value) {
            $must[] = ['key' => $key, 'match' => ['value' => $value]];
        }
        $response = $this->client()->post($this->path().'/points/query', [
            'query' => $vector, 'filter' => ['must' => $must], 'limit' => max(1, min(10, $limit)),
            'with_payload' => true, 'with_vector' => false,
        ])->throw();
        return $response->json('result.points', []);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $url = config('ai_documents.qdrant.url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (!config('ai_documents.qdrant.enabled') || !$parts || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Configure an approved HTTPS Qdrant endpoint before indexing knowledge.');
        }
        return Http::baseUrl(rtrim($url, '/'))->acceptJson()->asJson()->connectTimeout(5)->timeout(15)
            ->withoutRedirecting()->withHeaders(['api-key' => (string) config('ai_documents.qdrant.api_key')]);
    }

    private function path(): string
    {
        $collection = (string) config('ai_documents.qdrant.collection');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $collection)) {
            throw new RuntimeException('Invalid Qdrant collection configuration.');
        }
        return '/collections/'.$collection;
    }

    private function validateVector(array $vector): void
    {
        if (count($vector) !== (int) config('ai_documents.qdrant.dimensions')
            || !array_is_list($vector) || array_any($vector, static fn ($value): bool => !is_numeric($value) || !is_finite((float) $value))) {
            throw new RuntimeException('Embedding dimensions or values do not match the approved collection.');
        }
    }
}
