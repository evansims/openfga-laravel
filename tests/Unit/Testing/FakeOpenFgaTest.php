<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\FakeOpenFga;
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

uses(TestCase::class);

describe('FakeOpenFga', function (): void {
    beforeEach(function (): void {
        $this->fake = new FakeOpenFga;
    });

    describe('assertions', function (): void {
        describe('assertBatchWritten', function (): void {
            it('fails when no batch performed', function (): void {
                expect(fn () => $this->fake->assertBatchWritten())
                    ->toThrow(AssertionFailedError::class, 'Failed asserting that a batch write was performed');
            });

            it('passes when batch performed', function (): void {
                $this->fake->writeBatch([['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1']]);

                // Should not throw
                expect(function (): void {
                    $this->fake->assertBatchWritten();
                })->not->toThrow(Exception::class);
            });
        });

        describe('assertCheckCount', function (): void {
            it('fails with incorrect count', function (): void {
                $this->fake->check('user:1', 'reader', 'document:1');

                expect(fn () => $this->fake->assertCheckCount(2))
                    ->toThrow(AssertionFailedError::class, 'Failed asserting that [2] checks were performed. Actual: [1]');
            });

            it('passes with correct count', function (): void {
                $this->fake->check('user:1', 'reader', 'document:1');
                $this->fake->check('user:1', 'writer', 'document:1');

                // Should not throw
                expect(function (): void {
                    $this->fake->assertCheckCount(2);
                })->not->toThrow(Exception::class);
            });
        });

        describe('assertChecked', function (): void {
            it('fails when check not performed', function (): void {
                expect(fn () => $this->fake->assertChecked('user:1', 'reader', 'document:1'))
                    ->toThrow(AssertionFailedError::class, 'Failed asserting that check [reader] was performed for [user:1] on [document:1]');
            });

            it('passes when check performed', function (): void {
                $this->fake->check('user:1', 'reader', 'document:1');

                // Should not throw
                expect(function (): void {
                    $this->fake->assertChecked('user:1', 'reader', 'document:1');
                })->not->toThrow(Exception::class);
            });
        });

        describe('assertGranted', function (): void {
            it('fails when permission missing', function (): void {
                expect(fn () => $this->fake->assertGranted('user:1', 'reader', 'document:1'))
                    ->toThrow(AssertionFailedError::class, 'Failed asserting that permission [reader] was granted to [user:1] on [document:1]');
            });

            it('passes when permission exists', function (): void {
                $this->fake->grant('user:1', 'reader', 'document:1');

                // Should not throw
                expect(function (): void {
                    $this->fake->assertGranted('user:1', 'reader', 'document:1');
                })->not->toThrow(Exception::class);
            });
        });

        describe('assertNoBatchWrites', function (): void {
            it('fails when batch performed', function (): void {
                $this->fake->writeBatch([['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1']]);

                expect(fn () => $this->fake->assertNoBatchWrites())
                    ->toThrow(AssertionFailedError::class, 'Failed asserting that no batch writes were performed');
            });

            it('passes when no batches performed', function (): void {
                // Should not throw
                expect(function (): void {
                    $this->fake->assertNoBatchWrites();
                })->not->toThrow(Exception::class);
            });
        });

        describe('assertNoChecks', function (): void {
            it('fails when checks performed', function (): void {
                $this->fake->check('user:1', 'reader', 'document:1');

                expect(fn () => $this->fake->assertNoChecks())
                    ->toThrow(AssertionFailedError::class, 'Failed asserting that [0] checks were performed. Actual: [1]');
            });

            it('passes when no checks performed', function (): void {
                // Should not throw
                expect(function (): void {
                    $this->fake->assertNoChecks();
                })->not->toThrow(Exception::class);
            });
        });

        describe('assertNotGranted', function (): void {
            it('fails when permission exists', function (): void {
                $this->fake->grant('user:1', 'reader', 'document:1');

                expect(fn () => $this->fake->assertNotGranted('user:1', 'reader', 'document:1'))
                    ->toThrow(AssertionFailedError::class, 'Failed asserting that permission [reader] was not granted to [user:1] on [document:1]');
            });

            it('passes when permission missing', function (): void {
                // Should not throw
                expect(function (): void {
                    $this->fake->assertNotGranted('user:1', 'reader', 'document:1');
                })->not->toThrow(Exception::class);
            });
        });
    });

    describe('functionality', function (): void {
        it('can expand relations', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:2', 'reader', 'document:1');

            $expansion = $this->fake->expand('document:1', 'reader');

            expect($expansion)->toHaveKey('tree');
            expect($expansion['tree'])->toHaveKey('root');
            expect($expansion['tree']['root'])->toHaveKey('leaf');
            expect($expansion['tree']['root']['leaf'])->toHaveKey('users');

            $users = $expansion['tree']['root']['leaf']['users'];
            expect($users)->toHaveCount(2);
            expect($users)->toContain('user:1');
            expect($users)->toContain('user:2');
        });

        it('can grant and check permissions', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');

            expect($this->fake->check('user:1', 'reader', 'document:1'))->toBeTrue();
            expect($this->fake->check('user:1', 'writer', 'document:1'))->toBeFalse();
            expect($this->fake->check('user:2', 'reader', 'document:1'))->toBeFalse();
        });

        it('can list objects', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->grant('user:1', 'reader', 'document:2');
            $this->fake->grant('user:1', 'writer', 'document:1');

            $objects = $this->fake->listObjects('user:1', 'reader', 'document');
            expect($objects)->toHaveCount(2);
            expect($objects)->toContain('document:1');
            expect($objects)->toContain('document:2');

            $objects = $this->fake->listObjects('user:1', 'writer', 'document');
            expect($objects)->toHaveCount(1);
            expect($objects)->toContain('document:1');
        });

        it('can mock check responses', function (): void {
            $this->fake->mockCheck('user:1', 'admin', 'system:1', true);

            expect($this->fake->check('user:1', 'admin', 'system:1'))->toBeTrue();
            expect($this->fake->check('user:1', 'admin', 'system:2'))->toBeFalse();
        });

        it('can mock list objects responses', function (): void {
            $this->fake->mockListObjects('user:1', 'reader', 'document', ['document:1', 'document:2']);

            $objects = $this->fake->listObjects('user:1', 'reader', 'document');
            expect($objects)->toEqual(['document:1', 'document:2']);
        });

        it('can perform batch writes', function (): void {
            $writes = [
                ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'reader', 'object' => 'document:1'],
            ];

            $deletes = [
                ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:1'],
            ];

            $this->fake->grant('user:3', 'reader', 'document:1');
            expect($this->fake->check('user:3', 'reader', 'document:1'))->toBeTrue();

            $this->fake->writeBatch($writes, $deletes);

            expect($this->fake->check('user:1', 'reader', 'document:1'))->toBeTrue();
            expect($this->fake->check('user:2', 'reader', 'document:1'))->toBeTrue();
            expect($this->fake->check('user:3', 'reader', 'document:1'))->toBeFalse();

            $writeBatch = $this->fake->getWrites();
            expect($writeBatch)->toHaveCount(1);
            expect($writeBatch[0]['writes'])->toEqual($writes);
            expect($writeBatch[0]['deletes'])->toEqual($deletes);
        });

        it('can reset all data', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            $this->fake->check('user:1', 'reader', 'document:1');
            $this->fake->mockCheck('user:2', 'writer', 'document:2', true);

            $this->fake->reset();

            expect($this->fake->getTuples())->toBeEmpty();
            expect($this->fake->getChecks())->toBeEmpty();
            expect($this->fake->check('user:2', 'writer', 'document:2'))->toBeFalse();
        });

        it('can reset failure state', function (): void {
            $this->fake->shouldFail();
            $this->fake->shouldSucceed();

            // Should not throw
            expect($this->fake->check('user:1', 'reader', 'document:1'))->toBeFalse();
        });

        it('can revoke permissions', function (): void {
            $this->fake->grant('user:1', 'reader', 'document:1');
            expect($this->fake->check('user:1', 'reader', 'document:1'))->toBeTrue();

            $this->fake->revoke('user:1', 'reader', 'document:1');
            expect($this->fake->check('user:1', 'reader', 'document:1'))->toBeFalse();
        });

        it('can simulate custom failures', function (): void {
            $customException = new InvalidArgumentException('Custom failure message');
            $this->fake->shouldFail($customException);

            expect(fn () => $this->fake->check('user:1', 'reader', 'document:1'))
                ->toThrow(InvalidArgumentException::class, 'Custom failure message');
        });

        it('can simulate failures', function (): void {
            $this->fake->shouldFail();

            expect(fn () => $this->fake->check('user:1', 'reader', 'document:1'))
                ->toThrow(RuntimeException::class, 'Fake OpenFGA check failed');
        });

        it('records check operations', function (): void {
            $this->fake->check('user:1', 'reader', 'document:1');
            $this->fake->check('user:1', 'writer', 'document:1');

            $checks = $this->fake->getChecks();
            expect($checks)->toHaveCount(2);

            expect($checks[0]['user'])->toBe('user:1');
            expect($checks[0]['relation'])->toBe('reader');
            expect($checks[0]['object'])->toBe('document:1');
            expect($checks[0]['result'])->toBeFalse();

            expect($checks[1]['user'])->toBe('user:1');
            expect($checks[1]['relation'])->toBe('writer');
            expect($checks[1]['object'])->toBe('document:1');
            expect($checks[1]['result'])->toBeFalse();
        });
    });
});
