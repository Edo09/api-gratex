<?php
require_once(__DIR__ . '/../Models/authModel.php');

class AuthMiddleware
{
    private $authModel;

    public function __construct()
    {
        $this->authModel = new authModel();
    }

    /**
     * Validate API token from request header
     * Header format: X-API-KEY: <token> or Authorization: Bearer <token>
     * @return array ['valid' => bool, 'user_id' => int|null, 'message' => string]
     */
    public function validateRequest()
    {
        // Check for token in header
        $token = $this->getTokenFromHeader();

        if (!$token) {
            return [
                'valid' => false,
                'user_id' => null,
                'message' => 'API token is required. Use header: X-API-KEY: <token> or Authorization: Bearer <token>'
            ];
        }

        // Validate token
        $validation = $this->authModel->validateToken($token);

        if ($validation['valid']) {
            // Update last_used timestamp
            $token_hash = hash('sha256', $token);
            $this->authModel->updateLastUsed($token_hash);
            
            return [
                'valid' => true,
                'user_id' => $validation['user_id'],
                'message' => 'Token validated'
            ];
        }

        return [
            'valid' => false,
            'user_id' => null,
            'message' => 'Invalid or inactive API token'
        ];
    }

    /**
     * Extract token from X-API-KEY header or Authorization Bearer token
     * @return string|null Token or null if not present
     */
    private function getTokenFromHeader()
    {
        // Check for X-API-KEY header first
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        
        // Check for Authorization: Bearer token format using getallheaders() (more reliable)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    $auth_header = trim($value);
                    if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
                        return trim($matches[1]);
                    }
                }
            }
        } else {
            // Fallback for servers without getallheaders()
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth_header = trim($_SERVER['HTTP_AUTHORIZATION']);
                if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return null;
    }

    /**
     * Send unauthorized response and exit
     * @param string $message Error message
     */
    public function sendUnauthorized($message = 'Unauthorized')
    {
        http_response_code(401);
        echo json_encode(['status' => false, 'error' => $message]);
        exit;
    }
}
