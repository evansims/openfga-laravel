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
sleep 5

# Check service status
echo "📊 Service status:"
$DOCKER_COMPOSE -f "$COMPOSE_FILE" ps

# Run the tests
echo "🧪 Running integration tests..."
$DOCKER_COMPOSE -f "$COMPOSE_FILE" run --rm test

echo ""
echo "✅ Integration tests completed successfully!"