# CI Image Workflow

This document explains how the GitHub Actions workflows handle Docker images for integration testing.

## Overview

We use a two-workflow approach:
1. **Build Test Image** - Builds and publishes the optimized test image to GitHub Container Registry
2. **Integration Tests** - Pulls the pre-built image and runs tests

## Benefits

- **Faster CI runs** - Tests don't rebuild the image every time
- **Consistent environment** - All test runs use the same verified image
- **Better caching** - Image layers are stored in the registry
- **Version control** - Each image is tagged with commit SHA

## Workflows

### Build Test Image (`build-test-image.yml`)

**Triggers:**
- Push to `main` when Dockerfile or dependencies change
- Weekly schedule (for security updates)
- Manual dispatch

**Process:**
1. Builds the Docker image from `Dockerfile.integration`
2. Pushes to `ghcr.io` with SHA tag
3. Runs Docker Slim optimization
4. Pushes optimized image as `latest`

**Published images:**
- `ghcr.io/evansims/openfga-laravel-integration-tests:latest`
- `ghcr.io/evansims/openfga-laravel-integration-tests:slim-{sha}`

### Integration Tests (`integration-tests.yml`)

**Triggers:**
- Push to `main`
- Pull requests

**Process:**
1. Pulls the latest pre-built image from registry
2. Tags it locally for docker-compose
3. Runs integration tests
4. No building required!

## Local Development

Local development still builds images on demand:
```bash
# Builds and optimizes locally
composer test:integration
```

To use the CI image locally:
```bash
# Pull the CI image
docker pull ghcr.io/evansims/openfga-laravel-integration-tests:latest

# Tag it for local use
docker tag ghcr.io/evansims/openfga-laravel-integration-tests:latest \
  evansims/openfga-laravel-integration-tests:latest

# Run tests with cached image
composer test:integration:cached
```

## Permissions

The workflows use `GITHUB_TOKEN` for authentication:
- **Build workflow** needs `packages: write`
- **Test workflow** needs `packages: read`

## Troubleshooting

If tests fail with "image not found":
1. Check if the build workflow has run successfully
2. Verify the image exists in the registry
3. Check permissions on the repository

To manually trigger a rebuild:
1. Go to Actions → Build Test Image
2. Click "Run workflow"
3. Select the branch and run