<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('ModelKeyHelper', function (): void {
    it('converts integer model key to string', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(123);

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe('123');
        expect($result)->toBeString();
    });

    it('returns string model key unchanged', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn('abc-123');

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe('abc-123');
        expect($result)->toBeString();
    });

    it('handles zero as integer key', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(0);

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe('0');
    });

    it('handles empty string as key', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn('');

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe('');
    });

    it('throws exception for null key', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(null);

        expect(fn () => ModelKeyHelper::stringId($model))
            ->toThrow(InvalidArgumentException::class, 'Model key must be int or string, got: NULL');
    });

    it('throws exception for array key', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(['key' => 'value']);

        expect(fn () => ModelKeyHelper::stringId($model))
            ->toThrow(InvalidArgumentException::class, 'Model key must be int or string, got: array');
    });

    it('throws exception for object key', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(new stdClass);

        expect(fn () => ModelKeyHelper::stringId($model))
            ->toThrow(InvalidArgumentException::class, 'Model key must be int or string, got: object');
    });

    it('throws exception for boolean key', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(true);

        expect(fn () => ModelKeyHelper::stringId($model))
            ->toThrow(InvalidArgumentException::class, 'Model key must be int or string, got: boolean');
    });

    it('throws exception for float key', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(3.14);

        expect(fn () => ModelKeyHelper::stringId($model))
            ->toThrow(InvalidArgumentException::class, 'Model key must be int or string, got: double');
    });

    it('handles large integer keys', function (): void {
        $model = mock(Model::class);
        $largeInt = PHP_INT_MAX;
        $model->shouldReceive('getKey')->andReturn($largeInt);

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe((string) $largeInt);
    });

    it('handles negative integer keys', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn(-123);

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe('-123');
    });

    it('handles UUID string keys', function (): void {
        $model = mock(Model::class);
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $model->shouldReceive('getKey')->andReturn($uuid);

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe($uuid);
    });

    it('handles numeric string keys', function (): void {
        $model = mock(Model::class);
        $model->shouldReceive('getKey')->andReturn('12345');

        $result = ModelKeyHelper::stringId($model);

        expect($result)->toBe('12345');
    });
});
