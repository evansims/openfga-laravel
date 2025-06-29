<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use OpenFGA\Laravel\Authorization\OpenFgaPolicy;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('OpenFgaPolicy', function (): void {
    it('is an abstract class', function (): void {
        $reflection = new ReflectionClass(OpenFgaPolicy::class);
        expect($reflection->isAbstract())->toBeTrue();
    });

    it('requires OpenFgaManager in constructor', function (): void {
        $reflection = new ReflectionClass(OpenFgaPolicy::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();
        expect($constructor->getNumberOfParameters())->toBe(1);

        $param = $constructor->getParameters()[0];
        expect($param->getName())->toBe('manager');
        expect($param->getType()->getName())->toBe(OpenFgaManager::class);
    });

    it('has protected can, canAll, and canAny methods', function (): void {
        $reflection = new ReflectionClass(OpenFgaPolicy::class);

        expect($reflection->hasMethod('can'))->toBeTrue();
        expect($reflection->getMethod('can')->isProtected())->toBeTrue();

        expect($reflection->hasMethod('canAll'))->toBeTrue();
        expect($reflection->getMethod('canAll')->isProtected())->toBeTrue();

        expect($reflection->hasMethod('canAny'))->toBeTrue();
        expect($reflection->getMethod('canAny')->isProtected())->toBeTrue();
    });

    it('stores manager as protected property', function (): void {
        $reflection = new ReflectionClass(OpenFgaPolicy::class);

        expect($reflection->hasProperty('manager'))->toBeTrue();

        $property = $reflection->getProperty('manager');
        expect($property->isProtected())->toBeTrue();
    });

    it('has all required protected methods', function (): void {
        $reflection = new ReflectionClass(OpenFgaPolicy::class);

        $protectedMethods = [
            'can', 'canAll', 'canAny',
            'resolveUserId', 'resolveObject',
            'getResourceType', 'inferResourceType', 'objectId',
        ];

        foreach ($protectedMethods as $method) {
            expect($reflection->hasMethod($method))->toBeTrue();
            expect($reflection->getMethod($method)->isProtected())->toBeTrue();
        }
    });

    describe('method signatures', function (): void {
        it('can method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('can');

            expect($method->getNumberOfParameters())->toBe(4);

            $params = $method->getParameters();
            expect($params[0]->getName())->toBe('user');
            expect($params[0]->getType()->getName())->toBe(Authenticatable::class);

            expect($params[1]->getName())->toBe('relation');
            expect($params[1]->getType()->getName())->toBe('string');

            expect($params[2]->getName())->toBe('resource');
            expect($params[2]->hasType())->toBeFalse(); // mixed type

            expect($params[3]->getName())->toBe('connection');
            expect($params[3]->isOptional())->toBeTrue();
            expect($params[3]->getDefaultValue())->toBeNull();
        });

        it('canAll method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('canAll');

            expect($method->getNumberOfParameters())->toBe(4);

            $params = $method->getParameters();
            expect($params[0]->getName())->toBe('user');
            expect($params[0]->getType()->getName())->toBe(Authenticatable::class);

            expect($params[1]->getName())->toBe('relations');
            expect($params[1]->getType()->getName())->toBe('array');

            expect($params[2]->getName())->toBe('resource');
            expect($params[2]->hasType())->toBeFalse(); // mixed type

            expect($params[3]->getName())->toBe('connection');
            expect($params[3]->isOptional())->toBeTrue();
            expect($params[3]->getDefaultValue())->toBeNull();
        });

        it('canAny method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('canAny');

            expect($method->getNumberOfParameters())->toBe(4);

            $params = $method->getParameters();
            expect($params[0]->getName())->toBe('user');
            expect($params[0]->getType()->getName())->toBe(Authenticatable::class);

            expect($params[1]->getName())->toBe('relations');
            expect($params[1]->getType()->getName())->toBe('array');

            expect($params[2]->getName())->toBe('resource');
            expect($params[2]->hasType())->toBeFalse(); // mixed type

            expect($params[3]->getName())->toBe('connection');
            expect($params[3]->isOptional())->toBeTrue();
            expect($params[3]->getDefaultValue())->toBeNull();
        });

        it('resolveUserId method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('resolveUserId');

            expect($method->getNumberOfParameters())->toBe(1);

            $param = $method->getParameters()[0];
            expect($param->getName())->toBe('user');
            expect($param->getType()->getName())->toBe(Authenticatable::class);

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('string');
        });

        it('resolveObject method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('resolveObject');

            expect($method->getNumberOfParameters())->toBe(1);

            $param = $method->getParameters()[0];
            expect($param->getName())->toBe('resource');
            expect($param->hasType())->toBeFalse(); // mixed type

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('string');
        });

        it('objectId method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('objectId');

            expect($method->getNumberOfParameters())->toBe(1);

            $param = $method->getParameters()[0];
            expect($param->getName())->toBe('id');
            expect($param->hasType())->toBeFalse(); // mixed type

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('string');
        });

        it('getResourceType method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('getResourceType');

            expect($method->getNumberOfParameters())->toBe(0);

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('string');
        });

        it('inferResourceType method has correct signature', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('inferResourceType');

            expect($method->getNumberOfParameters())->toBe(0);

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('string');
        });
    });

    describe('method return types', function (): void {
        it('can method returns bool', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('can');

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('bool');
        });

        it('canAll method returns bool', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('canAll');

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('bool');
        });

        it('canAny method returns bool', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $method = $reflection->getMethod('canAny');

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('bool');
        });
    });

    describe('documentation and annotations', function (): void {
        it('has proper class documentation', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);
            $docComment = $reflection->getDocComment();

            expect($docComment)->toContain('Base policy class that provides OpenFGA integration');
        });

        it('has @throws annotations for exception handling', function (): void {
            $reflection = new ReflectionClass(OpenFgaPolicy::class);

            $canMethod = $reflection->getMethod('can');
            $canDoc = $canMethod->getDocComment();
            expect($canDoc)->toContain('@throws');

            $canAllMethod = $reflection->getMethod('canAll');
            $canAllDoc = $canAllMethod->getDocComment();
            expect($canAllDoc)->toContain('@throws');

            $canAnyMethod = $reflection->getMethod('canAny');
            $canAnyDoc = $canAnyMethod->getDocComment();
            expect($canAnyDoc)->toContain('@throws');
        });
    });
});
