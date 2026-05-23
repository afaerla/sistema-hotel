<?php
declare(strict_types=1);

/**
 * Front Controller — ponto de entrada unico da aplicacao.
 *
 * Carrega variaveis de ambiente, inicializa o logger
 * e roteia as requisicoes HTTP.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HotelReservas\Config\Logger;
use HotelReservas\Controllers\AuthController;
use HotelReservas\Controllers\ReservaController;
use HotelReservas\Controllers\QuartoController;
use HotelReservas\Middleware\JwtAuth;

// ---------------------------------------------------------------
// 1. Carrega .env (nunca hardcode de credenciais)
// ---------------------------------------------------------------
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($val));
        $_ENV[trim($key)] = trim($val);
    }
}

// ---------------------------------------------------------------
// 2. Configura tratamento global de erros
// ---------------------------------------------------------------
set_exception_handler(function (Throwable $e) {
    Logger::critical('Exceção não capturada', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    // Resposta generica — nunca expoe stack trace em producao
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['erro' => 'Erro interno do servidor.']);
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    Logger::error("PHP Error [{$errno}]", ['msg' => $errstr, 'file' => $errfile, 'line' => $errline]);
    return true;
});

// Oculta erros do output em producao
if (getenv('APP_ENV') !== 'development') {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// ---------------------------------------------------------------
// 3. Headers CORS e Content-Type
// ---------------------------------------------------------------
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ---------------------------------------------------------------
// 4. Roteamento
// ---------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/');

// Parse do body JSON
$body = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $body = json_decode($raw, true) ?? [];
}

// Rotas publicas
if ($uri === '/auth/login' && $method === 'POST') {
    (new AuthController())->login($body);
    exit;
}

if ($uri === '/auth/registrar' && $method === 'POST') {
    (new AuthController())->registrar($body);
    exit;
}

if ($uri === '/quartos' && $method === 'GET') {
    (new QuartoController())->listarDisponiveis();
    exit;
}

// ---------------------------------------------------------------
// 5. Rotas protegidas — exige JWT valido
// ---------------------------------------------------------------
$token = JwtAuth::fromRequest();
if (!$token) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token de autenticacao obrigatorio.']);
    exit;
}

try {
    $payload = JwtAuth::validate($token);
    $usuarioId = (int) $payload['sub'];
} catch (InvalidArgumentException $e) {
    http_response_code(401);
    echo json_encode(['erro' => $e->getMessage()]);
    exit;
}

// Roteamento autenticado
match(true) {
    $uri === '/reservas' && $method === 'GET'
        => (new ReservaController())->listar($usuarioId),

    $uri === '/reservas' && $method === 'POST'
        => (new ReservaController())->criar($body, $usuarioId),

    preg_match('#^/reservas/(\d+)$#', $uri, $m) && $method === 'GET'
        => (new ReservaController())->buscar((int) $m[1], $usuarioId),

    preg_match('#^/reservas/(\d+)$#', $uri, $m) && $method === 'DELETE'
        => (new ReservaController())->cancelar((int) $m[1], $usuarioId),

    default => (function () {
        http_response_code(404);
        echo json_encode(['erro' => 'Rota nao encontrada.']);
    })()
};
