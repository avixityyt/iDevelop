<?php
declare(strict_types=1);

// Local stand-in for the Wash Pal service, for testing without the VPN.
// Run: php -S 127.0.0.1:10102 tools/mock_washpal.php   (credentials: user / pass)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$ttl = (int) (getenv('MOCK_TOKEN_TTL') ?: 1800);
$tokenFile = sys_get_temp_dir() . '/mock_washpal_token';

if ($path === '/' && ($_GET['token'] ?? '') === 'renew') {
    if ($auth !== 'Basic ' . base64_encode('user:pass')) {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    $token = bin2hex(random_bytes(8));
    file_put_contents($tokenFile, $token);
    header('Content-Type: text/xml; charset=utf-8');
    echo '<Token><Value>' . $token . '</Value><Created>' . gmdate('Y-m-d H:i:s') . '</Created><Expires>'
        . gmdate('Y-m-d H:i:s', time() + $ttl) . '</Expires></Token>';
    exit;
}

if ($auth !== 'Bearer ' . (string) @file_get_contents($tokenFile)) {
    http_response_code(401);
    exit;
}

if ($path === '/task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    if ($body === 'command=begin' || $body === 'command=end') {
        exit; // 200 with an empty body
    }
    if (preg_match('/^command=getvalue,request=\d\d:\d\d:\d\d$/', $body)) {
        $roll = random_int(1, 10);
        $type = $roll === 1 ? 'unknown' : (random_int(0, 1) ? 'washer' : 'dryer');
        $temp = $roll === 2 ? 250 : random_int(60, 190);
        $node = random_int(1, 3);
        header('Content-Type: application/json');
        echo json_encode(['data' => ['node' => $node, 'type' => $type, 'name' => "m_$node", 'temp' => $temp]]);
        exit;
    }
}

http_response_code(404);
