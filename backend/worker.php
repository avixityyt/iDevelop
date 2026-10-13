<?php
declare(strict_types=1);

// Background worker started by the API: php worker.php <runId>
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/src/bootstrap.php';

$runId = $argv[1] ?? '';
if (!preg_match('/^[0-9a-f]{8}$/', $runId)) {
    exit(1);
}

$state = new RunState(DATA_DIR);

try {
    $collector = new Collector(
        new WashPalClient(
            Config::get('WASHPAL_BASE_URL', 'http://10.101.0.1:10101'),
            Config::get('WASHPAL_USER'),
            Config::get('WASHPAL_PASS'),
        ),
        $state,
        $runId,
        100,
        (int) Config::get('MAX_REQUESTS', '1000'),
        (int) Config::get('REQUEST_DELAY_MS', '300'),
    );
    $collector->run();
} catch (Throwable $e) {
    $state->update($runId, ['status' => 'failed', 'phase' => 'finished', 'error' => $e->getMessage(), 'finished_at' => time()]);
}
