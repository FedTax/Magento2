#!/bin/bash

# TaxCloud Magento2 Extension Deployment Script
# This script handles the deployment of the TaxCloud extension to a Magento installation

set -e

# Configuration
MODULE_NAME="Taxcloud_Magento2"
MODULE_PATH="app/code/Taxcloud/Magento2"
MAGENTO_ROOT="${MAGENTO_ROOT:-/var/www/html}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Check if running as root
check_permissions() {
    if [ "$EUID" -eq 0 ]; then
        warning "Running as root. This is not recommended for production deployments."
    fi
}

# Validate Magento installation
validate_magento() {
    log "Validating Magento installation..."
    
    if [ ! -d "$MAGENTO_ROOT" ]; then
        error "Magento root directory not found: $MAGENTO_ROOT"
        exit 1
    fi
    
    if [ ! -f "$MAGENTO_ROOT/bin/magento" ]; then
        error "Magento CLI not found: $MAGENTO_ROOT/bin/magento"
        exit 1
    fi
    
    if [ ! -f "$MAGENTO_ROOT/app/etc/env.php" ]; then
        error "Magento configuration not found: $MAGENTO_ROOT/app/etc/env.php"
        exit 1
    fi
    
    success "Magento installation validated"
}

# Backup existing module
backup_module() {
    log "Creating backup of existing module..."
    
    if [ -d "$MAGENTO_ROOT/$MODULE_PATH" ]; then
        BACKUP_DIR="$MAGENTO_ROOT/var/backups/taxcloud-$(date +%Y%m%d-%H%M%S)"
        mkdir -p "$BACKUP_DIR"
        cp -r "$MAGENTO_ROOT/$MODULE_PATH" "$BACKUP_DIR/"
        success "Module backed up to: $BACKUP_DIR"
    else
        log "No existing module found, skipping backup"
    fi
}

# Deploy module files
deploy_module() {
    log "Deploying module files..."
    
    # Create module directory
    mkdir -p "$MAGENTO_ROOT/$MODULE_PATH"
    
    # Copy module files (excluding development files)
    rsync -av --exclude='.git' \
              --exclude='.github' \
              --exclude='Test' \
              --exclude='docs' \
              --exclude='Makefile' \
              --exclude='run-test.sh' \
              --exclude='README.md' \
              --exclude='LICENSE.txt' \
              --exclude='scripts' \
              --exclude='.gitignore' \
              ./ "$MAGENTO_ROOT/$MODULE_PATH/"
    
    success "Module files deployed"
}

# Set proper permissions
set_permissions() {
    log "Setting proper permissions..."
    
    chmod -R 755 "$MAGENTO_ROOT/$MODULE_PATH"
    chown -R "$WEB_USER:$WEB_GROUP" "$MAGENTO_ROOT/$MODULE_PATH"
    
    success "Permissions set"
}

# Enable module
enable_module() {
    log "Enabling module..."
    
    cd "$MAGENTO_ROOT"
    bin/magento module:enable "$MODULE_NAME"
    
    success "Module enabled"
}

# Run Magento setup commands
run_magento_setup() {
    log "Running Magento setup commands..."
    
    cd "$MAGENTO_ROOT"
    
    # Setup upgrade
    log "Running setup:upgrade..."
    bin/magento setup:upgrade
    
    # Compile DI
    log "Running setup:di:compile..."
    bin/magento setup:di:compile
    
    # Deploy static content
    log "Running setup:static-content:deploy..."
    bin/magento setup:static-content:deploy -f
    
    # Clean and flush cache
    log "Cleaning and flushing cache..."
    bin/magento cache:clean
    bin/magento cache:flush
    
    success "Magento setup completed"
}

# Verify deployment
verify_deployment() {
    log "Verifying deployment..."
    
    cd "$MAGENTO_ROOT"
    
    # Check if module directory exists
    if [ ! -d "$MODULE_PATH" ]; then
        error "Module directory not found: $MODULE_PATH"
        exit 1
    fi
    
    # Check if module is enabled
    if ! bin/magento module:status "$MODULE_NAME" | grep -q "enabled"; then
        error "Module is not enabled"
        exit 1
    fi
    
    # Check if key files exist
    local key_files=(
        "$MODULE_PATH/Model/Api.php"
        "$MODULE_PATH/Model/Tax.php"
        "$MODULE_PATH/registration.php"
        "$MODULE_PATH/etc/module.xml"
    )
    
    for file in "${key_files[@]}"; do
        if [ ! -f "$file" ]; then
            error "Required file not found: $file"
            exit 1
        fi
    done
    
    success "Deployment verification completed"
}

# Test module functionality
test_module() {
    log "Testing module functionality..."
    
    cd "$MAGENTO_ROOT"
    
    # Check if module appears in module list
    if bin/magento module:status "$MODULE_NAME" | grep -q "enabled"; then
        success "Module is enabled and active"
    else
        error "Module is not active"
        exit 1
    fi
    
    # Check if configuration is available
    if bin/magento config:show | grep -q "taxcloud"; then
        success "Module configuration is available"
    else
        warning "Module configuration not found (may need admin configuration)"
    fi
}

# Main deployment function
main() {
    log "Starting TaxCloud Magento2 Extension Deployment"
    log "Magento Root: $MAGENTO_ROOT"
    log "Module Path: $MODULE_PATH"
    log "Web User: $WEB_USER:$WEB_GROUP"
    
    check_permissions
    validate_magento
    backup_module
    deploy_module
    set_permissions
    enable_module
    run_magento_setup
    verify_deployment
    test_module
    
    success "🎉 Deployment completed successfully!"
    log "Module is now available at: $MAGENTO_ROOT/$MODULE_PATH"
    log "Please configure the module in Magento Admin: Stores → Configuration → Sales → Tax"
}

# Handle script arguments
case "${1:-deploy}" in
    "deploy")
        main
        ;;
    "verify")
        validate_magento
        verify_deployment
        test_module
        ;;
    "backup")
        validate_magento
        backup_module
        ;;
    "help"|"-h"|"--help")
        echo "TaxCloud Magento2 Extension Deployment Script"
        echo ""
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  deploy    Deploy the module (default)"
        echo "  verify    Verify the deployment"
        echo "  backup    Create a backup of existing module"
        echo "  help      Show this help message"
        echo ""
        echo "Environment Variables:"
        echo "  MAGENTO_ROOT  Magento installation root directory (default: /var/www/html)"
        echo "  WEB_USER      Web server user (default: www-data)"
        echo "  WEB_GROUP     Web server group (default: www-data)"
        ;;
    *)
        error "Unknown command: $1"
        echo "Use '$0 help' for usage information"
        exit 1
        ;;
esac
