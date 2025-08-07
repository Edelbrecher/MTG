# MTG Collection Manager - Docker Setup

This project includes Docker configuration for easy deployment and development.

## Prerequisites

- Docker
- Docker Compose

## Quick Start

1. **Clone the repository** (if not already done)
```bash
git clone <your-repo-url>
cd MTG
```

2. **Build and start the containers**
```bash
docker-compose up -d --build
```

## Architecture

The application now uses a **Nginx + Apache architecture**:

```
Internet → Nginx (Port 8080) → Apache (Unix Socket) → PHP Application
                ↓
            Static Files served directly by Nginx
```

**Benefits:**
- Better performance for static files (CSS, JS, images)
- Nginx handles SSL termination and security headers
- Apache focuses on PHP processing
- Unix socket communication reduces network overhead

### Access Points
### Access Points
- Main application: http://localhost:8080 (via Nginx)
- phpMyAdmin: http://localhost:8081

4. **Database setup**
The database will be automatically initialized with the schema from `database/setup.sql`.

## Services

### Nginx Reverse Proxy
- **Port**: 8080
- **Container**: mtg_nginx
- **Purpose**: Serves static files and proxies PHP requests to Apache via Unix socket
- **Features**: 
  - Static file caching
  - Security headers
  - Gzip compression

### Web Server (PHP + Apache)
- **Container**: mtg_web
- **Communication**: Unix socket (/var/run/apache/apache.sock)
- **PHP Version**: 8.2
- **Features**: 
  - Apache with mod_rewrite enabled
  - PHP extensions: PDO MySQL, GD, mbstring
  - allow_url_fopen enabled for Scryfall API
  - Socket-based communication with Nginx

### MySQL Database
- **Port**: 3306
- **Container**: mtg_db
- **Database**: mtg_collection
- **User**: mtg_user
- **Password**: mtg_password
- **Root Password**: root_password

### phpMyAdmin
- **Port**: 8081
- **Container**: mtg_phpmyadmin
- **Access**: http://localhost:8081

## Configuration

### Environment Variables
The docker-compose.yml file sets up the following environment variables:
- `DB_HOST=db`
- `DB_NAME=mtg_collection`
- `DB_USER=mtg_user`
- `DB_PASSWORD=mtg_password`

### Database Configuration
For Docker deployment, use `config/database_docker.php` instead of the regular database config:

```php
// In your PHP files, check if running in Docker
if (getenv('DB_HOST')) {
    require_once 'config/database_docker.php';
} else {
    require_once 'config/database.php';
}
```

## Commands

### Start containers
```bash
docker-compose up -d
```

### Stop containers
```bash
docker-compose down
```

### View logs
```bash
docker-compose logs -f web
docker-compose logs -f db
```

### Rebuild containers
```bash
docker-compose down
docker-compose up -d --build
```

### Access container shell
```bash
docker exec -it mtg_web bash
docker exec -it mtg_db mysql -u mtg_user -p
```

### Backup database
```bash
docker exec mtg_db mysqldump -u mtg_user -pmtg_password mtg_collection > backup.sql
```

### Restore database
```bash
docker exec -i mtg_db mysql -u mtg_user -pmtg_password mtg_collection < backup.sql
```

## Development

### File Synchronization
The current directory is mounted as a volume, so changes to your code will be immediately reflected in the container.

### Database Persistence
Database data is stored in a Docker volume `mysql_data`, so it will persist between container restarts.

### PHP Configuration
Custom PHP settings are in `docker/php.ini` and will be applied to the container.

## Production Deployment

For production deployment, consider:

1. **Change default passwords** in docker-compose.yml
2. **Use environment files** for sensitive data
3. **Add SSL/HTTPS** configuration
4. **Set up proper backup strategy**
5. **Configure log rotation**
6. **Add health checks**

### Environment File (.env)
Create a `.env` file for production:
```
DB_HOST=db
DB_NAME=mtg_collection
DB_USER=your_secure_user
DB_PASSWORD=your_secure_password
MYSQL_ROOT_PASSWORD=your_secure_root_password
```

Then update docker-compose.yml to use environment files:
```yaml
env_file:
  - .env
```

## Troubleshooting

### Port conflicts
If ports 8080, 8081, or 3306 are already in use, change them in docker-compose.yml:
```yaml
ports:
  - "8090:80"  # Change 8080 to 8090
```

### Permission issues
If you encounter permission issues:
```bash
sudo chown -R $USER:$USER .
docker-compose down
docker-compose up -d --build
```

### Database connection issues
Check if the database container is running:
```bash
docker-compose ps
docker-compose logs db
```

### Clear all data and restart
```bash
docker-compose down -v
docker-compose up -d --build
```

## File Structure

```
MTG/
├── Dockerfile                 # Apache + PHP container
├── docker-compose.yml        # Multi-container setup with Nginx
├── docker/
│   ├── apache-config.conf     # Apache socket configuration
│   ├── nginx.conf            # Nginx reverse proxy config
│   ├── php.ini               # PHP configuration
│   └── .dockerignore         # Docker ignore file
├── config/
│   ├── database.php          # Local database config
│   └── database_docker.php   # Docker database config
└── database/
    └── setup.sql             # Database initialization
```
