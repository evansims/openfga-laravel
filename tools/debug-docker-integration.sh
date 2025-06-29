#!/usr/bin/env bash

# Debug script for Docker integration tests
set -euo pipefail

echo "🔍 Debugging Docker Integration Test Environment"
echo "================================================"

# Check environment variables
echo -e "\n📋 Environment Variables:"
echo "OPENFGA_TEST_URL=${OPENFGA_TEST_URL:-not set}"
echo "FGA_API_URL=${FGA_API_URL:-not set}"

# Check if running in Docker
echo -e "\n🐳 Docker Detection:"
if [ -f /.dockerenv ]; then
    echo "Running inside Docker container"
else
    echo "Not running inside Docker container"
fi

# Test OpenFGA connectivity
echo -e "\n🔗 Testing OpenFGA Connectivity:"

# Check if we're inside Docker
if [ -f /.dockerenv ]; then
    # Inside Docker, use internal hostname
    OPENFGA_URL="http://openfga:8080"
else
    # Outside Docker, use localhost or env var
    OPENFGA_URL=${OPENFGA_TEST_URL:-http://localhost:8080}
fi

echo "Testing: $OPENFGA_URL/healthz"
HEALTH_RESPONSE=$(curl -s "$OPENFGA_URL/healthz" 2>/dev/null || echo "Failed to connect")
echo "Health response: $HEALTH_RESPONSE"

if echo "$HEALTH_RESPONSE" | grep -q '"status":"SERVING"'; then
    echo "✅ Health check passed - OpenFGA is SERVING"
else
    echo "❌ Health check failed - OpenFGA is not SERVING"
fi

echo -e "\nTesting: $OPENFGA_URL/stores"
if curl -f -s "$OPENFGA_URL/stores" > /dev/null; then
    echo "✅ Stores endpoint accessible"
    curl -s "$OPENFGA_URL/stores" | jq . || echo "Response: $(curl -s "$OPENFGA_URL/stores")"
else
    echo "❌ Stores endpoint not accessible"
fi

# Check container status if docker is available
if command -v docker &> /dev/null; then
    echo -e "\n📦 Container Status:"
    docker ps --filter "name=openfga" --filter "name=otel"
fi

echo -e "\n🏁 Debug complete"