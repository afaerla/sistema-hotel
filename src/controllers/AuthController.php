<?php
declare(strict_types=1);

namespace HotelReservas\Controllers;

use HotelReservas\Config\Logger;
use HotelReservas\Middleware\JwtAuth;
use HotelReservas\Models\UsuarioModel;
use InvalidArgumentException;
use RuntimeException;

class AuthController
{
    private UsuarioModel $usuarioModel;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
    }

    /**
     * POST /auth/login
     */
    public function login(array $payload): void
    {
        try {
            if (empty($payload['email']) || empty($payload['senha'])) {
                $this->jsonResponse(400, ['erro' => 'E-mail e senha sao obrigatorios.']);
                return;
            }

            $usuario = $this->usuarioModel->autenticar($payload['email'], $payload['senha']);

            $token = JwtAuth::generate([
                'sub'   => $usuario['id'],
                'nome'  => $usuario['nome'],
                'email' => $usuario['email'],
            ]);

            Logger::info('Login realizado', ['usuario_id' => $usuario['id']]);

            $this->jsonResponse(200, [
                'sucesso' => true,
                'token'   => $token,
                'usuario' => ['id' => $usuario['id'], 'nome' => $usuario['nome']],
            ]);

        } catch (InvalidArgumentException $e) {
            $this->jsonResponse(401, ['sucesso' => false, 'erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            $this->jsonResponse($e->getCode() ?: 500, ['sucesso' => false, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * POST /auth/registrar
     */
    public function registrar(array $payload): void
    {
        try {
            $id = $this->usuarioModel->registrar($payload);
            $this->jsonResponse(201, ['sucesso' => true, 'mensagem' => 'Usuario criado.', 'id' => $id]);
        } catch (InvalidArgumentException $e) {
            $this->jsonResponse(400, ['sucesso' => false, 'erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            $code = $e->getCode() ?: 500;
            $this->jsonResponse($code, ['sucesso' => false, 'erro' => $e->getMessage()]);
        }
    }

    private function jsonResponse(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
