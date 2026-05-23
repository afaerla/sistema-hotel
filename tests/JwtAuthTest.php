<?php
declare(strict_types=1);

namespace HotelReservas\Tests;

use HotelReservas\Middleware\JwtAuth;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

/**
 * Testes unitarios para autenticacao JWT.
 * Nao requer banco de dados.
 */
class JwtAuthTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('JWT_SECRET=test_secret_key_for_unit_tests');
        putenv('JWT_EXPIRY=3600');
    }

    public function testGerarEValidarTokenComSucesso(): void
    {
        $payload = ['sub' => 42, 'nome' => 'Teste', 'email' => 'teste@email.com'];
        $token   = JwtAuth::generate($payload);

        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);

        $decoded = JwtAuth::validate($token);
        $this->assertEquals(42, $decoded['sub']);
        $this->assertEquals('Teste', $decoded['nome']);
    }

    public function testTokenMalformadoLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JwtAuth::validate('token.invalido');
    }

    public function testTokenComAssinaturaInvalidaLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $partes    = explode('.', JwtAuth::generate(['sub' => 1]));
        $partes[2] = 'assinatura_falsa';
        JwtAuth::validate(implode('.', $partes));
    }

    public function testTokenExpiradoLancaExcecao(): void
    {
        putenv('JWT_EXPIRY=-1'); // expira imediatamente
        $token = JwtAuth::generate(['sub' => 1]);
        sleep(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expirado');
        JwtAuth::validate($token);
    }
}
