# AI Coding Agent Instructions - testAPI

## Architecture Overview

This is a scalable REST API for user management using PHP with MySQL and token-based authentication. The architecture follows MVC pattern with token-based security:

- **Router**: [src/Router.php](../src/Router.php) - routes requests to appropriate controllers (auth vs user endpoints)
- **Auth Controller**: [src/Controllers/authController.php](../src/Controllers/authController.php) - generates and revokes API tokens
- **Auth Model**: [src/Models/authModel.php](../src/Models/authModel.php) - manages tokens in database, validates tokens
- **Auth Middleware**: [src/Middleware/AuthMiddleware.php](../src/Middleware/AuthMiddleware.php) - validates X-API-KEY header on protected routes
- **User Controller**: [src/Controllers/userController.php](../src/Controllers/userController.php) - handles CRUD operations (requires token)
- **User Model**: [src/Models/userModel.php](../src/Models/userModel.php) - manages user data persistence
- **Token Generator**: [src/Utils/TokenGenerator.php](../src/Utils/TokenGenerator.php) - generates secure API tokens
- **Entry Point**: [index.php](../index.php) - bootstrapper that includes the router
- **Database**: [db/database.sql](../db/database.sql) - `test` database with `users` and `api_tokens` tables

**Data Flow**: HTTP request → Router → Auth middleware (validates token) → Controller (CRUD/Auth) → Model (SQL) → JSON response

## API Endpoints

### Authentication Endpoints (No token required)

**POST** `/index.php` or `/api/auth` - Generate API token
```json
Request Body:
{
  "action": "generate_token",
  "user_id": 1
}

Response:
["success", "32-character-token-here"]
```

**GET** `/api/auth?user_id=<user_id>` - List all tokens for a user
```json
Response:
[
  "success",
  [
    {
      "id": 1,
      "created_at": "2024-03-01 10:30:00",
      "last_used": "2024-03-01 11:45:00",
      "is_active": 1
    }
  ]
]
```

**DELETE** `/api/auth` - Revoke a token
```json
Request Body:
{
  "token_id": 1
}

Response:
["success", "Token revoked"]
```

### Protected User CRUD Endpoints (Require X-API-KEY header)

All user endpoints require header: `X-API-KEY: <token>`

**GET** `/index.php?id=<optional_id>` - List users or get specific user
```
Response: Array of user objects [id, name, last_name, email]
```

**POST** `/index.php` - Create user
```json
Request Body:
{
  "name": "string (max 80)",
  "last_name": "string (max 80)",
  "email": "valid email (max 50)"
}

Response:
["success", "User saved"]
```

**PUT** `/index.php` - Update user
```json
Request Body:
{
  "id": "required",
  "name": "string (max 80)",
  "last_name": "string (max 80)",
  "email": "valid email (max 50)"
}

Response:
["success", "User updated"]
```

**DELETE** `/index.php` - Delete user
```json
Request Body:
{
  "id": "required"
}

Response:
["success", "User deleted"]
```

## Authentication Flow

1. **Token Generation**: Send POST request to auth endpoint with user_id
2. **Receive Token**: Get 32-character secure token from response
3. **Use Token**: Include token in all CRUD requests via `X-API-KEY: <token>` header
4. **Token Validation**: AuthMiddleware validates token in api_tokens table
5. **Token Revocation**: Send DELETE request to auth endpoint with token_id to disable token

## Critical Implementation Details

### Token Management
- Tokens are stored as SHA256 hashes in database for security
- [TokenGenerator.php](../src/Utils/TokenGenerator.php) provides three methods:
  - `generateApiToken()` - creates new 32-character token
  - `hashToken($token)` - converts to SHA256 for storage
  - `verifyToken($token, $hash)` - securely compares token to hash
- Each token tracks: creation date, last use, and active status
- Tokens are linked to users via foreign key (cascade delete)

### Authentication Patterns
- All protected endpoints check token via [AuthMiddleware.php](../src/Middleware/AuthMiddleware.php)
- Token extracted from `X-API-KEY` header
- Invalid/inactive tokens return 401 response
- `last_used` timestamp updates automatically on each request

### Input Handling Pattern
- Request data arrives as JSON in request body
- Parse using `json_decode(file_get_contents('php://input', true))`
- Validation: null checks → empty trim → length limits → format-specific (email)
- Auth endpoint validates `user_id` exists before token generation

### Database Schema
- `users` table: id, name, last_name, email
- `api_tokens` table: id, user_id (FK), token_hash, created_at, last_used, is_active
- Tokens cascade delete when user is deleted

## Common Patterns to Follow

1. **Adding protected endpoint**: Require auth middleware validation (already in userController template)
2. **Adding new auth endpoint**: Add case in authController switch, validate inputs, call authModel method
3. **New database field**: Add to SQL schema, add validation in controller, add to model queries
4. **Security**: Always hash tokens before storage, use `hash_equals()` for comparison to prevent timing attacks

## Project Setup & Deployment

1. **Database Setup**: 
   - Import [db/database.sql](../db/database.sql) to create tables
   - Ensure `api_tokens` table exists with foreign key to `users`
2. **Configuration**: 
   - Update mysqli parameters in [src/Models/userModel.php](../src/Models/userModel.php) and [src/Models/authModel.php](../src/Models/authModel.php) line 7 if using different server
3. **Hosting**: Requires PHP-enabled web server (Apache/Nginx with PHP)
4. **Testing**: Generate token first via auth endpoint, then use token in CRUD requests

## Scalability Patterns

This architecture scales to additional CRUD resources:
1. Create new Model file: `src/Models/resourceModel.php` (query your resource)
2. Create new Controller: `src/Controllers/resourceController.php` (validate input + auth middleware)
3. Update [src/Router.php](../src/Router.php) to route `/api/resources` to your controller
4. All controllers automatically inherit token validation from AuthMiddleware

## Security Considerations

- ⚠️ No prepared statements (vulnerable to SQL injection) - add parameterized queries in future
- ⚠️ Tokens stored as SHA256 hashes (not reversible, good for storage)
- ✓ Token validation uses `hash_equals()` to prevent timing attacks
- ✓ Tokens can be revoked/deactivated without database deletion
- ✓ Token tracking includes last_used for audit/monitoring

