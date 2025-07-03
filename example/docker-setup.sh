#!/bin/bash

# OpenFGA Laravel Example - Docker Setup Script
# This script sets up the complete development environment using Docker

set -e

echo "🐳 Setting up OpenFGA Laravel Example with Docker"
echo "================================================"

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Error: Docker is not installed"
    echo "Please install Docker Desktop from https://www.docker.com/products/docker-desktop"
    exit 1
fi

# Check if Docker Compose is available
if ! docker compose version &> /dev/null; then
    echo "❌ Error: Docker Compose is not available"
    echo "Please ensure you have Docker Desktop installed with Compose support"
    exit 1
fi

# Create .env file if it doesn't exist
if [ ! -f ".env" ]; then
    echo "📝 Creating .env file..."
    cp .env.example .env 2>/dev/null || echo "⚠️  No .env.example found, please create .env manually"
fi

echo "🚀 Starting Docker containers..."
docker compose up -d

echo "⏳ Waiting for services to be ready..."
# Wait for MySQL to be ready
echo -n "Waiting for MySQL..."
until docker compose exec -T db mysqladmin ping -h localhost --silent; do
    echo -n "."
    sleep 2
done
echo " ✅"

# Wait for OpenFGA to be ready
echo -n "Waiting for OpenFGA..."
until curl -sf http://localhost:8080/healthz > /dev/null; do
    echo -n "."
    sleep 2
done
echo " ✅"

echo "🔧 Running Laravel setup..."
# Generate application key
docker compose exec -T app php artisan key:generate

# Run migrations
echo "🗄️  Running database migrations..."
docker compose exec -T app php artisan migrate --force

# Create OpenFGA store and model
echo "🏪 Setting up OpenFGA store and model..."
STORE_RESPONSE=$(curl -s -X POST http://localhost:8080/stores \
    -H "Content-Type: application/json" \
    -d '{"name": "openfga-laravel-example"}')

STORE_ID=$(echo "$STORE_RESPONSE" | grep -o '"id":"[^"]*"' | cut -d'"' -f4)

if [ -n "$STORE_ID" ]; then
    echo "✅ Created store with ID: $STORE_ID"
    
    # Create authorization model
    MODEL_RESPONSE=$(curl -s -X POST "http://localhost:8080/stores/$STORE_ID/authorization-models" \
        -H "Content-Type: application/json" \
        -d @openfga/model.json)
    
    MODEL_ID=$(echo "$MODEL_RESPONSE" | grep -o '"authorization_model_id":"[^"]*"' | cut -d'"' -f4)
    
    if [ -n "$MODEL_ID" ]; then
        echo "✅ Created authorization model with ID: $MODEL_ID"
        
        # Update .env file with Docker environment values
        docker compose exec -T app sed -i "s/OPENFGA_STORE_ID=.*/OPENFGA_STORE_ID=$STORE_ID/" .env
        docker compose exec -T app sed -i "s/OPENFGA_MODEL_ID=.*/OPENFGA_MODEL_ID=$MODEL_ID/" .env
        
        # Also update local .env for reference
        sed -i.bak "s/OPENFGA_STORE_ID=.*/OPENFGA_STORE_ID=$STORE_ID/" .env
        sed -i.bak "s/OPENFGA_MODEL_ID=.*/OPENFGA_MODEL_ID=$MODEL_ID/" .env
    else
        echo "❌ Failed to create authorization model"
        exit 1
    fi
else
    echo "❌ Failed to create OpenFGA store"
    exit 1
fi

# Seed the database
echo "🌱 Seeding example data..."
docker compose exec -T app php artisan db:seed --class=ExampleSeeder

# Clear caches
echo "🧹 Clearing caches..."
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear

echo ""
echo "🎉 Docker setup completed successfully!"
echo ""
echo "📚 Service URLs:"
echo "- Laravel App: http://localhost:8000"
echo "- OpenFGA Playground: http://localhost:3000"
echo "- OpenFGA API: http://localhost:8080"
echo "- Mailhog: http://localhost:8025"
echo ""
echo "📊 Container Status:"
docker compose ps
echo ""
echo "👥 Demo user accounts:"
echo "- admin@example.com (Organization Admin)"
echo "- manager@example.com (Department Manager)"
echo "- lead@example.com (Team Lead)"
echo "- editor@example.com (Content Editor)"
echo "- viewer@example.com (Content Viewer)"
echo ""
echo "🔐 Password for all accounts: password"
echo ""
echo "🛠️  Useful commands:"
echo "- View logs: docker compose logs -f"
echo "- Stop containers: docker compose down"
echo "- Reset everything: docker compose down -v"
echo "- Shell into app: docker compose exec app sh"
echo ""