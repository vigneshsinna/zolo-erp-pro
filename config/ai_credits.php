<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Centralized AI Credit Metering Policy & Settings
    |--------------------------------------------------------------------------
    |
    | Authoritative configuration governing subscription credits, wallet
    | allocations, model rate cards, FX conversion, and payment top-up packs.
    |
    */

    'enabled' => (bool) env('AI_CREDITS_ENABLED', true),

    'credit_policy_version' => env('AI_CREDIT_POLICY_VERSION', 'cost_v1'),

    // 1 credit = 1 paisa (₹0.01) of chargeable model expenditure
    'credit_value_minor_inr' => 1,

    // Default included monthly credit allowance per active subscription
    'included_monthly_credits' => (int) env('AI_INCLUDED_MONTHLY_CREDITS', 20000),

    // Maximum credit hold allowed for a single model request/step
    'max_credits_per_request' => (int) env('AI_MAX_CREDITS_PER_REQUEST', 5000),

    // Maximum concurrent AI execution runs per account/subscription
    'max_concurrent_runs' => (int) env('AI_MAX_CONCURRENT_RUNS', 5),

    // Monthly document processing cap (independent of credit wallet)
    'monthly_media_pages' => (int) env('AI_MONTHLY_MEDIA_PAGES', 100),

    // Timezone used for monthly reset windows
    'monthly_reset_tz' => 'UTC',

    // Prepaid strictness: 0 means zero overdraft permitted
    'grace_overage_credits' => 0,

    // Reservation TTL before automatic reconciliation release
    'reservation_ttl_minutes' => 10,

    // Fallback USD to INR conversion rate
    'default_fx_rate_usd_inr' => 84.5000,
    'default_fx_version' => 'fx_2026_11',

    // When true, require a verified active or prepaid subscription before creating active accounts or granting monthly credits
    'require_subscription' => (bool) env('AI_CREDITS_REQUIRE_SUBSCRIPTION', true),

    /*
    |--------------------------------------------------------------------------
    | Subscription Plan Tiers & Entitlements
    |--------------------------------------------------------------------------
    */
    'plans' => [
        'starter' => [
            'name' => 'Starter Plan',
            'included_monthly_credits' => 5000,
            'monthly_media_pages' => 25,
            'max_concurrent_runs' => 2,
        ],
        'pro' => [
            'name' => 'Pro Plan',
            'included_monthly_credits' => 20000,
            'monthly_media_pages' => 100,
            'max_concurrent_runs' => 5,
        ],
        'enterprise' => [
            'name' => 'Enterprise Plan',
            'included_monthly_credits' => 100000,
            'monthly_media_pages' => 500,
            'max_concurrent_runs' => 20,
        ],
        'free_trial' => [
            'name' => 'Free Trial',
            'included_monthly_credits' => 2000,
            'monthly_media_pages' => 10,
            'max_concurrent_runs' => 1,
        ],
    ],

    // Webhook signing secret for payment provider callbacks (Razorpay, etc.)
    // Mandatory in secure environments; empty secret fails closed (no fallback)
    'webhook_secret' => env('AI_CREDITS_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Top-Up Pack Catalog
    |--------------------------------------------------------------------------
    */
    'packs' => [
        'PACK-5K' => [
            'sku' => 'PACK-5K',
            'name' => '5,000 AI Credits Top-Up',
            'credits' => 5000,
            'price_minor_inr' => 19900, // ₹199.00
            'tax_minor_inr' => 3582,    // 18% GST: ₹35.82
            'total_minor_inr' => 23482,
            'currency' => 'INR',
            'is_active' => true,
        ],
        'PACK-15K' => [
            'sku' => 'PACK-15K',
            'name' => '15,000 AI Credits Top-Up',
            'credits' => 15000,
            'price_minor_inr' => 49900, // ₹499.00
            'tax_minor_inr' => 8982,    // 18% GST: ₹89.82
            'total_minor_inr' => 58882,
            'currency' => 'INR',
            'is_active' => true,
        ],
        'PACK-30K' => [
            'sku' => 'PACK-30K',
            'name' => '30,000 AI Credits Top-Up',
            'credits' => 30000,
            'price_minor_inr' => 89900, // ₹899.00
            'tax_minor_inr' => 16182,   // 18% GST: ₹161.82
            'total_minor_inr' => 106082,
            'currency' => 'INR',
            'is_active' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Baseline Model Rate Cards (USD per billable unit)
    |--------------------------------------------------------------------------
    */
    'default_rate_cards' => [
        'cost_v1' => [
            'nvidia' => [
                'nvidia/nemotron-3.5-lightning-30b-a3b' => [
                    'input_token' => '0.00000020',
                    'output_token' => '0.00000060',
                ],
                'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning' => [
                    'input_token' => '0.00000030',
                    'output_token' => '0.00000090',
                    'image' => '0.00150000',
                ],
                'nvidia/nemotron-3.5-bolt-e4b' => [
                    'input_token' => '0.00000025',
                    'output_token' => '0.00000075',
                ],
                'nvidia/nemotron-3.5-super-vl-120b-a12b' => [
                    'input_token' => '0.00000050',
                    'output_token' => '0.00000150',
                    'image' => '0.00250000',
                ],
                'deepseek-ai/deepseek-v4.1-flash' => [
                    'input_token' => '0.00000014',
                    'output_token' => '0.00000028',
                ],
            ],
            'openai' => [
                'gpt-4o-mini' => [
                    'input_token' => '0.00000015',
                    'output_token' => '0.00000060',
                ],
                'gpt-4o' => [
                    'input_token' => '0.00000250',
                    'output_token' => '0.00001000',
                ],
            ],
            'gemini' => [
                'gemini-1.5-flash' => [
                    'input_token' => '0.000000075',
                    'output_token' => '0.00000030',
                ],
                'gemini-2.0-flash' => [
                    'input_token' => '0.00000010',
                    'output_token' => '0.00000040',
                ],
            ],
            'default' => [
                'input_token' => '0.00000050',
                'output_token' => '0.00000150',
                'image' => '0.00200000',
                'audio_second' => '0.00050000',
            ],
        ],
    ],
];
