<?php
declare(strict_types=1);

namespace HotelReservas\Config;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Gerenciador de conexao com o banco de dados usando Singleton + PDO.
 * Credenciais carregadas exclusivamente via variaveis de ambiente.
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    /**
     * Retorna a instancia unica do PDO (Singleton).
     * Nunca expoe credenciais em codigo — usa getenv().
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    private static function createConnection(): PDO
    {
        $host = self::requireEnv('DB_HOST');
        $port = self::requireEnv('DB_PORT');
        $name = self::requireEnv('DB_NAME');
        $user = self::requireEnv('DB_USER');
        $pass = self::requireEnv('DB_PASS');

        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // Prepared statements reais
                PDO::ATTR_PERSISTENT         => false,
            ]);

            // Configura timezone e encoding
            $pdo->exec("SET timezone = 'America/Sao_Paulo'");
            $pdo->exec("SET client_encoding = 'UTF8'");

            return $pdo;

        } catch (PDOException $e) {
            // Log interno — NUNCA expoe detalhes da conexao ao usuario
            Logger::error('Falha na conexao com o banco de dados', [
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
            ]);
            throw new RuntimeException('Servico temporariamente indisponivel.', 503);
        }
    }

    private static function requireEnv(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new RuntimeException("Variavel de ambiente obrigatoria nao definida: {$key}");
        }
        return $value;
    }
}
