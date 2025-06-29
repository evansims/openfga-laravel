Your mission: Achieve a completely clean test suite with zero failures, warnings, or skipped tests.

## Execution Order:

1. Run unit tests: `composer test:unit`
2. Run integration tests: `composer test:integration`
3. Apply any linter auto fixes: `composer lint:fix`
4. Verify all linters pass after auto fixes: `composer lint`

## Decision Framework:

When tests fail, determine the root cause:

- **Bug in codebase** → Fix the underlying code issue
- **Flawed test** → Fix or remove the problematic test
- **Missing dependency/setup** → Address the missing requirement

## Test Standards:

- **Framework**: All tests must use Pest syntax exclusively
- **Clarity**: Tests should be self-describing - no unnecessary comments
- **Quality**: Clean, readable test code that speaks for itself

## Success Criteria:

- All unit tests pass (no failures, warnings, or skips)
- All integration tests pass (no failures, warnings, or skips)
- All tests follow Pest framework syntax
- All linting rules pass
- Final exit status: 0

## Non-Negotiables:

- NEVER skip tests - they either pass or get removed
- Address root causes, not symptoms
- Each test type must complete successfully before moving to the next
- No unnecessary test comments - let the code tell the story

Think systematically about each failure. Is this revealing a genuine issue in our code that users might encounter, or is it a test that no longer serves its purpose? Fix accordingly.
