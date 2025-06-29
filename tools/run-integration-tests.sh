#!/usr/bin/env bash

# Integration Test Runner with Guaranteed Cleanup
# Ensures cleanup happens even if tests fail or are interrupted

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

echo "🚀 Starting integration tests..."
echo ""

# Build the test container
echo "🔨 Building test container..."
# BuildKit is now used by default in our Dockerfile
$DOCKER_COMPOSE -f "$COMPOSE_FILE" build test

# Start services first
echo "🚀 Starting services..."
$DOCKER_COMPOSE -f "$COMPOSE_FILE" up -d openfga otel-collector

# Wait for services to be ready
echo "⏳ Waiting for services to be ready..."

# Wait for OpenFGA to be ready
echo "Checking OpenFGA health..."
MAX_RETRIES=30
RETRY_COUNT=0
while true; do
    # Use docker compose run to access the network and check OpenFGA health
    HEALTH_RESPONSE=$($DOCKER_COMPOSE -f "$COMPOSE_FILE" run --rm test curl -s http://openfga:8080/healthz 2>/dev/null || echo "")
    
    # Check if the response contains SERVING status
    if echo "$HEALTH_RESPONSE" | grep -q '"status":"SERVING"'; then
        echo "✅ OpenFGA is ready! Response: $HEALTH_RESPONSE"
        break
    fi
    
    RETRY_COUNT=$((RETRY_COUNT + 1))
    if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
        echo "❌ OpenFGA failed to start after $MAX_RETRIES attempts"
        echo "Last health response: $HEALTH_RESPONSE"
        docker logs openfga-integration-tests || true
        exit 1
    fi
    
    echo "Waiting for OpenFGA... (attempt $RETRY_COUNT/$MAX_RETRIES) - Status: $HEALTH_RESPONSE"
    sleep 2
done

# Extra wait for complete initialization
sleep 2

# Check service status
echo "📊 Service status:"
$DOCKER_COMPOSE -f "$COMPOSE_FILE" ps

# Run the tests
echo "🧪 Running integration tests..."
$DOCKER_COMPOSE -f "$COMPOSE_FILE" run --rm test

echo ""
echo "✅ Integration tests completed successfully!"