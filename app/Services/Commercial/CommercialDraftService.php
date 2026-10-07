<?php

namespace App\Services\Commercial;

use App\Services\Platform\CompanyContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-side workspace drafts for the normal Sales and Purchase pages.
 *
 * A draft is an unfinished workspace state: a versioned snapshot of the legacy form. It never reserves stock, posts
 * accounting, opens items, takes payment, consumes a document number or creates a posted document. The table is
 * called sale_drafts for historical reasons and holds both kinds.
 */
class CommercialDraftService
{
    public const MAX_OPEN = 10;
    public const MAX_BYTES = 2097152;
    public const RETENTION_DAYS = 30;
    public const SCHEMA_VERSION = 1;

    /** Drafts of this user in this company for one kind, across branches and years (the quota is not per branch). */
    public function owned(string $kind, CompanyContext $context, int $actor): \Illuminate\Database\Query\Builder
    {
        return DB::table('sale_drafts')->where('company_id', $context->companyId)->where('user_id', $actor)->where('kind', $kind);
    }

    /** Drafts visible in the tab strip: this user's, in the active branch and financial year. */
    public function query(string $kind, CompanyContext $context, int $actor): \Illuminate\Database\Query\Builder
    {
        return $this->owned($kind, $context, $actor)->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId);
    }

    public function meta(\Illuminate\Database\Query\Builder $query): \Illuminate\Support\Collection
    {
        return $query->orderBy('id')->get(['id', 'version', 'title', 'party_id', 'party_name_snapshot', 'created_at', 'updated_at']);
    }

    public function save(string $kind, array $payload, ?int $id, int $version, CompanyContext $context, int $actor, array $meta = []): array
    {
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $context, $actor);
        [$payload, $warnings] = $this->checked($kind, $payload, $context, $actor);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['draft' => 'This bill is too large to save as a draft. Reduce the number of lines or tracking details, or post it before leaving.']);
        }
        $party = ['party_id' => isset($meta['party_id']) && (int) $meta['party_id'] > 0 ? (int) $meta['party_id'] : null,
            'party_name_snapshot' => isset($meta['party_name']) && $meta['party_name'] !== '' ? mb_substr((string) $meta['party_name'], 0, 150) : null];

        $saved = DB::transaction(function () use ($kind, $json, $id, $version, $context, $actor, $party) {
            // One lock per company serialises the quota check and the version check.
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            if ($id) {
                $draft = $this->query($kind, $context, $actor)->where('id', $id)->lockForUpdate()->first();
                abort_unless($draft, 404, 'This draft no longer exists. It may have been posted or removed.');
                if ((int) $draft->version !== $version) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Draft changed in another window.', 'code' => 'draft_conflict',
                        'current' => ['id' => (int) $draft->id, 'version' => (int) $draft->version, 'updated_at' => $draft->updated_at],
                    ], 409));
                }
                DB::table('sale_drafts')->where('id', $id)->update($party + ['version' => $version + 1, 'payload_json' => $json, 'updated_at' => now()]);
                return $id;
            }
            $this->prune($kind, $context, $actor);
            if ($this->owned($kind, $context, $actor)->count() >= self::MAX_OPEN) {
                throw ValidationException::withMessages(['draft' => 'You already have '.self::MAX_OPEN.' open '.($kind === 'sale' ? 'Sales' : 'Purchase')
                    .' drafts. Close or post one before starting another.'])->status(422);
            }
            return DB::table('sale_drafts')->insertGetId($party + ['company_id' => $context->companyId, 'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId, 'user_id' => $actor, 'kind' => $kind,
                'title' => $this->nextTitle($kind, $context, $actor),
                'version' => 1, 'payload_json' => $json, 'created_at' => now(), 'updated_at' => now()]);
        });
        return ['draft' => $this->meta($this->query($kind, $context, $actor)->where('id', $saved))->first(), 'warnings' => $warnings];
    }

    /** The full draft with its references re-validated against the current trusted context. */
    public function load(string $kind, int $id, CompanyContext $context, int $actor): array
    {
        $row = $this->query($kind, $context, $actor)->where('id', $id)->first();
        abort_unless($row, 404, 'This draft no longer exists. It may have been posted or removed.');
        $payload = json_decode($row->payload_json, true);
        $warnings = [];
        $blocking = [];
        if (($payload['schema_version'] ?? 0) === self::SCHEMA_VERSION) {
            $refs = $payload['form']['context'] ?? [];
            $result = app(DocumentContextResolver::class)->revalidate($refs, $kind, $context, $actor);
            $payload['form']['context'] = array_intersect_key($refs, array_flip($result['valid']));
            $warnings = $result['warnings'];
            $blocking = $result['blocking'];
        }
        return ['draft' => $this->meta($this->query($kind, $context, $actor)->where('id', $id))->first(), 'payload' => $payload,
            'legacy' => ($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION, 'warnings' => $warnings, 'blocking' => $blocking];
    }

    /** A draft whose required references are gone must not be posted: removing them would change what is posted. */
    public function assertPostable(string $kind, int $id, CompanyContext $context, int $actor): void
    {
        $loaded = $this->load($kind, $id, $context, $actor);
        if ($loaded['blocking']) {
            throw ValidationException::withMessages(['draft' => implode(' ', $loaded['blocking'])]);
        }
    }

    public function delete(string $kind, int $id, CompanyContext $context, int $actor): void
    {
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $context, $actor);
        abort_unless($this->query($kind, $context, $actor)->where('id', $id)->delete(), 404, 'This draft no longer exists.');
    }

    /** Lazy prune for one owner/kind. Age is measured from the last edit, never from creation. */
    public function prune(string $kind, CompanyContext $context, int $actor): int
    {
        return $this->owned($kind, $context, $actor)->where('updated_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    /** Scheduled prune for every owner. */
    public function pruneExpired(): int
    {
        return DB::table('sale_drafts')->where('updated_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    private function nextTitle(string $kind, CompanyContext $context, int $actor): string
    {
        $used = $this->owned($kind, $context, $actor)->pluck('title')
            ->map(fn ($title) => preg_match('/^Draft (\d+)$/', (string) $title, $match) ? (int) $match[1] : 0)->filter()->all();
        $number = 1;
        while (in_array($number, $used, true)) $number++;
        return 'Draft '.$number;
    }

    /** @return array{0: array, 1: list<string>} the payload with unusable optional references removed, plus the notices. */
    private function checked(string $kind, array $payload, CompanyContext $context, int $actor): array
    {
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($payload['document_kind'] ?? null) !== $kind
            || !is_array($payload['form']['fields'] ?? null) || !is_array($payload['form']['lines'] ?? null)) {
            throw ValidationException::withMessages(['payload' => 'Unsupported draft format.']);
        }
        $refs = $payload['form']['context'] ?? [];
        $result = app(DocumentContextResolver::class)->revalidate(is_array($refs) ? $refs : [], $kind, $context, $actor);
        if ($result['blocking']) {
            throw ValidationException::withMessages(['draft' => implode(' ', $result['blocking'])]);
        }
        $payload['form']['context'] = array_intersect_key(is_array($refs) ? $refs : [], array_flip($result['valid']));
        return [$payload, $result['warnings']];
    }
}
