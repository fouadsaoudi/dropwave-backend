# 🚀 Socials Hub — Master Server Deployment Plan

> **For the Server AI Administrator:**  
> This file contains the complete, self-contained deployment blueprint to set up the **Socials Hub** platform on this VPS (`179.198.193.57`).  
> Read this document and execute the instructions below sequentially.

---

## 1. Target Directory Layout on This Server

All repositories are located inside `/var/www/html/`:

```
/var/www/html/
├── dropwave-backend/     # Backend Laravel API + Docker stack (MySQL, Redis, Reverb)
├── dropwave-dashboard/   # Admin & Agent React Dashboard (builds to ./dist)
└── dropwave-frontend/    # Marketing Website React SPA (builds to ./dist)
```

---

## 2. Domain & Subdomain Mapping

| Subdomain | Points To | Served By |
| :--- | :--- | :--- |
| **`api.socials-hub.com`** | `http://127.0.0.1:8000` | Host Nginx reverse proxy to Docker |
| **`app.socials-hub.com`** | `/var/www/html/dropwave-dashboard/dist` | Host Nginx static SPA |
| **`socials-hub.com`** | `/var/www/html/dropwave-frontend/dist` | Host Nginx static SPA |
| **`www.socials-hub.com`** | `/var/www/html/dropwave-frontend/dist` | Host Nginx static SPA |

---

## 3. Step-by-Step Execution Plan

### Step 1: System Packages & Dependencies

Run this on the host server:

```bash
# 1. Update package registry
apt-get update && apt-get install -y \
    curl git ufw nginx certbot python3-certbot-nginx unzip \
    ca-certificates gnupg lsb-release

# 2. Install Docker & Docker Compose Plugin (if not installed)
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
fi

# 3. Install Node.js 20 LTS & pnpm (for building frontend SPAs)
if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

if ! command -v pnpm &> /dev/null; then
    npm install -g pnpm
fi

# 4. Open essential ports on UFW firewall
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
```

---

### Step 2: Backend Configuration & Docker Launch

Navigate to the backend directory:
```bash
cd /var/www/html/dropwave-backend
```

#### A. Configure `/var/www/html/dropwave-backend/.env`
If `.env` doesn't exist, create it with:
```bash
cat << 'EOF' > /var/www/html/dropwave-backend/.env
APP_NAME="Socials Hub"
APP_ENV=production
APP_KEY=base64:9eUbNLsl8gNBUSEb8Fob1xLPCaqa/wJIPo8wOmuDS4A=
APP_DEBUG=false
APP_URL=https://api.socials-hub.com

LOG_CHANNEL=stack
LOG_LEVEL=info

# Internal Docker MySQL (socialshub-mysql)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=socialshub
DB_USERNAME=socialshub
DB_PASSWORD=SocialsHubSecretPass2026!
DB_ROOT_PASSWORD=SocialsHubRootPass2026!

# Internal Docker Redis (socialshub-redis)
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Laravel Reverb WebSockets (Managed by Supervisor inside socialshub-app)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=dropwave-app-id
REVERB_APP_KEY=dropwave-app-key
REVERB_APP_SECRET=dropwave-app-secret
REVERB_HOST="api.socials-hub.com"
REVERB_PORT=443
REVERB_SCHEME=https

# WebRTC COTURN Fallback
TURN_SERVER_REALM=turn.socials-hub.com
EOF
```

#### B. Launch Backend Docker Stack
```bash
cd /var/www/html/dropwave-backend
docker compose down
docker compose up -d --build
```

#### C. Run Migrations & Storage Link
```bash
# Allow MySQL 5-10 seconds to fully initialize
sleep 8

# Run database migrations
docker exec socialshub-app php artisan migrate --force

# Create public storage symlink
docker exec socialshub-app php artisan storage:link

# Cache config & routes for maximum production performance
docker exec socialshub-app php artisan config:cache
docker exec socialshub-app php artisan route:cache
docker exec socialshub-app php artisan view:cache

# Verify Supervisor processes (php-fpm, reverb, queue-1, queue-2, scheduler)
docker exec socialshub-app supervisorctl status
```

---

### Step 3: Build Frontends (Website & Dashboard)

#### A. Build Admin Dashboard (`dropwave-dashboard`)
```bash
cd /var/www/html/dropwave-dashboard

# Create .env.production for the dashboard
cat << 'EOF' > /var/www/html/dropwave-dashboard/.env.production
VITE_GOOGLE_MAPS_API_KEY=AIzaSyCsw2FOuLPNQLqMfZJGzJCDfwyQ8DbUizw
VITE_API_BASE_URL='https://api.socials-hub.com/api'
VITE_REVERB_HOST='api.socials-hub.com'
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME='https'
EOF

# Install dependencies and build production assets
pnpm install
pnpm build
```
*(Artifacts will be generated in `/var/www/html/dropwave-dashboard/dist`)*

#### B. Build Marketing Website (`dropwave-frontend`)
```bash
cd /var/www/html/dropwave-frontend
pnpm install
pnpm build
```
*(Artifacts will be generated in `/var/www/html/dropwave-frontend/dist`)*

#### C. Set Web Permissions
```bash
chown -R www-data:www-data /var/www/html/dropwave-dashboard/dist
chown -R www-data:www-data /var/www/html/dropwave-frontend/dist
chmod -R 755 /var/www/html
```

---

### Step 4: Host Nginx Configuration

Create the unified Nginx virtual host configuration:

```bash
cat << 'EOF' > /etc/nginx/sites-available/socials-hub.conf
# ==========================================
# 1. Backend API & WebSockets (api.socials-hub.com)
# ==========================================
server {
    listen 80;
    server_name api.socials-hub.com;
    client_max_body_size 64M;

    # Laravel Reverb WebSocket proxy
    location /app {
        proxy_pass http://127.0.0.1:8000/app;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_connect_timeout 60s;
    }

    # REST API & Media Uploads
    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
}

# ==========================================
# 2. Admin & Agent Dashboard (app.socials-hub.com)
# ==========================================
server {
    listen 80;
    server_name app.socials-hub.com;
    root /var/www/html/dropwave-dashboard/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(?:css|js|jpg|jpeg|gif|png|ico|cur|gz|svg|svgz|mp4|ogg|ogv|webm|htc|woff|woff2)$ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public";
    }
}

# ==========================================
# 3. Marketing Website (socials-hub.com & www.socials-hub.com)
# ==========================================
server {
    listen 80;
    server_name socials-hub.com www.socials-hub.com;
    root /var/www/html/dropwave-frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(?:css|js|jpg|jpeg|gif|png|ico|cur|gz|svg|svgz|mp4|ogg|ogv|webm|htc|woff|woff2)$ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public";
    }
}
EOF
```

Enable the site and reload Nginx:
```bash
ln -sf /etc/nginx/sites-available/socials-hub.conf /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

---

### Step 5: Free SSL Certificates via Certbot

Run Certbot to automatically provision and bind Let's Encrypt SSL certificates for all 4 subdomains:

```bash
certbot --nginx --non-interactive --agree-tos \
    -m hello@socials-hub.com \
    -d socials-hub.com \
    -d www.socials-hub.com \
    -d api.socials-hub.com \
    -d app.socials-hub.com
```

Certbot will automatically install HTTPS redirects and reload Nginx.

---

### Step 6: Post-Deployment Verification

Verify that all services are operational:

```bash
# 1. Check Docker Containers
docker compose -f /var/www/html/dropwave-backend/docker-compose.yml ps

# 2. Check Background Workers
docker exec socialshub-app supervisorctl status

# 3. Test API Health Check
curl -I https://api.socials-hub.com/up

# 4. Test Dashboard HTTP Response
curl -I https://app.socials-hub.com

# 5. Test Marketing Website HTTP Response
curl -I https://socials-hub.com
```

Expected Result: All curl requests return `HTTP/2 200`.
EOF
