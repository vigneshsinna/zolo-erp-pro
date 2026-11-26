<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Conversation;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\WorkflowTaskContext;
use App\Ai\Services\WorkflowTaskStateService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IndependentWorkflowRevisionTest extends TestCase
{
    public function test_independent_mysql_sessions_cannot_both_apply_the_same_revision(): void
    {
        if (getenv('ERP_TEST_MYSQL') !== '1') {
            $this->markTestSkipped('Requires a disposable MySQL database.');
        }
        $database = getenv('ERP_TEST_MYSQL_DATABASE') ?: '';
        $connection = DB::connection();
        $this->assertMatchesRegularExpression('/^zolo_test_[a-z0-9_]+$/D', $database);
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame($database, $connection->getDatabaseName());
        $principal = new AiPrincipal(1, 1, 1, 1);
        $task = app(WorkflowTaskStateService::class)->createTask(
            new WorkflowTaskContext($principal, new CompanyContext(1, 1, 1)), 'purchase_order.create.v1'
        );
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-revision-'.Str::uuid();
        $workers = [];
        try {
            foreach (['first', 'second'] as $note) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/ai_workflow_revision_worker.php'), $task['id'], $barrier, $note],
                    base_path(), [
                        'APP_ENV' => 'testing',
                        'DB_CONNECTION' => 'mysql',
                        'DB_URL' => false,
                        'DB_HOST' => (string) $connection->getConfig('host'),
                        'DB_PORT' => (string) $connection->getConfig('port'),
                        'DB_DATABASE' => $database,
                        'DB_USERNAME' => (string) $connection->getConfig('username'),
                        'DB_PASSWORD' => (string) $connection->getConfig('password'),
                        'ERP_TEST_MYSQL' => '1',
                        'ERP_TEST_MYSQL_DATABASE' => $database,
                    ]);
                $worker->setTimeout(25);
                $worker->start();
                $workers[$note] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        $this->fail('Race worker exited before the start barrier: '.$worker->getErrorOutput());
                    }
                }
                clearstatcache();
                if (is_file($barrier.'.first') && is_file($barrier.'.second')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertFileExists($barrier.'.first');
            $this->assertFileExists($barrier.'.second');
            touch($barrier);

            $results = [];
            foreach ($workers as $note => $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[$note] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertCount(2, array_unique(array_column($results, 'session')));
            $this->assertEqualsCanonicalizing([200, 409], array_column($results, 'status'));
            $this->assertSame([1, 1], array_column($results, 'expected'));
            $this->assertSame([2, 2], array_column($results, 'actual'));
            $winner = $results['first']['status'] === 200 ? 'first' : 'second';
            $this->assertSame($winner, DB::table('ai_workflow_tasks')->where('id', $task['id'])->value('slots_json->note'));
            $this->assertSame(1, DB::table('ai_workflow_task_events')->where('task_id', $task['id'])->where('event_type', 'patched')->count());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            foreach ([$barrier, $barrier.'.first', $barrier.'.second'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            DB::table('ai_workflow_task_events')->where('task_id', $task['id'])->delete();
            DB::table('ai_workflow_tasks')->where('id', $task['id'])->delete();
        }
    }
}
