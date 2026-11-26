<?php

declare(strict_types=1);

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\WorkflowTaskStateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

[$script, $taskId, $barrier, $note] = $argv;
$database = getenv('ERP_TEST_MYSQL_DATABASE') ?: '';
if (getenv('APP_ENV') !== 'testing' || getenv('ERP_TEST_MYSQL') !== '1'
    || !preg_match('/^zolo_test_[a-z0-9_]+$/D', $database) || getenv('DB_DATABASE') !== $database) {
    throw new RuntimeException('The race worker requires the named disposable test database.');
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database) {
    throw new RuntimeException('The race worker resolved an unexpected connection.');
}

$state = app(WorkflowTaskStateService::class);
$principal = new AiPrincipal(1, 1, 1, 1);
$revision = $state->getTask($principal, $taskId)['revision'];
$session = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($barrier.'.'.$note, 'ready');
$deadline = microtime(true) + 15;
while (!is_file($barrier)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for both race workers.');
    }
    usleep(10000);
}

try {
    $result = $state->updateTaskState($principal, $taskId, $revision, ['note' => $note], patch: ['note' => $note]);
    $status = 200;
} catch (ConflictHttpException $e) {
    $result = $state->getTask($principal, $taskId);
    $status = $e->getStatusCode();
}

echo json_encode(['session' => $session, 'status' => $status, 'expected' => $revision, 'actual' => $result['revision']], JSON_THROW_ON_ERROR);
