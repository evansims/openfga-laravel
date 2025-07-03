#!/usr/bin/env bash
set -euo pipefail

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

# Configuration
IMAGE_NAME="evansims/openfga-laravel-integration-tests"
DOCKERFILE="Dockerfile.integration"

echo "🔨 Building integration test image..."

# BuildKit is required for our Dockerfile (uses --mount directives)
# Note: --squash flag is incompatible with BuildKit, but BuildKit provides
# better layer optimization through its own mechanisms
echo "📦 Using BuildKit for optimized build (required for cache mounts)"
DOCKER_INFO=$(docker info 2>&1 || true)
if echo "$DOCKER_INFO" | grep -q "Experimental:.*true"; then
    echo "ℹ️  Note: --squash flag is not compatible with BuildKit"
    echo "ℹ️  BuildKit provides superior layer optimization automatically"
fi

# Build with BuildKit enabled
DOCKER_BUILDKIT=1 docker build -f "$DOCKERFILE" -t "${IMAGE_NAME}:original" . --quiet

# Get original size
ORIGINAL_SIZE=$(docker images "${IMAGE_NAME}:original" --format "{{.Size}}")
echo "📦 Base image size: ${ORIGINAL_SIZE}"

# Detect platform
PLATFORM=$(uname -s)
ARCH=$(uname -m)

# Docker Slim only works properly on Linux due to kernel requirements
if [[ "$PLATFORM" == "Linux" ]]; then
    echo "🏃‍♂️ Running Docker Slim optimization (Linux detected)..."

    # Pull Docker Slim
    docker pull dslim/slim:latest >/dev/null 2>&1

    # Find Docker socket
    DOCKER_SOCK="/var/run/docker.sock"
    if [[ ! -S "$DOCKER_SOCK" ]] && [[ -S "$HOME/.docker/run/docker.sock" ]]; then
        DOCKER_SOCK="$HOME/.docker/run/docker.sock"
    fi

    # Run Docker Slim
    if docker run --rm \
      -v "$DOCKER_SOCK:/var/run/docker.sock" \
      dslim/slim:latest build \
      --target "${IMAGE_NAME}:original" \
      --tag "${IMAGE_NAME}:latest" \
      --http-probe=false \
      --continue-after=10 \
      --include-path=/app \
      --include-path=/usr/local/lib/php \
      --include-path=/usr/local/etc/php \
      --include-path=/usr/local/bin/php \
      --include-bin=/usr/bin/curl \
      --include-shell >/dev/null 2>&1; then

        SLIM_SIZE=$(docker images "${IMAGE_NAME}:latest" --format "{{.Size}}")
        echo "✅ Image optimized with Docker Slim: ${ORIGINAL_SIZE} → ${SLIM_SIZE}"
        docker rmi "${IMAGE_NAME}:original" >/dev/null 2>&1 || true
    else
        echo "⚠️  Docker Slim failed, using optimized base image"
        docker tag "${IMAGE_NAME}:original" "${IMAGE_NAME}:latest"
    fi
else
    # On macOS/Windows, Docker Slim doesn't work due to kernel limitations
    echo "ℹ️  Platform: $PLATFORM/$ARCH"
    echo "ℹ️  Docker Slim requires Linux kernel features not available on this platform"
    echo "ℹ️  Using Dockerfile-optimized image (reduced from 1.62GB to ${ORIGINAL_SIZE})"
    docker tag "${IMAGE_NAME}:original" "${IMAGE_NAME}:latest"
fi

echo "✅ Build complete!"