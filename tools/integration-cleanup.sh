#!/usr/bin/env bash

# Integration Test Cleanup Script
# Ensures all integration test containers and data are properly cleaned up

set -euo pipefail

COMPOSE_FILE="docker-compose.integration.yml"
PROJECT_NAME="openfga-laravel"

echo "🧹 Cleaning up integration test containers and data..."

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

# Stop and remove containers, networks, volumes
echo "📦 Stopping and removing containers..."
$DOCKER_COMPOSE -f "$COMPOSE_FILE" down -v --remove-orphans --timeout 10 || true

# Remove any dangling containers
echo "🔍 Checking for dangling containers..."
CONTAINERS=$(docker ps -a --filter "name=openfga-integration-tests" --filter "name=otel-collector-integration-tests" --filter "name=openfga-php-integration-tests" -q)
if [ -n "$CONTAINERS" ]; then
    echo "🗑️  Removing dangling containers..."
    docker rm -f $CONTAINERS || true
fi

# Remove any dangling volumes
echo "💾 Checking for dangling volumes..."
VOLUMES=$(docker volume ls --filter "name=${PROJECT_NAME}" -q)
if [ -n "$VOLUMES" ]; then
    echo "🗑️  Removing dangling volumes..."
    docker volume rm $VOLUMES || true
fi

# Remove any dangling networks
echo "🌐 Checking for dangling networks..."
NETWORKS=$(docker network ls --filter "name=openfga-network" -q)
if [ -n "$NETWORKS" ]; then
    echo "🗑️  Removing dangling networks..."
    docker network rm $NETWORKS || true
fi

# Clean up any test artifacts
echo "📄 Cleaning up test artifacts..."
rm -rf coverage/integration/* || true
rm -rf build/integration/* || true

echo "✅ Integration test cleanup complete!"

# Optional: Show current Docker status
if [ "${SHOW_STATUS:-false}" = "true" ]; then
    echo ""
    echo "📊 Current Docker status:"
    echo "Containers:"
    docker ps -a --filter "name=openfga" --filter "name=otel-collector" || true
    echo ""
    echo "Volumes:"
    docker volume ls --filter "name=${PROJECT_NAME}" || true
    echo ""
    echo "Networks:"
    docker network ls --filter "name=openfga" || true
fi
