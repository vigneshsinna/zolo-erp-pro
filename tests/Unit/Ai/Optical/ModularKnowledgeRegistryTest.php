<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Optical;

use App\Ai\Knowledge\BusinessKnowledgeRegistry;
use App\Ai\Knowledge\KnowledgeDefinition;
use App\Ai\Knowledge\KnowledgeDefinitionValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class ModularKnowledgeRegistryTest extends TestCase
{
    public function test_loads_modular_core_and_opticals_knowledge_deterministically(): void
    {
        $registry = new BusinessKnowledgeRegistry(
            base_path(),
            new KnowledgeDefinitionValidator(),
        );

        $all = $registry->all();
        $this->assertGreaterThan(15, count($all));
        $this->assertArrayHasKey('entity.opticals.lens_spec.v1', $all);
        $this->assertArrayHasKey('entity.opticals.lens_stock_identity.v1', $all);
        $this->assertArrayHasKey('policy.opticals.exact_power_identity.v1', $all);
        $this->assertArrayHasKey('process.opticals.direct_stock_purchase.v1', $all);
        $this->assertArrayHasKey('entity.opticals.rx_job.v1', $all);

        $hash1 = $registry->versionHash();
        $hash2 = (new BusinessKnowledgeRegistry(
            base_path(),
            new KnowledgeDefinitionValidator(),
        ))->versionHash();
        $this->assertSame($hash1, $hash2);
    }

    public function test_profile_filtering_separates_general_trading_supplier_and_rx_lab(): void
    {
        $registry = new BusinessKnowledgeRegistry(
            base_path(),
            new KnowledgeDefinitionValidator(),
        );

        $generalIds = array_map(
            static fn (KnowledgeDefinition $d): string => $d->id,
            $registry->forProfile('general_trading', 'trading', false),
        );
        $this->assertContains('entity.supplier.v1', $generalIds);
        $this->assertNotContains('entity.opticals.lens_stock_identity.v1', $generalIds);

        $supplierIds = array_map(
            static fn (KnowledgeDefinition $d): string => $d->id,
            $registry->forProfile('opticals', 'wholesale_distributor', false),
        );
        $this->assertContains('entity.opticals.lens_stock_identity.v1', $supplierIds);
        $this->assertNotContains('entity.opticals.rx_job.v1', $supplierIds);

        $labIds = array_map(
            static fn (KnowledgeDefinition $d): string => $d->id,
            $registry->forProfile('opticals', 'lens_manufacturing', false),
        );
        $this->assertContains('entity.opticals.rx_job.v1', $labIds);
    }

    public function test_schema_v2_validator_checks_effective_dates_and_rejects_duplicate_scoped_aliases(): void
    {
        $expired = KnowledgeDefinition::fromArray([
            'id' => 'policy.opticals.expired.v1',
            'kind' => 'policy',
            'version' => 1,
            'status' => 'draft',
            'owner' => 'optical-commercial',
            'profiles' => ['opticals'],
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-01',
            'summary' => 'Expired test rule',
            'source_refs' => ['app/Models/Product.php'],
            'aliases' => ['expired optical rule'],
        ]);

        $this->assertFalse($expired->isEffectiveOn('2026-06-02'));
        $this->assertTrue($expired->isEffectiveOn('2026-03-15'));

        $validator = new KnowledgeDefinitionValidator();
        $document = [
            'schema_version' => 2,
            'definitions' => [
                [
                    'id' => 'entity.opticals.dup_a.v1',
                    'kind' => 'entity',
                    'version' => 1,
                    'status' => 'draft',
                    'owner' => 'optical-commercial',
                    'profiles' => ['opticals'],
                    'effective_from' => '2026-01-01',
                    'summary' => 'Optical duplicate alias A',
                    'source_refs' => ['app/Models/Product.php'],
                    'aliases' => ['lens power sheet'],
                ],
                [
                    'id' => 'entity.opticals.dup_b.v1',
                    'kind' => 'entity',
                    'version' => 1,
                    'status' => 'draft',
                    'owner' => 'optical-commercial',
                    'profiles' => ['opticals'],
                    'effective_from' => '2026-01-01',
                    'summary' => 'Optical duplicate alias B',
                    'source_refs' => ['app/Models/Product.php'],
                    'aliases' => ['lens power sheet'],
                ],
            ],
        ];
        $manifest = [
            'schema_version' => 2,
            'sources' => [],
        ];

        $this->expectException(InvalidArgumentException::class);
        $validator->validate($document, $manifest, base_path());
    }
}
