# Security Policy

## Supported Versions

This library follows semantic versioning. Only the latest major version receives security updates.

## Reporting a Vulnerability

The OpenFGA Laravel team takes security issues seriously. We appreciate your efforts to responsibly disclose your findings.

If you believe you've found a security vulnerability in the OpenFGA Laravel SDK, please follow these steps:

1. **Do not disclose the vulnerability publicly**
2. **Use GitHub's private vulnerability reporting feature** at [https://github.com/evansims/openfga-laravel/security/advisories/new](https://github.com/evansims/openfga-laravel/security/advisories/new)
3. **Include details** such as:
   - A description of the vulnerability
   - Steps to reproduce with minimal code examples
   - Potential impact
   - Affected versions
   - Suggested fix (if any)

We will acknowledge receipt of your vulnerability report and send you regular updates about our progress. If you don't get a response within 48 hours, please follow up to ensure we received your report.

## Security Measures

### Dependency Security

This project employs multiple layers of dependency security:

- **Dependabot alerts** for automated vulnerability detection
- **Composer audit** runs on schedule and when dependencies change
- **Dependency review** for all pull requests changing dependencies

### Code Security

We protect our codebase with:

- **CodeQL analysis** to detect potential vulnerabilities
- **Static analysis** via PHPStan and Psalm
- **Comprehensive testing** with PEST

## Process for Handling Reports

1. Your report will be acknowledged within 48 hours
2. We will confirm the vulnerability and determine its impact
3. We will develop and test a fix in a private repository
4. We will release a patch as soon as possible, depending on complexity
5. We will publicly disclose the issue after a patch has been released

Thank you for helping keep OpenFGA Laravel and our users secure!
