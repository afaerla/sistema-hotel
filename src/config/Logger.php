<?php
declare(strict_types=1);

namespace HotelReservas\Config;

use DateTime;
use JsonException;

/**
 * Logger estruturado em JSON.
 * Registra erros em arquivo sem expor detalhes sensiveis ao cliente.
 */
class Logger
{
    private static string $logFile = '';

    public static function init(): void
    {
        $logDir  = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        self::$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::write('CRITICAL', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$logFile === '') {
            self::init();
        }

        // Remove dados sensiveis antes de logar
        $safeContext = self::sanitize($context);

        try {
            $entry = json_encode([
                'timestamp' => (new DateTime())->format('Y-m-d\TH:i:s.uP'),
                'level'     => $level,
                'message'   => $message,
                'context'   => $safeContext,
                'pid'       => getmypid(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            file_put_contents(self::$logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (JsonException $e) {
            // Fallback simples se JSON falhar
            $fallback = date('Y-m-d H:i:s') . " [{$level}] {$message}" . PHP_EOL;
            file_put_contents(self::$logFile, $fallback, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Remove campos sensiveis antes de escrever no log.
     */
    private static function sanitize(array $context): array
    {
        $sensitiveKeys = ['senha', 'password', 'token', 'secret', 'cpf', 'cartao', 'cvv'];
        foreach ($sensitiveKeys as $key) {
            if (isset($context[$key])) {
                $context[$key] = '***REDACTED***';
            }
        }
        return $context;
    }
}
