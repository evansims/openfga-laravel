#!/usr/bin/env bash

# This script is deprecated in favor of GitHub Actions workflows
# Integration tests in CI now use pre-built images from GitHub Container Registry

echo "=================================================="
echo "⚠️  This script is deprecated!"
echo ""
echo "CI integration tests are now handled by GitHub Actions:"
echo "- Build workflow: .github/workflows/build-test-image.yml"
echo "- Test workflow: .github/workflows/integration-tests.yml"
echo ""
echo "Images are automatically built and published to:"
echo "ghcr.io/evansims/openfga-laravel-integration-tests:latest"
echo ""
echo "For local testing, use: composer test:integration"
echo "=================================================="
exit 1