<?php

declare(strict_types=1);

use OpenFGA\Laravel\Cache\TaggedCache;

describe('TaggedCache', function (): void {
    it('tests basic cache functionality and configuration handling', function (): void {
        // Test basic instantiation
        $cache = new TaggedCache;
        expect($cache)->toBeInstanceOf(TaggedCache::class);

        // Test with custom configuration
        $customCache = new TaggedCache([
            'prefix' => 'custom',
            'ttl' => 600,
            'enabled' => false,
        ]);
        expect($customCache)->toBeInstanceOf(TaggedCache::class);

        // Test disabled operations return expected values
        expect($customCache->put('key', 'value', ['tag1']))->toBeFalse();
        expect($customCache->get('key', ['tag1']))->toBeNull();
        expect($customCache->forget('key', ['tag1']))->toBeFalse();
        expect($customCache->flush(['tag1']))->toBeFalse();

        // Test permission operations when disabled
        expect($customCache->putPermission('user:123', 'read', 'document:456', true))->toBeFalse();
        expect($customCache->getPermission('user:123', 'read', 'document:456'))->toBeNull();
        expect($customCache->invalidateUser('user:123'))->toBeFalse();
        expect($customCache->invalidateObject('document:456'))->toBeFalse();
        expect($customCache->invalidateRelation('read'))->toBeFalse();
        expect($customCache->invalidateUserType('user'))->toBeFalse();
        expect($customCache->invalidateObjectType('document'))->toBeFalse();
    });
});
