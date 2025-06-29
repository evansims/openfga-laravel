#!/usr/bin/env bash

# CI-Optimized Integration Test Runner
# Skips OTEL health check for faster CI execution

set -euo pipefail

COMPOSE_FILE="docker-compose.integration.yml"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

# Trap to ensure cleanup on exit
cleanup() {
    local exit_code=$?
    echo ""
    echo "🧹 Running cleanup..."
    "$SCRIPT_DIR/integration-cleanup.sh"
    exit $exit_code
}

# Set trap for various signals
trap cleanup EXIT INT TERM

# Function to check if docker compose is available
check_docker_compose() {
    if command -v docker-compose &> /dev/null; then
        echo "docker-compose"
    elif docker compose version &> /dev/null; then
        echo "docker compose"
    else
        echo "❌ Docker Compose is not installed or not in PATH" >&2
        exit 1
    fi
}

DOCKER_COMPOSE=$(check_docker_compose)

echo "🚀 Starting integration tests (CI mode)..."
echo ""

# Build the test container
echo "🔨 Building test container..."
$DOCKER_COMPOSE -f "$COMPOSE_FILE" build test

# Start services first
echo "🚀 Starting services..."
$DOCKER_COMPOSE -f "$COMPOSE_FILE" up -d openfga otel-collector

# Wait for services to be ready
echo "⏳ Waiting for services to be ready..."
sleep 5

# Check service status
echo "📊 Service status:"
$DOCKER_COMPOSE -f "$COMPOSE_FILE" ps

# Create a custom wait script that skips OTEL health check
echo "🔧 Creating CI-optimized wait script..."
cat > /tmp/wait-and-test-ci.sh << 'EOF'
#!/bin/sh
set -e

# Restore vendor directory if it was overridden by volume mount
if [ ! -d "/app/vendor" ] || [ -z "$(ls -A /app/vendor)" ]; then
  echo "Restoring vendor directory..."
  cp -r /app-vendor/vendor /app/
fi

# Wait for OpenFGA to be ready
echo "Waiting for OpenFGA to be ready..."
TIMEOUT=60
COUNTER=0
until curl -f http://openfga:8080/healthz > /dev/null 2>&1; do
  if [ $COUNTER -ge $TIMEOUT ]; then
    echo "OpenFGA failed to start within $TIMEOUT seconds"
    exit 1
  fi
  echo "OpenFGA is unavailable - sleeping ($COUNTER/$TIMEOUT)"
  sleep 2
  COUNTER=$((COUNTER + 2))
done

echo "OpenFGA is ready! Starting tests..."
echo "Note: Skipping OTEL Collector health check for CI speed"

# Execute tests
exec composer test:integration:run
EOF

chmod +x /tmp/wait-and-test-ci.sh

# Run the tests with the CI script
echo "🧪 Running integration tests..."
$DOCKER_COMPOSE -f "$COMPOSE_FILE" run --rm -v /tmp/wait-and-test-ci.sh:/wait-and-test-ci.sh test /wait-and-test-ci.sh

echo ""
echo "✅ Integration tests completed successfully!"