-- =============================================================
-- Sistema de Reservas de Hotel - Schema PostgreSQL
-- =============================================================

-- Extensões úteis
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- =============================================================
-- TABELA: usuarios
-- =============================================================
CREATE TABLE IF NOT EXISTS usuarios (
    id          SERIAL PRIMARY KEY,
    nome        VARCHAR(150) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    senha_hash  VARCHAR(255) NOT NULL,
    telefone    VARCHAR(20),
    cpf         VARCHAR(14) UNIQUE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ativo       BOOLEAN DEFAULT TRUE,
    CONSTRAINT chk_email CHECK (email ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')
);

-- =============================================================
-- TABELA: quartos
-- =============================================================
CREATE TABLE IF NOT EXISTS quartos (
    id          SERIAL PRIMARY KEY,
    numero      VARCHAR(10) NOT NULL UNIQUE,
    tipo        VARCHAR(50) NOT NULL,
    capacidade  SMALLINT NOT NULL DEFAULT 2,
    preco_diaria NUMERIC(10,2) NOT NULL,
    descricao   TEXT,
    ativo       BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_tipo CHECK (tipo IN ('solteiro', 'casal', 'suite', 'familia', 'presidencial')),
    CONSTRAINT chk_capacidade CHECK (capacidade > 0 AND capacidade <= 10),
    CONSTRAINT chk_preco CHECK (preco_diaria > 0)
);

-- =============================================================
-- TABELA: reservas
-- =============================================================
CREATE TABLE IF NOT EXISTS reservas (
    id              SERIAL PRIMARY KEY,
    usuario_id      INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE RESTRICT,
    quarto_id       INTEGER NOT NULL REFERENCES quartos(id) ON DELETE RESTRICT,
    data_checkin    DATE NOT NULL,
    data_checkout   DATE NOT NULL,
    num_hospedes    SMALLINT NOT NULL DEFAULT 1,
    status          VARCHAR(20) NOT NULL DEFAULT 'pendente',
    valor_total     NUMERIC(10,2) NOT NULL,
    observacoes     TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_datas CHECK (data_checkout > data_checkin),
    CONSTRAINT chk_status CHECK (status IN ('pendente', 'confirmada', 'cancelada', 'concluida')),
    CONSTRAINT chk_hospedes CHECK (num_hospedes > 0)
);

-- =============================================================
-- TABELA: pagamentos
-- =============================================================
CREATE TABLE IF NOT EXISTS pagamentos (
    id              SERIAL PRIMARY KEY,
    reserva_id      INTEGER NOT NULL REFERENCES reservas(id) ON DELETE RESTRICT,
    valor           NUMERIC(10,2) NOT NULL,
    metodo          VARCHAR(30) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pendente',
    transacao_id    VARCHAR(100),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_metodo CHECK (metodo IN ('cartao_credito', 'cartao_debito', 'pix', 'boleto', 'dinheiro')),
    CONSTRAINT chk_status_pag CHECK (status IN ('pendente', 'aprovado', 'recusado', 'estornado')),
    CONSTRAINT chk_valor_pag CHECK (valor > 0)
);

-- =============================================================
-- TABELA: logs_auditoria
-- =============================================================
CREATE TABLE IF NOT EXISTS logs_auditoria (
    id          BIGSERIAL PRIMARY KEY,
    tabela      VARCHAR(50) NOT NULL,
    operacao    VARCHAR(10) NOT NULL,
    usuario_id  INTEGER REFERENCES usuarios(id),
    dados_antes JSONB,
    dados_apos  JSONB,
    ip_origem   VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================
-- ÍNDICES EXPLÍCITOS (Performance)
-- =============================================================

-- Índice para buscas de reservas por período (range scan)
CREATE INDEX idx_reservas_datas ON reservas (data_checkin, data_checkout);

-- Índice para filtrar reservas por status
CREATE INDEX idx_reservas_status ON reservas (status) WHERE status IN ('pendente', 'confirmada');

-- Índice para buscar reservas de um usuário específico
CREATE INDEX idx_reservas_usuario ON reservas (usuario_id, created_at DESC);

-- Índice para verificação de disponibilidade de quarto
CREATE INDEX idx_reservas_quarto_datas ON reservas (quarto_id, data_checkin, data_checkout)
    WHERE status NOT IN ('cancelada');

-- Índice para busca de pagamentos por reserva
CREATE INDEX idx_pagamentos_reserva ON pagamentos (reserva_id);

-- Índice composto para auditoria
CREATE INDEX idx_logs_tabela_data ON logs_auditoria (tabela, created_at DESC);

-- Índice para busca de usuário por email (login)
CREATE UNIQUE INDEX idx_usuarios_email ON usuarios (lower(email));

-- =============================================================
-- FUNCTION: atualizar updated_at automaticamente
-- =============================================================
CREATE OR REPLACE FUNCTION fn_atualiza_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_usuarios_updated_at
    BEFORE UPDATE ON usuarios
    FOR EACH ROW EXECUTE FUNCTION fn_atualiza_updated_at();

CREATE TRIGGER trg_reservas_updated_at
    BEFORE UPDATE ON reservas
    FOR EACH ROW EXECUTE FUNCTION fn_atualiza_updated_at();

-- =============================================================
-- DADOS INICIAIS (Seed)
-- =============================================================
INSERT INTO quartos (numero, tipo, capacidade, preco_diaria, descricao) VALUES
    ('101', 'solteiro',    1,  150.00, 'Quarto solteiro com cama de solteiro, TV e ar-condicionado'),
    ('102', 'solteiro',    1,  150.00, 'Quarto solteiro com vista para o jardim'),
    ('201', 'casal',       2,  250.00, 'Quarto casal com cama king size e banheira'),
    ('202', 'casal',       2,  250.00, 'Quarto casal com varanda e vista para piscina'),
    ('301', 'suite',       2,  450.00, 'Suíte com sala de estar, jacuzzi e minibar'),
    ('401', 'familia',     4,  380.00, 'Quarto família com 2 camas de casal e área kids'),
    ('501', 'presidencial',4,  950.00, 'Suíte presidencial com sala, cozinha e terraço privativo');

INSERT INTO usuarios (nome, email, senha_hash, telefone, cpf) VALUES
    ('Admin Hotel', 'admin@hotel.com', crypt('admin123', gen_salt('bf')), '(11) 99999-0001', '000.000.000-00');
