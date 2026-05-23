<?php
declare(strict_types=1);

namespace HotelReservas\Controllers;

use HotelReservas\Config\Logger;
use HotelReservas\Models\ReservaModel;
use HotelReservas\Models\QuartoModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Controller de Reservas — camada HTTP.
 *
 * Responsabilidade: receber request, delegar ao Model, retornar response.
 */
class ReservaController
{
    private ReservaModel $reservaModel;

    public function __construct()
    {
        $this->reservaModel = new ReservaModel();
    }

    /**
     * POST /reservas
     * Cria nova reserva com pagamento (transacional).
     */
    public function criar(array $payload, int $usuarioAutenticado): void
    {
        try {
            $payload['usuario_id'] = $usuarioAutenticado;

            $resultado = $this->reservaModel->criarComPagamento($payload);

            $this->jsonResponse(201, [
                'sucesso'    => true,
                'mensagem'   => 'Reserva criada com sucesso.',
                'reserva_id' => $resultado['reserva_id'],
                'valor_total'=> $resultado['valor_total'],
            ]);

        } catch (InvalidArgumentException $e) {
            $this->jsonResponse(400, ['sucesso' => false, 'erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            $code = $e->getCode() ?: 500;
            $this->jsonResponse($code, ['sucesso' => false, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * GET /reservas
     * Lista reservas do usuario autenticado.
     */
    public function listar(int $usuarioAutenticado): void
    {
        try {
            $status   = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
            $reservas = $this->reservaModel->listarPorUsuario($usuarioAutenticado, $status);

            $this->jsonResponse(200, ['sucesso' => true, 'dados' => $reservas]);

        } catch (InvalidArgumentException $e) {
            $this->jsonResponse(400, ['sucesso' => false, 'erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            Logger::error('Erro ao listar reservas', ['error' => $e->getMessage()]);
            $this->jsonResponse(500, ['sucesso' => false, 'erro' => 'Erro interno.']);
        }
    }

    /**
     * GET /reservas/{id}
     */
    public function buscar(int $id, int $usuarioAutenticado): void
    {
        try {
            $reserva = $this->reservaModel->buscarPorId($id);

            if (!$reserva || (int) $reserva['usuario_id'] !== $usuarioAutenticado) {
                $this->jsonResponse(404, ['sucesso' => false, 'erro' => 'Reserva não encontrada.']);
                return;
            }

            $this->jsonResponse(200, ['sucesso' => true, 'dados' => $reserva]);

        } catch (RuntimeException $e) {
            Logger::error('Erro ao buscar reserva', ['error' => $e->getMessage(), 'id' => $id]);
            $this->jsonResponse(500, ['sucesso' => false, 'erro' => 'Erro interno.']);
        }
    }

    /**
     * DELETE /reservas/{id}
     */
    public function cancelar(int $id, int $usuarioAutenticado): void
    {
        try {
            $this->reservaModel->cancelar($id, $usuarioAutenticado);
            $this->jsonResponse(200, ['sucesso' => true, 'mensagem' => 'Reserva cancelada.']);

        } catch (InvalidArgumentException $e) {
            $this->jsonResponse(400, ['sucesso' => false, 'erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            Logger::error('Erro ao cancelar', ['error' => $e->getMessage()]);
            $this->jsonResponse(500, ['sucesso' => false, 'erro' => 'Erro interno.']);
        }
    }

    private function jsonResponse(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        // Nunca expoe stack traces ao cliente
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
