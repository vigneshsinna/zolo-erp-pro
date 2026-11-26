<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Conversation;

use App\Ai\Conversation\BusinessIntentInterpreter;
use App\Ai\Conversation\ConversationQuestionPlanner;
use App\Ai\Conversation\PendingPartyIntentService;
use App\Ai\DTOs\AiPrincipal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BusinessIntentInterpreterTest extends TestCase
{
    public function test_direction_of_trade_is_not_equated_with_keyword_buying(): void
    {
        $interpreter = new BusinessIntentInterpreter(new PendingPartyIntentService);
        $this->assertSame('supplier', $interpreter->direction('We buy linen from them.'));
        $this->assertSame('supplier', $interpreter->direction('They supply fabric to us.'));
        $this->assertSame('customer', $interpreter->direction('They buy linen from us.'));
        $this->assertSame('customer', $interpreter->direction('We sell to them.'));
        $this->assertNull($interpreter->direction('Create a new party for buying linen.'));
        $this->assertNull($interpreter->direction('I need a linen trader.'));
    }

    public function test_disabled_semantic_mode_never_calls_model_or_database(): void
    {
        config(['ai_business_knowledge.enabled' => false, 'ai_business_knowledge.semantic_enabled' => false]);
        Http::preventStrayRequests();
        $interpreter = new BusinessIntentInterpreter(new PendingPartyIntentService);
        $principal = new AiPrincipal(actorId: 1, companyId: 1, branchId: 1, financialYearId: 1);
        $this->assertNull($interpreter->start($principal, '00000000-0000-4000-8000-000000000000', 'Create a new vendor'));
        Http::assertNothingSent();
    }

    public function test_shared_supplier_questions_follow_confirmed_facts_and_skips(): void
    {
        $planner = new ConversationQuestionPlanner;
        $action = 'setup.supplier.create.v1';
        $this->assertStringContainsString('supplier name', $planner->next($action, []));
        $this->assertStringContainsString('phone', $planner->next($action, ['name' => 'New Trader']));
        $this->assertStringContainsString('address', $planner->next($action, [
            'name' => 'New Trader', 'phone_number' => '9876543210',
        ]));
        $this->assertStringContainsString('GST', $planner->next($action, [
            'name' => 'New Trader', 'phone_number' => '9876543210', 'address' => 'Market Road',
        ]));
        $this->assertStringContainsString('GSTIN', $planner->next($action, [
            'name' => 'New Trader', 'phone_number' => '9876543210', 'address' => 'Market Road',
            '_conversation_gst_status' => 'registered',
        ]));
        $this->assertNull($planner->next($action, [
            'name' => 'New Trader', '_conversation_skips' => ['contact', 'address', 'tax'],
        ]));
        $this->assertSame(['contact', 'address', 'tax'], $planner->skipGroups('skip all optional'));
    }

    public function test_shared_customer_questions_and_intent_extraction(): void
    {
        $planner = new ConversationQuestionPlanner;
        $action = 'setup.customer.create.v1';
        $this->assertStringContainsString('customer name', $planner->next($action, []));
        $this->assertStringContainsString('contact', $planner->next($action, ['name' => 'Acme Retail']));
        $this->assertStringContainsString('address', $planner->next($action, [
            'name' => 'Acme Retail', 'phone_number' => '9876543210',
        ]));
        $this->assertNull($planner->next($action, [
            'name' => 'Acme Retail', '_conversation_skips' => ['contact', 'address'],
        ]));

        config(['ai_business_knowledge.enabled' => true, 'ai_business_knowledge.semantic_enabled' => true]);
        $interpreter = new BusinessIntentInterpreter(new PendingPartyIntentService);
        $principal = new AiPrincipal(actorId: 1, companyId: 1, branchId: 1, financialYearId: 1);

        $res1 = $interpreter->start($principal, '00000000-0000-4000-8000-000000000000', 'Create a customer named Zenith');
        $this->assertSame('customer', $res1['role']);
        $this->assertSame('Zenith', $res1['facts']['name']);
        $this->assertSame('deterministic', $res1['source']);

        $res2 = $interpreter->start($principal, '00000000-0000-4000-8000-000000000000', 'Create a customer who buys linen from us');
        $this->assertSame('customer', $res2['role']);
        $this->assertSame('deterministic', $res2['source']);
        $this->assertFalse($res2['needs_clarification']);
    }
}
