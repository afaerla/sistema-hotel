<?php
declare(strict_types=1);

namespace HotelReservas\Models;

use HotelReservas\Config\Database;
use PDO;
use InvalidArgumentException;

/**
 * Modelo de Quartos — consultas e verificacao de disponibilidade.
 */
class QuartoModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function listarDisponiveis(string $checkin, string $checkout, int $hospedes = 1): array
    {
        if (strtotime($checkout) <= strtotime($checkin)) {
            throw new InvalidArgumentException('Período inválido.');
        }

        // Subquery usa prepared statement — zero risco de SQL Injection
        $stmt = $this->db->prepare(
            'SELECT q.id, q.numero, q.tipo, q.capacidade, q.preco_diaria, q.descricao
               FROM quartos q
              WHERE q.ativo = TRUE
                AND q.capacidade >= :hospedes
                AND q.id NOT IN (
                    SELECT r.quarto_id
                      FROM reservas r
                     WHERE r.status NOT IN (\'cancelada\')
                       AND r.data_checkin  < :checkout
                       AND r.data_checkout > :checkin
                )
              ORDER BY q.preco_diaria ASC'
        );

        $stmt->execute([
            ':hospedes' => $hospedes,
            ':checkin'  => $checkin,
            ':checkout' => $checkout,
        ]);

        return $stmt->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, numero, tipo, capacidade, preco_diaria, descricao, ativo
               FROM quartos WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function listarTodos(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, numero, tipo, capacidade, preco_diaria, descricao
               FROM quartos WHERE ativo = TRUE ORDER BY numero'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
