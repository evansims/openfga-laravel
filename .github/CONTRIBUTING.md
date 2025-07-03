# CONTRIBUTING

Contributions are welcome, and are accepted via pull requests.
Please review these guidelines before submitting any pull requests.

## Process

1. Fork the project
1. Create a new branch
1. Code, test, commit and push
1. Open a pull request detailing your changes

## Development Workflow

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Running Linters

Lint your code:

```bash
composer lint
```

Lint and fix:

```bash
composer lint:fix
```

## Running Tests

Run all tests:

```bash
composer test
```

Unit tests:

```bash
composer test:unit
```

Integration tests require Docker. The container starts automatically:

```bash
composer test:integration
```
