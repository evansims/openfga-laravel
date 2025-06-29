<?php

declare(strict_types=1);

use OpenFGA\Laravel\Cache\TaggedCache;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('TaggedCache', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->cache = new TaggedCache;
        $this->disabledCache = new TaggedCache(
            config: [
                'prefix' => 'custom',
                'ttl' => 600,
                'enabled' => false,
            ],
        );
        $this->testKey = 'test-key';
        $this->testValue = 'test-value';
        $this->testTags = ['tag1', 'tag2'];
        $this->testUser = 'user:123';
        $this->testRelation = 'read';
        $this->testObject = 'document:456';
    });

    afterEach(function (): void {
        // Clean up any test data
        $this->cache->flush($this->testTags);
        $this->tearDownConfigRestoration();
    });

    it('instantiates with default configuration', function (): void {
        expect($this->cache)->toBeInstanceOf(TaggedCache::class);
    });

    it('instantiates with custom configuration', function (): void {
        expect($this->disabledCache)->toBeInstanceOf(TaggedCache::class);
    });

    describe('when cache is disabled', function (): void {
        it('returns false for put operations', function (): void {
            expect($this->disabledCache->put($this->testKey, $this->testValue, $this->testTags))
                ->toBeFalse();
        });

        it('returns null for get operations', function (): void {
            expect($this->disabledCache->get($this->testKey, $this->testTags))
                ->toBeNull();
        });

        it('returns false for forget operations', function (): void {
            expect($this->disabledCache->forget($this->testKey, $this->testTags))
                ->toBeFalse();
        });

        it('returns false for flush operations', function (): void {
            expect($this->disabledCache->flush($this->testTags))
                ->toBeFalse();
        });

        describe('permission operations', function (): void {
            it('returns false for putPermission when disabled', function (): void {
                expect($this->disabledCache->putPermission($this->testUser, $this->testRelation, $this->testObject, true))
                    ->toBeFalse();
            });

            it('returns null for getPermission when disabled', function (): void {
                expect($this->disabledCache->getPermission($this->testUser, $this->testRelation, $this->testObject))
                    ->toBeNull();
            });

            it('returns false for invalidation operations when disabled', function (): void {
                expect($this->disabledCache->invalidateUser($this->testUser))->toBeFalse();
                expect($this->disabledCache->invalidateObject($this->testObject))->toBeFalse();
                expect($this->disabledCache->invalidateRelation($this->testRelation))->toBeFalse();
                expect($this->disabledCache->invalidateUserType('user'))->toBeFalse();
                expect($this->disabledCache->invalidateObjectType('document'))->toBeFalse();
            });
        });
    });
});
