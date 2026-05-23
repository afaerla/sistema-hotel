<?php
declare(strict_types=1);

namespace HotelReservas\Middleware;

use HotelReservas\Config\Logger;
use RuntimeException;
use InvalidArgumentException;

/**
 * Autenticao JWT simples sem dependencias externas.
 * Implementa HS256 (HMAC-SHA256).
 */
class JwtAuth
{
    private static function getSecret(): string
    {
        $secret = getenv('JWT_SECRET');
        if (!$secret) {
            throw new RuntimeException('JWT_SECRET não configurado');
        }
        return $secret;
    }

    /**
     * Gera um token JWT assinado com HS256.
     */
    public static function generate(array $payload): string
    {
        $expiry  = (int) (getenv('JWT_EXPIRY') ?: 3600);
        $header  = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiry;
        $body    = self::base64UrlEncode(json_encode($payload));
        $sig     = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$body}", self::getSecret(), true));
        return "{$header}.{$body}.{$sig}";
    }

    /**
     * Valida e decodifica um token JWT.
     * Lanca excecao em caso de token invalido ou expirado.
     */
    public static function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Token malformado');
        }

        [$header, $body, $sig] = $parts;
        $expectedSig = self::base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$body}", self::getSecret(), true)
        );

        // Comparacao timing-safe para evitar timing attacks
        if (!hash_equals($expectedSig, $sig)) {
            Logger::warning('Token JWT com assinatura inválida');
            throw new InvalidArgumentException('Token inválido');
        }

        $payload = json_decode(self::base64UrlDecode($body), true);

        if (!$payload || !isset($payload['exp'])) {
            throw new InvalidArgumentException('Payload inválido');
        }

        if ($payload['exp'] < time()) {
            throw new InvalidArgumentException('Token expirado');
        }

        return $payload;
    }

    /**
     * Extrai o token do header Authorization: Bearer <token>
     */
    public static function fromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
