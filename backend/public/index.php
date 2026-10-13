<?php
declare(strict_types=1);

// Backend API. Run with: php -S 127.0.0.1:8081 -t backend/public backend/public/index.php
require __DIR__ . '/../src/bootstrap.php';

const TARGET_READINGS = 100;

function respondJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Starts worker.php detached from this request so the run outlives the HTTP call and the browser tab. */
function launchWorker(string $runId): void
{
    $php = escapeshellarg(PHP_BINARY);
    $script = escapeshellarg(ROOT . '/worker.php');
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen("start /B \"\" $php $script $runId > NUL 2>&1", 'r'));
    } else {
        exec("$php $script $runId > /dev/null 2>&1 &");
    }
}

// CORS: only the configured frontend origins may call this API.
$allowedOrigins = array_map('trim', explode(',', Config::get('FRONTEND_ORIGINS', 'http://127.0.0.1:8080,http://localhost:8080')));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originAllowed = in_array($origin, $allowedOrigins, true);
if ($originAllowed) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Blocks cross-site form posts (CSRF) from starting runs: a state-changing call with a foreign Origin is refused.
if ($method !== 'GET' && $origin !== '' && !$originAllowed) {
    respondJson(403, ['error' => 'Origin not allowed']);
}

Logger::write("API $method $path from " . ($_SERVER['REMOTE_ADDR'] ?? '-'));

try {
    $state = new RunState(DATA_DIR);

    switch ("$method $path") {
        case 'POST /runs':
            $run = $state->tryStart(bin2hex(random_bytes(4)), TARGET_READINGS);
            if ($run === null) {
                // Only one run at a time: the service keeps a single begin/end task per token.
                respondJson(409, ['error' => 'A run is already in progress', 'run' => $state->read()]);
            }
            launchWorker($run['id']);
            respondJson(202, ['run' => $run]);

        case 'GET /runs/current':
            respondJson(200, ['run' => $state->read()]);

        case 'GET /runs/current/csv':
            $run = $state->read();
            if ($run === null || $run['status'] !== 'done' || !is_file(RESULT_CSV)) {
                respondJson(404, ['error' => 'No finished run to download']);
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="washpal.csv"');
            header('Content-Length: ' . filesize(RESULT_CSV));
            readfile(RESULT_CSV);
            exit;

        default:
            respondJson(404, ['error' => 'Not found']);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    respondJson(500, ['error' => 'Internal error']);
}
