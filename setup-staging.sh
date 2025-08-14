#!/bin/bash

echo "Setting up Magento 2 staging environment..."

# Create magento directory
mkdir -p magento

# Start Docker services
echo "Starting Docker services..."
docker-compose up -d

# Wait for services to be ready
echo "Waiting for services to be ready..."
sleep 30

# Install Composer in the PHP container
echo "Installing Composer..."
docker-compose exec php curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install Magento 2 via Composer
echo "Installing Magento 2..."
docker-compose exec php composer create-project --repository-url=https://repo.magento.com/ magento/project-community-edition /var/www/html

# Set proper permissions
echo "Setting permissions..."
docker-compose exec php chown -R www-data:www-data /var/www/html
docker-compose exec php chmod -R 777 /var/www/html/var
docker-compose exec php chmod -R 777 /var/www/html/generated
docker-compose exec php chmod -R 777 /var/www/html/pub/static

# Install Magento 2
echo "Installing Magento 2..."
docker-compose exec php /var/www/html/bin/magento setup:install \
    --base-url=http://localhost:8080/ \
    --db-host=mysql \
    --db-name=magento \
    --db-user=magento \
    --db-password=magento \
    --admin-firstname=Admin \
    --admin-lastname=User \
    --admin-email=admin@example.com \
    --admin-user=admin \
    --admin-password=admin123 \
    --language=en_US \
    --currency=USD \
    --timezone=America/New_York \
    --use-rewrites=1 \
    --search-engine=elasticsearch7 \
    --elasticsearch-host=elasticsearch \
    --elasticsearch-port=9200 \
    --cache-backend=redis \
    --cache-backend-redis-server=redis \
    --cache-backend-redis-port=6379 \
    --cache-backend-redis-db=0 \
    --page-cache=redis \
    --page-cache-redis-server=redis \
    --page-cache-redis-port=6379 \
    --page-cache-redis-db=1 \
    --session-save=redis \
    --session-save-redis-host=redis \
    --session-save-redis-port=6379 \
    --session-save-redis-db=2

# Deploy the TaxCloud module
echo "Deploying TaxCloud module..."
cp -r ../Magento2 magento/app/code/Taxcloud/

# Set permissions for the module
docker-compose exec php chown -R www-data:www-data /var/www/html/app/code/Taxcloud
docker-compose exec php chmod -R 755 /var/www/html/app/code/Taxcloud

# Enable and install the module
echo "Enabling TaxCloud module..."
docker-compose exec php /var/www/html/bin/magento module:enable Taxcloud_Magento2
docker-compose exec php /var/www/html/bin/magento setup:upgrade
docker-compose exec php /var/www/html/bin/magento setup:di:compile
docker-compose exec php /var/www/html/bin/magento setup:static-content:deploy -f
docker-compose exec php /var/www/html/bin/magento cache:flush

echo "Staging environment setup complete!"
echo "Magento 2 is available at: http://localhost:8080"
echo "Admin panel: http://localhost:8080/admin"
echo "Admin credentials: admin / admin123" 