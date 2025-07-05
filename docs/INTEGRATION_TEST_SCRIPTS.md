# Integration Test Scripts Guide

This guide explains the various integration test scripts and their purposes.

## Primary Scripts

### Local Development

**`composer test:integration`**

- Main command for running integration tests locally
- Builds and optimizes image with Docker Slim
- Runs full test suite
- Use this for day-to-day development

**`composer test:integration:cached`**

- Runs tests using existing local image
- Skips building/optimization
- Fastest option if image already exists

### Docker Image Management

**`composer docker:build`**

- Builds optimized test image locally
- Uses Docker Slim for size reduction

**`composer docker:pull`**

- Pulls the latest CI-built image from GitHub Container Registry
- Tags it for local use
- Useful for testing with the exact CI image

## Utility Scripts

### Cleanup

**`composer test:integration:clean`**

- Stops and removes test containers
- Cleans up networks

**`composer test:integration:clean:force`**

- More aggressive cleanup
- Removes all containers with test labels
- Prunes networks

### Debugging

**`composer test:integration:debug`**

- Runs integration tests with debugging enabled
- Useful for troubleshooting test failures

**`composer test:integration:shell`**

- Opens a shell in the test container
- Useful for manual testing/debugging

### Service Management

**`composer test:integration:start`**

- Starts only OpenFGA and OTEL collector
- Doesn't run tests
- Useful for manual testing

**`composer test:integration:stop`**

- Stops all test services

### Test Execution

**`composer test:integration:run`**

- Runs only the Pest test command
- Assumes services are already running
- Used internally by other scripts

## Workflow Summary

### For Local Development

```bash
# Standard test run (builds and optimizes)
composer test:integration

# Quick test run (no rebuild)
composer test:integration:cached

# Use CI image locally
composer docker:pull
composer test:integration:cached
```

### For CI/CD

- Automated via GitHub Actions
- Images built on schedule or when Dockerfile changes
- Tests always use pre-built optimized images

### For Debugging

```bash
# Interactive shell
composer test:integration:shell

# Keep services running
composer test:integration:start
# ... do manual testing ...
composer test:integration:stop
```
