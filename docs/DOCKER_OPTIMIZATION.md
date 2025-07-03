# Docker Image Optimization

This document explains the Docker image optimization strategies used in this project.

## Image Size Reduction

We've implemented a two-stage optimization process to minimize the Docker image size for integration tests:

### 1. Dockerfile Optimization
- **Original**: 1.62GB (using full Alpine with all extensions)
- **Optimized**: 583MB (using minimal Alpine with only required extensions)
- **Reduction**: 64%

### 2. Docker Slim Optimization (Linux only)
- **Applied automatically** on Linux systems and in GitHub Actions
- **Not available** on macOS/Windows due to Linux kernel requirements (fanotify)
- Further reduces image size by analyzing runtime behavior
- Removes unused files, libraries, and binaries
- Gracefully falls back to Dockerfile-optimized image if unavailable

## Key Optimizations

### Dockerfile Optimizations
- Removed unnecessary PHP extensions (gd, bcmath, ctype, fileinfo, mbstring, etc.)
- Using Alpine Linux base image
- Cleaned up build dependencies after compilation
- Optimized layer caching
- Running as non-root user (www-data)

### Build Optimizations
- **BuildKit**: Always enabled for better caching, parallel builds, and cache mounts
- **--squash flag**: Not used (incompatible with BuildKit; BuildKit provides better optimization)
- **Layer compression**: Maximum gzip compression in CI/CD
- **Docker Slim**: Additional runtime analysis on Linux systems

### BuildKit vs Legacy Builder
Our Dockerfile uses BuildKit-specific features like cache mounts (`--mount=type=cache`), which require BuildKit to be enabled. While Docker's experimental `--squash` flag can reduce image size by merging layers, it's incompatible with BuildKit.

BuildKit provides superior optimization through:
- Smart layer caching and reuse
- Parallel execution of independent build steps
- Cache mount support for package managers
- Automatic layer optimization

The combination of BuildKit + our optimized Dockerfile achieves excellent results without needing `--squash`.

## Running Tests

### Standard Test Run
```bash
# Automatically builds with Docker Slim optimization
composer test:integration
```

### Quick Run (No Build)
```bash
# Uses existing image without rebuilding
composer test:integration:cached
```

## How It Works

1. **Build Stage**: Creates optimized Docker image using our slim Dockerfile
2. **Optimization Stage**: Docker Slim analyzes the image and removes unused components
3. **Fallback**: If Docker Slim fails, the standard optimized image is used
4. **Test Execution**: Runs integration tests with the optimized image

## CI/CD Pipeline

The GitHub Actions workflow automatically:
- Builds the optimized Docker image
- Runs Docker Slim optimization
- Falls back gracefully if optimization fails
- Reports final image size

## Platform Support

### macOS/ARM64
- Docker Slim is **not supported** due to Linux kernel requirements (fanotify)
- The optimized Dockerfile already reduces size from 1.62GB to 583MB (64% reduction)
- This is sufficient for local development

### Linux/CI
- Docker Slim works fully in GitHub Actions (Ubuntu runners)
- Can achieve additional size reductions beyond the 583MB baseline
- Automatically applied in the CI pipeline

## Troubleshooting

If you see "Docker Slim optimization is not supported on macOS/ARM64":
- This is expected behavior on macOS
- The image is already optimized through Dockerfile improvements
- No action needed - tests will run normally

For the fastest local test runs:
```bash
# Skip rebuild entirely
composer test:integration:cached
```