<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Knowledge\PurchaseDocumentClassifier;
use Tests\TestCase;

final class BusinessKnowledgeIntentCorpusTest extends TestCase
{
    public function test_120_known_purchasing_classification_paraphrases(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path('tests/fixtures/ai_business_knowledge_intents.json')
        ), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(120, $fixture['cases']);
        $classifier = new PurchaseDocumentClassifier;
        foreach ($fixture['cases'] as $case) {
            $actual = $classifier->classify($case['utterance']);
            $this->assertSame($case['classification'], $actual['classification'], $case['utterance']);
            $this->assertFalse($actual['writes_executed']);
        }
    }
}
