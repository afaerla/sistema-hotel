<?php
declare(strict_types=1);

namespace HotelReservas\Models;

use HotelReservas\Config\Database;
use HotelReservas\Config\Logger;
use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Modelo de Usuarios com autenticacao segura.
 *
 * SEGURANCA:
 * - Senhas armazenadas com password_hash() (bcrypt)
 * - Busca por email via prepared statement
 * - Sem exposicao de hash para o cliente
 */
class UsuarioModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function registrar(array $dados): int
    {
        $this->validarRegistro($dados);

        // Verifica email duplicado
        if ($this->buscarPorEmail($dados['email'])) {
            throw new RuntimeException('E-mail já cadastrado.', 409);
        }

        // Hash seguro da senha — NUNCA armazenar em texto plano
        $senhaHash = password_hash($dados['senha'], PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, telefone, cpf)
             VALUES (:nome, :email, :senha_hash, :telefone, :cpf)
             RETURNING id'
        );
        $stmt->execute([
            ':nome'       => trim($dados['nome']),
            ':email'      => strtolower(trim($dados['email'])),
            ':senha_hash' => $senhaHash,
            ':telefone'   => $dados['telefone'] ?? null,
            ':cpf'        => $dados['cpf'] ?? null,
        ]);

        $id = (int) $stmt->fetchColumn();
        Logger::info('Novo usuário registrado', ['usuario_id' => $id]);
        return $id;
    }

    /**
     * Autentica o usuario e retorna os dados (sem o hash da senha).
     */
    public function autenticar(string $email, string $senha): array
    {
        $usuario = $this->buscarPorEmail($email);

        // Usa timing-safe verify para evitar timing attacks
        if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
            Logger::warning('Tentativa de login invalida', ['email' => $email]);
            throw new InvalidArgumentException('Credenciais invalidas.', 401);
        }

        if (!$usuario['ativo']) {
            throw new RuntimeException('Conta desativada. Entre em contato com o suporte.', 403);
        }

        // Atualiza hash se o custo do bcrypt mudou
        if (password_needs_rehash($usuario['senha_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $this->atualizarSenhaHash($usuario['id'], $senha);
        }

        // Remove o hash antes de retornar ao controller
        unset($usuario['senha_hash']);
        return $usuario;
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, email, telefone, cpf, created_at, ativo
               FROM usuarios WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private function buscarPorEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, email, senha_hash, telefone, ativo
               FROM usuarios WHERE lower(email) = lower(:email)'
        );
        $stmt->execute([':email' => $email]);
        return $stmt->fetch() ?: null;
    }

    private function atualizarSenhaHash(int $id, string $senha): void
    {
        $novoHash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $this->db->prepare('UPDATE usuarios SET senha_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $novoHash, ':id' => $id]);
    }

    private function validarRegistro(array $dados): void
    {
        if (empty($dados['nome']) || strlen($dados['nome']) < 3) {
            throw new InvalidArgumentException('Nome deve ter ao menos 3 caracteres.');
        }
        if (empty($dados['email']) || !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail invalido.');
        }
        if (empty($dados['senha']) || strlen($dados['senha']) < 8) {
            throw new InvalidArgumentException('Senha deve ter ao menos 8 caracteres.');
        }
    }
}
