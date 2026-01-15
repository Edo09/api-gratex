---
description: Initialize the development environment for api-gratex
---

# Initialize Development Environment

This workflow sets up your local development environment for the **api-gratex** PHP REST API application.

## Current Configuration

The application is currently configured to connect to:
- **Database**: `test`
- **Host**: `localhost:3306`
- **User**: `root`
- **Password**: (empty)

## Prerequisites

### ✅ Already Installed
- **PHP 8.5.1** - Verified and ready to use

### ⚠️ Required Installation

#### 1. MySQL/MariaDB Database Server

**Option A: Install XAMPP (Recommended for Windows)**
- Download from: https://www.apachefriends.org/
- XAMPP includes Apache, MySQL, and phpMyAdmin
- After installation, start MySQL from the XAMPP Control Panel

**Option B: Install MySQL Standalone**
- Download MySQL Community Server: https://dev.mysql.com/downloads/mysql/
- Or download MariaDB: https://mariadb.org/download/

**Option C: Use Existing Installation**
- If you already have MySQL/MariaDB, ensure it's running
- Add MySQL to your PATH or use the full path to mysql.exe

#### 2. Composer (Optional)
- The project has a `vendor` directory, suggesting dependencies may already be included
- If needed, download from: https://getcomposer.org/download/

## Setup Steps

### 1. Verify PHP Installation
// turbo
```powershell
php --version
```

### 2. Set Up Database

**Option A: Using phpMyAdmin (if using XAMPP)**
1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Click "Import" tab
3. Choose file: `db/database.sql`
4. Click "Go" to import

**Option B: Using MySQL Command Line**
If MySQL is in your PATH:
```powershell
mysql -u root < db/database.sql
```

If MySQL is not in PATH (adjust path to your MySQL installation):
```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root < db/database.sql
```

**Option C: Manual Import**
The database.sql file will automatically:
- Create the `test` database
- Create all required tables: `users`, `clients`, `cotizaciones`, `facturas`, `api_tokens`
- Populate with sample data

### 3. Verify Database Connection

The database settings are in `src/Database.php`:
- If you set a root password during MySQL installation, update line 13 in `src/Database.php`
- Default settings should work for most local installations

### 4. Start PHP Development Server
// turbo
```powershell
php -S localhost:8000
```

### 5. Test the API

**Test in browser**: http://localhost:8000

**Test with PowerShell**:
```powershell
curl http://localhost:8000
```

**Test specific endpoints** (see src/Router.php for all available routes):
- `GET /users` - List all users
- `GET /clients` - List all clients
- `GET /cotizaciones` - List all quotations
- More endpoints available in the router

## Troubleshooting

**Issue**: "Database connection failed"
- Verify MySQL is running
- Check credentials in `src/Database.php`
- Verify database `test` exists

**Issue**: "Port 8000 is already in use"
- Stop other applications using port 8000
- Or use a different port: `php -S localhost:8080`

**Issue**: MySQL not found
- Install MySQL/MariaDB (see Prerequisites above)
- Or add MySQL bin directory to your PATH

## Next Steps

Once the server is running:
1. Review available API endpoints in `src/Router.php`
2. Test authentication endpoints with the sample users (password for all test users: `password123`)
3. Explore the test suite in `tests/` directory
4. Check out the sample data in the database
