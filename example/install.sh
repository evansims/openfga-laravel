#!/bin/bash

# OpenFGA Laravel Example Installation Script
# This script sets up the example application for demonstration

set -e

echo "🚀 Installing OpenFGA Laravel Example Application"
echo "=================================================="

# Check if we're in the example directory
if [ ! -f "README.md" ]; then
    echo "❌ Error: Please run this script from the example/ directory"
    exit 1
fi

# Check if Laravel is installed
if ! command -v php &> /dev/null; then
    echo "❌ Error: PHP is not installed or not in PATH"
    exit 1
fi

# Check if Composer is installed
if ! command -v composer &> /dev/null; then
    echo "❌ Error: Composer is not installed or not in PATH"
    exit 1
fi

echo "📦 Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader

echo "⚙️  Setting up environment configuration..."
if [ ! -f ".env" ]; then
    cp .env.example .env
    echo "✅ Created .env file from .env.example"
fi

echo "🔑 Generating application key..."
php artisan key:generate

echo "🗄️  Setting up database..."
read -p "Do you want to create a SQLite database? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    touch database/database.sqlite
    echo "✅ Created SQLite database"

    # Update .env file to use SQLite
    sed -i.bak 's/DB_CONNECTION=mysql/DB_CONNECTION=sqlite/' .env
    sed -i.bak 's/DB_DATABASE=.*/DB_DATABASE=database\/database.sqlite/' .env
    echo "✅ Updated .env to use SQLite"
fi

echo "🏗️  Running database migrations..."
php artisan migrate --force

echo "🌱 Seeding example data..."
php artisan db:seed --class=ExampleSeeder

echo "🎨 Publishing assets..."
php artisan vendor:publish --provider="OpenFGA\Laravel\OpenFgaServiceProvider" --tag="config"

echo "🔧 Setting up OpenFGA configuration..."
echo ""
echo "Please configure your OpenFGA connection in the .env file:"
echo "OPENFGA_URL=http://localhost:8080"
echo "OPENFGA_STORE_ID=your-store-id"
echo "OPENFGA_MODEL_ID=your-model-id"
echo ""

# Ask if user wants to start OpenFGA locally
read -p "Do you want to start OpenFGA locally using Docker? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    if command -v docker &> /dev/null; then
        echo "🐳 Starting OpenFGA with Docker..."
        docker run -d \
            --name openfga-example \
            -p 8080:8080 \
            -p 8081:8081 \
            -p 3000:3000 \
            openfga/openfga:latest \
            run --playground-enabled

        echo "✅ OpenFGA started on http://localhost:8080"
        echo "🎮 Playground available at http://localhost:3000"

        # Wait for OpenFGA to start
        echo "⏳ Waiting for OpenFGA to start..."
        sleep 5

        # Create store and model
        echo "🏪 Creating OpenFGA store and model..."

        # Create store
        STORE_RESPONSE=$(curl -s -X POST http://localhost:8080/stores \
            -H "Content-Type: application/json" \
            -d '{"name": "openfga-laravel-example"}')

        STORE_ID=$(echo $STORE_RESPONSE | grep -o '"id":"[^"]*"' | cut -d'"' -f4)

        if [ -n "$STORE_ID" ]; then
            echo "✅ Created store with ID: $STORE_ID"

            # Create authorization model
            MODEL_RESPONSE=$(curl -s -X POST "http://localhost:8080/stores/$STORE_ID/authorization-models" \
                -H "Content-Type: application/json" \
                -d @openfga/model.fga)

            MODEL_ID=$(echo $MODEL_RESPONSE | grep -o '"authorization_model_id":"[^"]*"' | cut -d'"' -f4)

            if [ -n "$MODEL_ID" ]; then
                echo "✅ Created authorization model with ID: $MODEL_ID"

                # Update .env file
                sed -i.bak "s/OPENFGA_STORE_ID=.*/OPENFGA_STORE_ID=$STORE_ID/" .env
                sed -i.bak "s/OPENFGA_MODEL_ID=.*/OPENFGA_MODEL_ID=$MODEL_ID/" .env
                echo "✅ Updated .env with OpenFGA credentials"
            else
                echo "❌ Failed to create authorization model"
            fi
        else
            echo "❌ Failed to create OpenFGA store"
        fi
    else
        echo "❌ Docker is not installed. Please install Docker and run:"
        echo "docker run -d --name openfga-example -p 8080:8080 -p 3000:3000 openfga/openfga:latest run --playground-enabled"
    fi
fi

echo ""
echo "🎉 Installation completed successfully!"
echo ""
echo "📚 What's next:"
echo "1. Configure OpenFGA connection in .env file (if not done automatically)"
echo "2. Start the Laravel development server: php artisan serve"
echo "3. Visit http://localhost:8000 to see the example application"
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
echo "📖 For more information, see the README.md file"
echo "🐛 For issues, visit: https://github.com/evansms/openfga-laravel/issues"
