<?php
class TokenGenerator
{
    /**
     * Generate a secure API token
     * @param int $length Token length (default 32 characters)
     * @return string Generated token
     */
    public static function generateApiToken($length = 32)
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Hash a token for storage (optional security layer)
     * @param string $token Token to hash
     * @return string Hashed token
     */
    public static function hashToken($token)
    {
        return hash('sha256', $token);
    }

    /**
     * Verify a token against its hash
     * @param string $token Raw token
     * @param string $hash Stored hash
     * @return bool True if token matches hash
     */
    public static function verifyToken($token, $hash)
    {
        return hash_equals($hash, self::hashToken($token));
    }
}
