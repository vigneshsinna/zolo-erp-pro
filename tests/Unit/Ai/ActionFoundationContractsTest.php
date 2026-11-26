<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\DTOs\ActionDefinition;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Services\ActionApprovalSignature;
use App\Ai\Services\CanonicalActionPayload;
use InvalidArgumentException;
use Tests\TestCase;

final class ActionFoundationContractsTest extends TestCase
{
    public function test_canonical_payload_hash_is_order_independent_but_detects_changes(): void
    {
        $a = ['supplier' => 4, 'items' => [['qty' => '2.00', 'sku' => 'X']], 'currency' => 'INR'];
        $b = ['currency' => 'INR', 'items' => [['sku' => 'X', 'qty' => '2.00']], 'supplier' => 4];
        $this->assertSame(CanonicalActionPayload::hash($a), CanonicalActionPayload::hash($b));
        $b['items'][0]['qty'] = '3.00';
        $this->assertNotSame(CanonicalActionPayload::hash($a), CanonicalActionPayload::hash($b));
    }

    public function test_canonical_payload_rejects_floating_money(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalActionPayload::hash(['amount' => 10.50]);
    }

    public function test_approval_hmac_rejects_tampered_actor_and_scope(): void
    {
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $sig = app(ActionApprovalSignature::class);
        $envelope = [
            'type' => 'zoloerp.action-approval.v1',
            'proposal_id' => 'example',
            'action_key' => 'purchase_order.create.v1',
            'version' => 2,
            'payload_hash' => str_repeat('a', 64),
            'source_state_hash' => str_repeat('b', 64),
            'approver_id' => 9,
            'actor_id' => 8,
            'company_id' => 5,
            'branch_id' => 1,
            'financial_year_id' => 2,
            'created_at_utc' => '2026-11-11 11:00:00',
        ];
        $signed = $sig->sign($envelope);
        $this->assertTrue($sig->matches($envelope, $signed));
        $envelope['company_id'] = 7;
        $this->assertFalse($sig->matches($envelope, $signed));
    }

    public function test_registry_is_empty_by_default_and_rejects_unreviewed_definition(): void
    {
        $registry = new ActionCapabilityRegistry();
        $this->assertSame([], $registry->all());
        $this->expectException(InvalidArgumentException::class);
        $registry->register(new ActionDefinition(
            key: 'purchase_order.create.v1',
            businessModule: 'purchasing',
            riskTier: 'r2_operational',
            requiredPermissions: ['ai.use', 'purchases-add'],
            inputSchemaClass: \stdClass::class,
            previewAdapterClass: \stdClass::class,
            commandAdapterClass: \stdClass::class,
        ));
    }
}
