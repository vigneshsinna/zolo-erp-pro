#!/usr/bin/env bash
# Runner for live AI benchmarks and evaluations with strict budget cap enforcement

set -e

PROVIDER="nvidia"
BUDGET_CAP="5.00"

for arg in "$@"
do
    case $arg in
        --provider=*)
        PROVIDER="${arg#*=}"
        shift
        ;;
        --budget-cap-usd=*)
        BUDGET_CAP="${arg#*=}"
        shift
        ;;
        *)
        ;;
    esac
done

echo "=== Configuration ==="
echo "Provider: ${PROVIDER}"
echo "Budget Cap USD: \$${BUDGET_CAP}"

echo "=== Starting AI Live Omni Benchmark with Budget Cap Enforcement ==="
php scripts/run_live_omni_benchmark.php --provider="${PROVIDER}" --budget-cap-usd="${BUDGET_CAP}"

echo "=== Starting Full 250-Scenario AI Evaluation Suite ==="
php vendor/bin/phpunit -c phpunit.ai.xml

echo "=== Exporting Machine-Readable Evaluation Artifacts ==="
php scripts/export_ai_evaluation_report.php

echo "=== All AI Benchmarks and Evaluations Completed Successfully ==="
