<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use App\Ai\Knowledge\KnowledgeDefinitionValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class BusinessKnowledgeRegistryTest extends TestCase
{
    public function test_catalog_loads_but_drafts_cannot_be_used_as_reviewed_actions(): void
    {
        $catalog = new BusinessKnowledgeRegistry;
        $this->assertNotNull($catalog->find('entity.supplier.v1'));
        $this->assertNull($catalog->find('entity.supplier.v1', reviewedOnly: true));
        $this->assertSame([], $catalog->forAction('purchase_order.create.v1'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $catalog->versionHash());
    }

    public function test_duplicate_id_fails(): void
    {
        [$doc, $manifest] = $this->files();
        $doc['definitions'][] = $doc['definitions'][0];
        $this->expectException(InvalidArgumentException::class);
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
    }

    public function test_broken_relationship_fails(): void
    {
        [$doc, $manifest] = $this->files();
        foreach ($doc['definitions'] as &$row) {
            if ($row['kind'] === 'relationship') {
                $row['to'] = 'entity.missing.v1';
                break;
            }
        }
        unset($row);
        $this->expectException(InvalidArgumentException::class);
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
    }

    public function test_process_cycle_fails(): void
    {
        [$doc, $manifest] = $this->files();
        foreach ($doc['definitions'] as &$row) {
            if ($row['id'] === 'process.purchase_to_pay.v1') {
                $row['steps'][0]['depends_on'] = ['bill'];
                break;
            }
        }
        unset($row);
        $this->expectException(InvalidArgumentException::class);
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
    }

    public function test_unreviewed_signoff_cannot_be_forged_by_changing_status(): void
    {
        [$doc, $manifest] = $this->files();
        $doc['definitions'][0]['status'] = 'reviewed';
        $this->expectException(InvalidArgumentException::class);
        (new KnowledgeDefinitionValidator)->validate($doc, $manifest, base_path());
    }

    private function files(): array
    {
        $dir = base_path('resources/ai/knowledge/');
        return [
            json_decode((string) file_get_contents($dir.'definitions.json'), true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents($dir.'source_manifest.json'), true, flags: JSON_THROW_ON_ERROR),
        ];
    }
}
