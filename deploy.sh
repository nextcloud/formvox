#!/bin/bash

# FormVox Deployment Script
# Deploys to Nextcloud test server
# Usage: ./deploy.sh [1dev|3dev|hetzner|dev]  (default: dev)

set -e

# Configuration
APP_NAME="formvox"
REMOTE_USER="rdekker"
REMOTE_PATH="/var/www/nextcloud/apps"
SSH_KEY="~/.ssh/sur"
LOCAL_PATH="$(pwd)"

# Server selection based on argument (default: dev = dev.rikdekker.nl)
case "${1:-dev}" in
    1dev|1)
        REMOTE_HOST="145.38.193.235"
        SERVER_NAME="1dev"
        ;;
    3dev|3)
        REMOTE_HOST="145.38.188.218"
        SERVER_NAME="3dev"
        ;;
    hetzner|dev|"")
        REMOTE_HOST="178.63.205.103"
        REMOTE_USER="root"
        SSH_KEY="~/.ssh/hetzner_ed25519"
        SERVER_NAME="dev.rikdekker.nl"
        DOCKER_DEPLOY=true
        DOCKER_CONTAINER="nc-dev"
        REMOTE_PATH="/opt/docker/nc-dev/app/custom_apps"
        ;;
    *)
        echo "❌ Unknown server: $1"
        echo "Usage: ./deploy.sh [1dev|3dev|hetzner|dev]  (default: dev)"
        exit 1
        ;;
esac

# Extract version from package.json
VERSION=$(grep '"version"' package.json | head -1 | sed 's/.*"version": "\([^"]*\)".*/\1/')

echo "🚀 FormVox Deployment Script"
echo "=============================="
echo "📌 Version: $VERSION"
echo "📅 Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo "🖥️  Server: $SERVER_NAME ($REMOTE_HOST)"

# Files and folders to include in deployment
INCLUDE_ITEMS=(
    "appinfo"
    "lib"
    "l10n"
    "templates"
    "css"
    "img"
    "js"
    "docs"
    "LICENSE"
    "README.md"
)

echo ""
echo "📦 Step 1: Building frontend..."

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo "  📥 Installing dependencies..."
    npm install
fi

# Build
npm run build

if [ $? -ne 0 ]; then
    echo "❌ Build failed!"
    exit 1
fi

echo "✅ Build completed"

echo ""
echo "📋 Step 2: Creating deployment package..."

# Create temporary directory
TEMP_DIR=$(mktemp -d)
DEPLOY_DIR="$TEMP_DIR/$APP_NAME"
mkdir -p "$DEPLOY_DIR"

# Copy files
for item in "${INCLUDE_ITEMS[@]}"; do
    if [ -e "$LOCAL_PATH/$item" ]; then
        echo "  📄 Copying $item..."
        cp -r "$LOCAL_PATH/$item" "$DEPLOY_DIR/"
    else
        echo "  ⚠️  Warning: $item not found, skipping..."
    fi
done

# Create tarball
TARBALL="$TEMP_DIR/${APP_NAME}.tar.gz"
echo "  📦 Creating tarball..."
cd "$TEMP_DIR"
tar -czf "$TARBALL" "$APP_NAME"

echo "✅ Deployment package created"

echo ""
echo "🚢 Step 3: Deploying to server..."
echo "  Server: $REMOTE_HOST ($SERVER_NAME)"
echo "  Path: $REMOTE_PATH/$APP_NAME"

# Upload tarball
echo "  📤 Uploading package..."
scp -i "$SSH_KEY" "$TARBALL" "${REMOTE_USER}@${REMOTE_HOST}:/tmp/${APP_NAME}.tar.gz"

# Extract and setup on server
echo "  📂 Extracting on server..."
if [ "${DOCKER_DEPLOY:-false}" = true ]; then
    # Docker deployment (Hetzner/dev.rikdekker.nl)
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
        set -e

        # Ensure custom_apps directory exists
        mkdir -p $REMOTE_PATH

        # Backup existing installation if present
        if [ -d "$REMOTE_PATH/$APP_NAME" ]; then
            echo "  💾 Backing up existing installation..."
            BACKUP_NAME="${APP_NAME}.backup.\$(date +%Y%m%d_%H%M%S)"
            mv $REMOTE_PATH/$APP_NAME "/tmp/\$BACKUP_NAME" || true
            echo "  📦 Backup saved to /tmp/\$BACKUP_NAME"
        fi

        # Extract new version
        echo "  📦 Extracting new version..."
        tar -xzf /tmp/${APP_NAME}.tar.gz -C $REMOTE_PATH

        # Set permissions (www-data in container is uid 33)
        echo "  🔐 Setting permissions..."
        chown -R 33:33 $REMOTE_PATH/$APP_NAME
        chmod -R 755 $REMOTE_PATH/$APP_NAME

        # Clean up
        rm /tmp/${APP_NAME}.tar.gz

        # Remove old backups, keep only the 2 most recent
        echo "  🧹 Cleaning up old backups..."
        ls -d /tmp/${APP_NAME}.backup.* 2>/dev/null | sort -r | tail -n +3 | xargs -r rm -rf

        echo "  ✅ Files deployed"
EOF
else
    # Bare metal deployment (SURF servers)
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
        set -e

        # Navigate to apps directory
        cd $REMOTE_PATH

        # Backup existing installation if present
        if [ -d "$APP_NAME" ]; then
            echo "  💾 Backing up existing installation..."
            BACKUP_NAME="${APP_NAME}.backup.\$(date +%Y%m%d_%H%M%S)"
            # Move backup to /tmp instead of apps directory to avoid Nextcloud scanning it
            sudo mv $APP_NAME "/tmp/\$BACKUP_NAME" || true
            echo "  📦 Backup saved to /tmp/\$BACKUP_NAME"
        fi

        # Extract new version
        echo "  📦 Extracting new version..."
        sudo tar -xzf /tmp/${APP_NAME}.tar.gz -C $REMOTE_PATH

        # Set permissions
        echo "  🔐 Setting permissions..."
        sudo chown -R www-data:www-data $REMOTE_PATH/$APP_NAME
        sudo chmod -R 755 $REMOTE_PATH/$APP_NAME

        # Clean up
        rm /tmp/${APP_NAME}.tar.gz

        # Remove old backups, keep only the 2 most recent
        echo "  🧹 Cleaning up old backups..."
        ls -d /tmp/${APP_NAME}.backup.* 2>/dev/null | sort -r | tail -n +3 | xargs -r sudo rm -rf

        echo "  ✅ Files deployed"
EOF
fi

echo ""
echo "🔧 Step 4: Enabling app and running setup..."
if [ "${DOCKER_DEPLOY:-false}" = true ]; then
    # Docker: run occ commands inside the container
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
        set -e

        # Ensure custom_apps path is configured in Nextcloud
        docker exec -u www-data $DOCKER_CONTAINER php occ config:system:set apps_paths 1 path --value="/var/www/html/custom_apps" 2>/dev/null || true
        docker exec -u www-data $DOCKER_CONTAINER php occ config:system:set apps_paths 1 url --value="/custom_apps" 2>/dev/null || true
        docker exec -u www-data $DOCKER_CONTAINER php occ config:system:set apps_paths 1 writable --value=true --type=boolean 2>/dev/null || true

        # Disable and re-enable app to force route cache refresh
        echo "  🔌 Re-enabling app (forces route cache refresh)..."
        docker exec -u www-data $DOCKER_CONTAINER php occ app:disable $APP_NAME 2>/dev/null || true
        docker exec -u www-data $DOCKER_CONTAINER php occ app:enable $APP_NAME 2>/dev/null || true

        # Update data fingerprint to bust browser asset cache (prevents chunk mismatch errors)
        echo "  🔄 Updating asset fingerprint..."
        docker exec -u www-data $DOCKER_CONTAINER php occ maintenance:data-fingerprint 2>/dev/null || true

        # Restart Apache inside the container to clear OPcache
        echo "  🔄 Restarting Apache in container (OPcache clear)..."
        docker exec $DOCKER_CONTAINER apache2ctl graceful 2>/dev/null || docker restart $DOCKER_CONTAINER

        echo "  ✅ App deployed"
EOF
else
    # Bare metal: run occ commands directly
    ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" << EOF
        set -e
        cd /var/www/nextcloud

        # Disable and re-enable app to force route cache refresh
        # (app:enable alone is a no-op if already enabled, which means
        # new controllers/routes won't be picked up by Nextcloud's router)
        echo "  🔌 Re-enabling app (forces route cache refresh)..."
        sudo -u www-data php occ app:disable $APP_NAME 2>/dev/null || true
        sudo -u www-data php occ app:enable $APP_NAME || true

        # Clear OPcache by restarting web server (required for new/changed PHP controllers)
        echo "  🔄 Restarting web server (OPcache clear)..."
        sudo systemctl restart apache2 2>/dev/null || sudo systemctl restart php8.2-fpm 2>/dev/null || sudo systemctl restart php8.1-fpm 2>/dev/null || sudo systemctl restart php-fpm 2>/dev/null || echo "  ⚠️  Could not restart web server, clear OPcache manually"

        echo "  ✅ App enabled"
EOF
fi

echo ""
echo "🏥 Step 5: Health check..."
if [ "${DOCKER_DEPLOY:-false}" = true ]; then
    HEALTH_CHECK=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "docker exec $DOCKER_CONTAINER curl -s -o /dev/null -w '%{http_code}' http://localhost/apps/formvox/ 2>/dev/null || echo '000'")
else
    HEALTH_CHECK=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "curl -s -o /dev/null -w '%{http_code}' http://localhost/apps/formvox/ 2>/dev/null || echo '000'")
fi

if [ "$HEALTH_CHECK" = "200" ] || [ "$HEALTH_CHECK" = "302" ] || [ "$HEALTH_CHECK" = "303" ]; then
    echo "  ✅ Health check passed (HTTP $HEALTH_CHECK)"
else
    echo "  ⚠️  Health check returned HTTP $HEALTH_CHECK (may require login)"
fi

# Verify deployed version
echo ""
echo "🔍 Step 6: Verifying deployed version..."
DEPLOYED_VERSION=$(ssh -i "$SSH_KEY" "${REMOTE_USER}@${REMOTE_HOST}" "grep '<version>' $REMOTE_PATH/$APP_NAME/appinfo/info.xml | sed 's/.*<version>\([^<]*\)<\/version>.*/\1/'")
echo "  📌 Deployed version: $DEPLOYED_VERSION"

if [ "$VERSION" = "$DEPLOYED_VERSION" ]; then
    echo "  ✅ Version matches!"
else
    echo "  ⚠️  Version mismatch! Local: $VERSION, Deployed: $DEPLOYED_VERSION"
fi

# Cleanup local temp files
rm -rf "$TEMP_DIR"

echo ""
echo "✅ Deployment completed successfully!"
echo ""
echo "📊 Summary:"
echo "  • App Name: $APP_NAME"
echo "  • Version: $DEPLOYED_VERSION"
echo "  • Server: $REMOTE_HOST ($SERVER_NAME)"
echo "  • Status: Deployed and enabled"
echo ""
echo "🔄 Rollback (if needed):"
echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'ls -la /tmp/${APP_NAME}.backup.*'"
if [ "${DOCKER_DEPLOY:-false}" = true ]; then
    echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'rm -rf $REMOTE_PATH/$APP_NAME && mv /tmp/${APP_NAME}.backup.YYYYMMDD_HHMMSS $REMOTE_PATH/$APP_NAME'"
else
    echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'sudo rm -rf $REMOTE_PATH/$APP_NAME && sudo mv /tmp/${APP_NAME}.backup.YYYYMMDD_HHMMSS $REMOTE_PATH/$APP_NAME'"
fi
echo ""
echo "📝 View logs:"
if [ "${DOCKER_DEPLOY:-false}" = true ]; then
    echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'docker exec $DOCKER_CONTAINER tail -f /var/www/html/data/nextcloud.log'"
else
    echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'sudo tail -f /var/www/nextcloud/data/nextcloud.log'"
fi
echo ""
