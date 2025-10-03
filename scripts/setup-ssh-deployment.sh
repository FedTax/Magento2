#!/bin/bash

# TaxCloud Magento2 Extension - SSH Deployment Setup Script
# This script helps set up SSH key-based deployment to DigitalOcean

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
KEY_NAME="taxcloud_deploy_do"
KEY_PATH="$HOME/.ssh/$KEY_NAME"
DO_SERVER_IP=""
DO_USERNAME="deploy"

# Logging functions
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

# Check if running on macOS
check_os() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        success "Running on macOS - pbcopy available"
    else
        warning "Not running on macOS - you'll need to manually copy the public key"
    fi
}

# Generate SSH key pair
generate_ssh_key() {
    log "Generating SSH key pair for DigitalOcean deployment..."
    
    if [ -f "$KEY_PATH" ]; then
        warning "SSH key already exists: $KEY_PATH"
        read -p "Do you want to overwrite it? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log "Using existing key: $KEY_PATH"
            return
        fi
    fi
    
    # Generate ED25519 key (preferred) or fallback to RSA
    if command -v ssh-keygen &> /dev/null; then
        if ssh-keygen -t ed25519 -f "$KEY_PATH" -C "taxcloud-deployment@digitalocean" -N ""; then
            success "Generated ED25519 key pair"
        else
            warning "ED25519 not supported, falling back to RSA"
            ssh-keygen -t rsa -b 4096 -f "$KEY_PATH" -C "taxcloud-deployment@digitalocean" -N ""
            success "Generated RSA key pair"
        fi
    else
        error "ssh-keygen not found. Please install OpenSSH."
        exit 1
    fi
}

# Display public key
show_public_key() {
    log "Your public key:"
    echo "----------------------------------------"
    cat "$KEY_PATH.pub"
    echo "----------------------------------------"
    
    if [[ "$OSTYPE" == "darwin"* ]]; then
        cat "$KEY_PATH.pub" | pbcopy
        success "Public key copied to clipboard!"
    else
        warning "Please copy the public key above manually"
    fi
}

# Get server information
get_server_info() {
    echo
    read -p "Enter your DigitalOcean server IP address: " DO_SERVER_IP
    read -p "Enter username (default: deploy): " input_username
    DO_USERNAME=${input_username:-deploy}
    
    log "Server: $DO_SERVER_IP"
    log "Username: $DO_USERNAME"
}

# Test SSH connection
test_ssh_connection() {
    log "Testing SSH connection..."
    
    if ssh -i "$KEY_PATH" -o ConnectTimeout=10 -o BatchMode=yes "$DO_USERNAME@$DO_SERVER_IP" "echo 'SSH connection successful'" 2>/dev/null; then
        success "SSH connection test passed!"
        return 0
    else
        error "SSH connection test failed!"
        return 1
    fi
}

# Setup server (if connection works)
setup_server() {
    log "Setting up server for deployment..."
    
    ssh -i "$KEY_PATH" "$DO_USERNAME@$DO_SERVER_IP" << 'EOF'
        # Create necessary directories
        sudo mkdir -p /var/www/html/app/code/Taxcloud/Magento2
        sudo mkdir -p /var/www/html/var/backups
        
        # Set proper ownership
        sudo chown -R www-data:www-data /var/www/html/app/code
        sudo chown -R www-data:www-data /var/www/html/var
        
        # Set permissions
        sudo chmod -R 755 /var/www/html/app/code
        sudo chmod -R 755 /var/www/html/var
        
        echo "Server setup completed"
EOF
    
    success "Server setup completed"
}

# Display GitHub secrets instructions
show_github_secrets() {
    log "GitHub Secrets Configuration:"
    echo
    echo "Go to your GitHub repository → Settings → Secrets and variables → Actions"
    echo "Add the following secrets:"
    echo
    echo "┌─────────────────────┬─────────────────────────────────────────┐"
    echo "│ Secret Name         │ Value                                    │"
    echo "├─────────────────────┼─────────────────────────────────────────┤"
    echo "│ SFTP_HOST           │ $DO_SERVER_IP                           │"
    echo "│ SFTP_USERNAME       │ $DO_USERNAME                            │"
    echo "│ SFTP_PORT           │ 22 (or your custom port)                │"
    echo "│ MAGENTO_ROOT_PATH   │ /var/www/html                           │"
    echo "│ WEB_USER            │ www-data                                │"
    echo "│ WEB_GROUP           │ www-data                                │"
    echo "│ SSH_PRIVATE_KEY     │ (see below)                             │"
    echo "└─────────────────────┴─────────────────────────────────────────┘"
    echo
    echo "For SSH_PRIVATE_KEY, copy the contents of: $KEY_PATH"
    echo
    echo "Private key contents:"
    echo "----------------------------------------"
    cat "$KEY_PATH"
    echo "----------------------------------------"
    
    if [[ "$OSTYPE" == "darwin"* ]]; then
        cat "$KEY_PATH" | pbcopy
        success "Private key copied to clipboard!"
    else
        warning "Please copy the private key above manually"
    fi
}

# Main setup function
main() {
    log "🚀 TaxCloud Magento2 SSH Deployment Setup"
    echo
    
    check_os
    generate_ssh_key
    show_public_key
    
    echo
    warning "IMPORTANT: Add the public key above to your DigitalOcean server"
    echo "1. SSH into your server: ssh root@your-server-ip"
    echo "2. Add the key: echo 'public-key-here' >> ~/.ssh/authorized_keys"
    echo "3. Or add it to the deploy user: echo 'public-key-here' >> /home/deploy/.ssh/authorized_keys"
    echo
    
    read -p "Press Enter when you've added the public key to your server..."
    
    get_server_info
    
    if test_ssh_connection; then
        setup_server
        show_github_secrets
        success "🎉 SSH deployment setup completed!"
        echo
        log "Next steps:"
        echo "1. Add the GitHub secrets shown above"
        echo "2. Push your changes to trigger deployment"
        echo "3. Monitor the Actions tab for deployment status"
    else
        error "Setup incomplete. Please check your SSH key configuration."
        exit 1
    fi
}

# Handle script arguments
case "${1:-setup}" in
    "setup")
        main
        ;;
    "test")
        get_server_info
        test_ssh_connection
        ;;
    "show-key")
        show_public_key
        ;;
    "help"|"-h"|"--help")
        echo "TaxCloud Magento2 SSH Deployment Setup"
        echo
        echo "Usage: $0 [command]"
        echo
        echo "Commands:"
        echo "  setup     Run full setup (default)"
        echo "  test      Test SSH connection"
        echo "  show-key  Display public key"
        echo "  help      Show this help message"
        ;;
    *)
        error "Unknown command: $1"
        echo "Use '$0 help' for usage information"
        exit 1
        ;;
esac
