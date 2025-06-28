<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\{AssertionHelper, FakeOpenFga};
use PHPUnit\Framework\AssertionFailedError;

describe('AssertionHelper', function (): void {
    beforeEach(function (): void {
        $this->fake = new FakeOpenFga;
    });

    describe('assertExpandContainsUsers', function (): void {
        it('fails when users missing', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertExpandContainsUsers($this->fake, 'document:1', 'reader', ['user:1', 'user:2']))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that expand result for [reader] on [document:1] contains expected users (missing user: user:2)');
        });

        it('passes when users exist', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:2', 'reader', 'document:1');

            // Should not throw
            AssertionHelper::assertExpandContainsUsers($this->fake, 'document:1', 'reader', ['user:1', 'user:2']);
        });
    });

    describe('assertExpandDoesNotContainUsers', function (): void {
        it('fails when users exist', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:2', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertExpandDoesNotContainUsers($this->fake, 'document:1', 'reader', ['user:2', 'user:3']))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that expand result for [reader] on [document:1] does not contain forbidden users (found forbidden user: user:2)');
        });

        it('passes when users missing', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            // Should not throw
            AssertionHelper::assertExpandDoesNotContainUsers($this->fake, 'document:1', 'reader', ['user:2', 'user:3']);
        });
    });

    describe('assertFailedCheckCount', function (): void {
        it('fails with incorrect count', function (): void {
            $this->fake->check('user:1', 'writer', 'document:1'); // fail

            expect(fn () => AssertionHelper::assertFailedCheckCount($this->fake, 2))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that [2] failed checks were performed. Actual: [1]');
        });

        it('passes with correct count', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            $this->fake->check('user:1', 'reader', 'document:1'); // success
            $this->fake->check('user:1', 'writer', 'document:1'); // fail
            $this->fake->check('user:2', 'reader', 'document:1'); // fail

            // Should not throw
            AssertionHelper::assertFailedCheckCount($this->fake, 2);
        });
    });

    describe('assertNoTuples', function (): void {
        it('fails when tuples exist', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertNoTuples($this->fake))
                ->toThrow(AssertionFailedError::class);
        });

        it('passes when no tuples', function (): void {
            // Should not throw
            AssertionHelper::assertNoTuples($this->fake);
        });
    });

    describe('assertObjectCheckCount', function (): void {
        it('fails with incorrect count', function (): void {
            $this->fake->check('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertObjectCheckCount($this->fake, 'document:1', 2))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that [2] checks were performed for object [document:1]. Actual: [1]');
        });

        it('passes with correct count', function (): void {
            $this->fake->check('user:1', 'reader', 'document:1');
            $this->fake->check('user:2', 'writer', 'document:1');
            $this->fake->check('user:1', 'reader', 'document:2');

            // Should not throw
            AssertionHelper::assertObjectCheckCount($this->fake, 'document:1', 2);
        });
    });

    describe('assertSuccessfulCheckCount', function (): void {
        it('fails with incorrect count', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->check('user:1', 'reader', 'document:1'); // success

            expect(fn () => AssertionHelper::assertSuccessfulCheckCount($this->fake, 2))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that [2] successful checks were performed. Actual: [1]');
        });

        it('passes with correct count', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            $this->fake->check('user:1', 'reader', 'document:1'); // success
            $this->fake->check('user:1', 'writer', 'document:1'); // fail
            $this->fake->check('user:2', 'reader', 'document:1'); // fail

            // Should not throw
            AssertionHelper::assertSuccessfulCheckCount($this->fake, 1);
        });
    });

    describe('assertTupleCount', function (): void {
        it('fails with incorrect count', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertTupleCount($this->fake, 2))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that tuple count is [2]. Actual: [1]');
        });

        it('passes with correct count', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:2', 'writer', 'document:2');

            // Should not throw
            AssertionHelper::assertTupleCount($this->fake, 2);
        });
    });

    describe('assertTuplesDoNotExist', function (): void {
        it('fails when tuples exist', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:2', 'writer', 'document:2');

            $forbiddenTuples = [
                ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
            ];

            expect(fn () => AssertionHelper::assertTuplesDoNotExist($this->fake, $forbiddenTuples))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that forbidden tuples do not exist (found forbidden tuple: user:2#writer@document:2)');
        });

        it('passes when tuples missing', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            $forbiddenTuples = [
                ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
            ];

            // Should not throw
            expect(function () use ($forbiddenTuples): void {
                AssertionHelper::assertTuplesDoNotExist($this->fake, $forbiddenTuples);
            })->not->toThrow(Exception::class);
        });
    });

    describe('assertTuplesExist', function (): void {
        it('fails when tuples missing', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            $expectedTuples = [
                ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
            ];

            expect(fn () => AssertionHelper::assertTuplesExist($this->fake, $expectedTuples))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that expected tuples exist (missing tuple: user:2#writer@document:2)');
        });

        it('passes when tuples exist', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:2', 'writer', 'document:2');

            $expectedTuples = [
                ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
            ];

            // Should not throw
            expect(function () use ($expectedTuples): void {
                AssertionHelper::assertTuplesExist($this->fake, $expectedTuples);
            })->not->toThrow(Exception::class);
        });
    });

    describe('assertUserCheckCount', function (): void {
        it('fails with incorrect count', function (): void {
            $this->fake->check('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertUserCheckCount($this->fake, 'user:1', 2))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that [2] checks were performed for user [user:1]. Actual: [1]');
        });

        it('passes with correct count', function (): void {
            $this->fake->check('user:1', 'reader', 'document:1');
            $this->fake->check('user:1', 'writer', 'document:1');
            $this->fake->check('user:2', 'reader', 'document:1');

            // Should not throw
            AssertionHelper::assertUserCheckCount($this->fake, 'user:1', 2);
        });
    });

    describe('assertUserDoesNotHaveAccessToObjects', function (): void {
        it('fails when access exists', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:1', 'reader', 'document:2');

            expect(fn () => AssertionHelper::assertUserDoesNotHaveAccessToObjects($this->fake, 'user:1', 'reader', ['document:2', 'document:3']))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that user [user:1] does not have [reader] access to forbidden objects (has unexpected access to: document:2)');
        });

        it('passes when no access', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            // Should not throw
            AssertionHelper::assertUserDoesNotHaveAccessToObjects($this->fake, 'user:1', 'reader', ['document:2', 'document:3']);
        });
    });

    describe('assertUserDoesNotHavePermission', function (): void {
        it('fails when permission exists', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertUserDoesNotHavePermission($this->fake, 'user:1', 'reader', 'document:1'))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that user [user:1] does not have permission [reader] on [document:1]');
        });

        it('passes when permission missing', function (): void {
            // Should not throw
            AssertionHelper::assertUserDoesNotHavePermission($this->fake, 'user:1', 'reader', 'document:1');
        });
    });

    describe('assertUserHasAccessToObjectCount', function (): void {
        it('fails with incorrect count', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertUserHasAccessToObjectCount($this->fake, 'user:1', 'reader', 'document', 2))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that user [user:1] has [reader] access to [2] objects of type [document]. Actual: [1]');
        });

        it('passes with correct count', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:1', 'reader', 'document:2');

            // Should not throw
            AssertionHelper::assertUserHasAccessToObjectCount($this->fake, 'user:1', 'reader', 'document', 2);
        });
    });

    describe('assertUserHasAccessToObjects', function (): void {
        it('fails when access missing', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertUserHasAccessToObjects($this->fake, 'user:1', 'reader', ['document:1', 'document:2']))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that user [user:1] has [reader] access to expected objects (missing access to: document:2)');
        });

        it('passes when access exists', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:1', 'reader', 'document:2');

            // Should not throw
            AssertionHelper::assertUserHasAccessToObjects($this->fake, 'user:1', 'reader', ['document:1', 'document:2']);
        });
    });

    describe('assertUserHasAllPermissions', function (): void {
        it('fails when some permissions missing', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect(fn () => AssertionHelper::assertUserHasAllPermissions($this->fake, 'user:1', ['reader', 'writer'], 'document:1'))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that user [user:1] has all permissions [reader, writer] on [document:1] (missing: writer)');
        });

        it('passes when all permissions exist', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:1', 'writer', 'document:1');

            // Should not throw
            expect(function (): void {
                AssertionHelper::assertUserHasAllPermissions($this->fake, 'user:1', ['reader', 'writer'], 'document:1');
            })->not->toThrow(Exception::class);
        });
    });

    describe('assertUserHasAnyPermission', function (): void {
        it('fails when no permissions exist', function (): void {
            expect(fn () => AssertionHelper::assertUserHasAnyPermission($this->fake, 'user:1', ['reader', 'writer'], 'document:1'))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that user [user:1] has any of the permissions [reader, writer] on [document:1]');
        });

        it('passes when one permission exists', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            // Should not throw
            expect(function (): void {
                AssertionHelper::assertUserHasAnyPermission($this->fake, 'user:1', ['reader', 'writer'], 'document:1');
            })->not->toThrow(Exception::class);
        });
    });

    describe('assertUserHasPermission', function (): void {
        it('fails when permission missing', function (): void {
            expect(fn () => AssertionHelper::assertUserHasPermission($this->fake, 'user:1', 'reader', 'document:1'))
                ->toThrow(AssertionFailedError::class, 'Failed asserting that user [user:1] has permission [reader] on [document:1]');
        });

        it('passes when permission exists', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            // Should not throw
            AssertionHelper::assertUserHasPermission($this->fake, 'user:1', 'reader', 'document:1');
        });
    });
});
