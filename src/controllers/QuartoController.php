<?php
declare(strict_types=1);

namespace HotelReservas\Controllers;

use HotelReservas\Config\Logger;
use HotelReservas\Models\QuartoModel;
use InvalidArgumentException;

class QuartoController
{
    private QuartoModel $quartoModel;

    public function __construct()
    {
        $this->quartoModel = new QuartoModel();
    }

    /**
     * GET /quartos?checkin=YYYY-MM-DD&checkout=YYYY-MM-DD&hospedes=2
     */
    public function listarDisponiveis(): void
    {
        try {
            $checkin  = filter_input(INPUT_GET, 'checkin',  FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
            $checkout = filter_input(INPUT_GET, 'checkout', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
            $hospedes = (int) filter_input(INPUT_GET, 'hospedes', FILTER_VALIDATE_INT) ?: 1;

            if ($checkin && $checkout) {
                $quartos = $this->quartoModel->listarDisponiveis($checkin, $checkout, $hospedes);
            } else {
                $quartos = $this->quartoModel->listarTodos();
            }

            $this->jsonResponse(200, ['sucesso' => true, 'dados' => $quartos]);

        } catch (InvalidArgumentException $e) {
            $this->jsonResponse(400, ['sucesso' => false, 'erro' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Logger::error('Erro ao listar quartos', ['error' => $e->getMessage()]);
            $this->jsonResponse(500, ['sucesso' => false, 'erro' => 'Erro interno.']);
        }
    }

    private function jsonResponse(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
