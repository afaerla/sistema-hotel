<?php
declare(strict_types=1);

namespace HotelReservas\Models;

use HotelReservas\Config\Database;
use HotelReservas\Config\Logger;
use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;

/**
 * Modelo de Reservas.
 *
 * Implementa operacoes transacionais reais para garantir
 * consistencia entre reservas e pagamentos.
 *
 * SEGURANCA: Todos os inputs passam por prepared statements PDO.
 */
class ReservaModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // =========================================================
    // TRANSACAO PRINCIPAL: Criar reserva + pagamento atomicamente
    // =========================================================

    /**
     * Cria uma reserva e registra o pagamento em uma unica transacao.
     *
     * Se qualquer etapa falhar, ROLLBACK automatico garante que
     * nenhum dado parcial seja persistido.
     *
     * @throws RuntimeException em caso de conflito de disponibilidade
     * @throws PDOException     em caso de falha no banco
     */
    public function criarComPagamento(array $dados): array
    {
        $this->validarDadosReserva($dados);

        $this->db->beginTransaction();

        try {
            // 1. Verifica disponibilidade com bloqueio pessimista (FOR UPDATE)
            $this->verificarDisponibilidade(
                $dados['quarto_id'],
                $dados['data_checkin'],
                $dados['data_checkout']
            );

            // 2. Calcula valor total
            $valorTotal = $this->calcularValorTotal(
                $dados['quarto_id'],
                $dados['data_checkin'],
                $dados['data_checkout']
            );

            // 3. Insere a reserva
            $reservaId = $this->inserirReserva($dados, $valorTotal);

            // 4. Registra o pagamento vinculado
            $this->inserirPagamento($reservaId, $valorTotal, $dados['metodo_pagamento']);

            // 5. Confirma a reserva pagamento registrado
            $this->atualizarStatus($reservaId, 'confirmada');

            // 6. COMMIT — tudo certo
            $this->db->commit();

            Logger::info('Reserva criada com sucesso', [
                'reserva_id' => $reservaId,
                'quarto_id'  => $dados['quarto_id'],
                'usuario_id' => $dados['usuario_id'],
            ]);

            return ['reserva_id' => $reservaId, 'valor_total' => $valorTotal];

        } catch (\Throwable $e) {
            // ROLLBACK em qualquer falha — atomicidade garantida
            $this->db->rollBack();

            Logger::error('Falha ao criar reserva — rollback executado', [
                'error'      => $e->getMessage(),
                'quarto_id'  => $dados['quarto_id'] ?? null,
                'usuario_id' => $dados['usuario_id'] ?? null,
            ]);

            // Relanca excecoes de negocio; encapsula erros tecnicos
            if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new RuntimeException('Erro ao processar reserva. Tente novamente.', 500, $e);
        }
    }

    // =========================================================
    // TRANSACAO: Cancelar reserva + estornar pagamento
    // =========================================================

    public function cancelar(int $reservaId, int $usuarioId): void
    {
        $this->db->beginTransaction();

        try {
            // Verifica se pertence ao usuario e esta� cancelavel
            $stmt = $this->db->prepare(
                'SELECT id, status, usuario_id FROM reservas
                  WHERE id = :id AND usuario_id = :usuario_id
                  FOR UPDATE'
            );
            $stmt->execute([':id' => $reservaId, ':usuario_id' => $usuarioId]);
            $reserva = $stmt->fetch();

            if (!$reserva) {
                throw new InvalidArgumentException('Reserva nao encontrada ou sem permissao.');
            }

            if (!in_array($reserva['status'], ['pendente', 'confirmada'])) {
                throw new InvalidArgumentException("Reserva com status '{$reserva['status']}' nao pode ser cancelada.");
            }

            // Cancela a reserva
            $stmt = $this->db->prepare(
                'UPDATE reservas SET status = :status WHERE id = :id'
            );
            $stmt->execute([':status' => 'cancelada', ':id' => $reservaId]);

            // Estorna pagamentos aprovados
            $stmt = $this->db->prepare(
                'UPDATE pagamentos SET status = :status
                  WHERE reserva_id = :reserva_id AND status = :aprovado'
            );
            $stmt->execute([
                ':status'     => 'estornado',
                ':reserva_id' => $reservaId,
                ':aprovado'   => 'aprovado',
            ]);

            $this->db->commit();

            Logger::info('Reserva cancelada', ['reserva_id' => $reservaId, 'usuario_id' => $usuarioId]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            Logger::error('Falha ao cancelar reserva', ['error' => $e->getMessage(), 'reserva_id' => $reservaId]);

            if ($e instanceof InvalidArgumentException) throw $e;
            throw new RuntimeException('Erro ao cancelar reserva.', 500, $e);
        }
    }

    // =========================================================
    // LEITURA: Busca de reservas (prepared statements)
    // =========================================================

    public function listarPorUsuario(int $usuarioId, string $status = ''): array
    {
        $sql = 'SELECT r.id, r.data_checkin, r.data_checkout, r.status,
                       r.valor_total, r.num_hospedes,
                       q.numero AS quarto_numero, q.tipo AS quarto_tipo
                  FROM reservas r
                  JOIN quartos q ON q.id = r.quarto_id
                 WHERE r.usuario_id = :usuario_id';

        $params = [':usuario_id' => $usuarioId];

        // Filtro opcional por status — valor validado antes de usar
        if ($status !== '') {
            $this->validarStatus($status);
            $sql .= ' AND r.status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY r.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, q.numero AS quarto_numero, q.tipo, q.preco_diaria,
                    u.nome AS usuario_nome, u.email AS usuario_email
               FROM reservas r
               JOIN quartos q ON q.id = r.quarto_id
               JOIN usuarios u ON u.id = r.usuario_id
              WHERE r.id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function quartoDisponivel(int $quartoId, string $checkin, string $checkout): bool
    {
        try {
            $this->verificarDisponibilidade($quartoId, $checkin, $checkout);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    // =========================================================
    // PRIVADOS — helpers internos
    // =========================================================

    private function verificarDisponibilidade(int $quartoId, string $checkin, string $checkout): void
    {
        // Bloqueia linhas conflitantes com FOR UPDATE (evita race condition)
        $stmt = $this->db->prepare(
            'SELECT id FROM reservas
              WHERE quarto_id = :quarto_id
                AND status NOT IN (\'cancelada\')
                AND data_checkin  < :checkout
                AND data_checkout > :checkin
              FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([
            ':quarto_id' => $quartoId,
            ':checkin'   => $checkin,
            ':checkout'  => $checkout,
        ]);

        if ($stmt->fetch()) {
            throw new RuntimeException("Quarto indisponivel para o periodo solicitado.", 409);
        }
    }

    private function calcularValorTotal(int $quartoId, string $checkin, string $checkout): float
    {
        $stmt = $this->db->prepare('SELECT preco_diaria FROM quartos WHERE id = :id AND ativo = TRUE');
        $stmt->execute([':id' => $quartoId]);
        $quarto = $stmt->fetch();

        if (!$quarto) {
            throw new InvalidArgumentException("Quarto nao encontrado ou inativo.");
        }

        $dias = (new \DateTime($checkout))->diff(new \DateTime($checkin))->days;
        return round((float) $quarto['preco_diaria'] * $dias, 2);
    }

    private function inserirReserva(array $dados, float $valorTotal): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO reservas (usuario_id, quarto_id, data_checkin, data_checkout,
                                   num_hospedes, valor_total, status, observacoes)
             VALUES (:usuario_id, :quarto_id, :checkin, :checkout,
                    :num_hospedes, :valor_total, \'pendente\', :obs)
             RETURNING id'
        );
        $stmt->execute([
            ':usuario_id'  => $dados['usuario_id'],
            ':quarto_id'   => $dados['quarto_id'],
            ':checkin'     => $dados['data_checkin'],
            ':checkout'    => $dados['data_checkout'],
            ':num_hospedes'=> $dados['num_hospedes'] ?? 1,
            ':valor_total' => $valorTotal,
            ':obs'         => $dados['observacoes'] ?? null,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function inserirPagamento(int $reservaId, float $valor, string $metodo): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO pagamentos (reserva_id, valor, metodo, status)
             VALUES (:reserva_id, :valor, :metodo, \'aprovado\')'
        );
        $stmt->execute([
            ':reserva_id' => $reservaId,
            ':valor'      => $valor,
            ':metodo'     => $metodo,
        ]);
    }

    private function atualizarStatus(int $reservaId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE reservas SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $reservaId]);
    }

    private function validarDadosReserva(array $dados): void
    {
        $obrigatorios = ['usuario_id', 'quarto_id', 'data_checkin', 'data_checkout', 'metodo_pagamento'];
        foreach ($obrigatorios as $campo) {
            if (empty($dados[$campo])) {
                throw new InvalidArgumentException("Campo obrigatorio ausente: {$campo}");
            }
        }

        if (strtotime($dados['data_checkout']) <= strtotime($dados['data_checkin'])) {
            throw new InvalidArgumentException("Data de checkout deve ser posterior ao checkin.");
        }

        if (strtotime($dados['data_checkin']) < strtotime('today')) {
            throw new InvalidArgumentException("Data de checkin nao pode ser no passado.");
        }

        $metodosValidos = ['cartao_credito', 'cartao_debito', 'pix', 'boleto', 'dinheiro'];
        if (!in_array($dados['metodo_pagamento'], $metodosValidos, true)) {
            throw new InvalidArgumentException("Metodo de pagamento invalido.");
        }
    }

    private function validarStatus(string $status): void
    {
        $validos = ['pendente', 'confirmada', 'cancelada', 'concluida'];
        if (!in_array($status, $validos, true)) {
            throw new InvalidArgumentException("Status invalido: {$status}");
        }
    }
}
