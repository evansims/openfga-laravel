<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Contracts\AuthorizationType;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Traits\ResolvesAuthorizationObject;

uses(TestCase::class);

describe('ResolvesAuthorizationObject', function (): void {
    beforeEach(function (): void {
        // Create a class that uses the trait for testing
        $this->resolver = new class {
            use ResolvesAuthorizationObject;

            public function testGetAuthorizationObjectFromModel(Model $model): string
            {
                return $this->getAuthorizationObjectFromModel($model);
            }

            public function testToStringValue($value): string
            {
                return $this->toStringValue($value);
            }
        };
    });

    describe('getAuthorizationObjectFromModel', function (): void {
        it('uses authorizationObject method when available', function (): void {
            $model = new class extends Model {
                protected $table = 'test_models';

                public function authorizationObject(): string
                {
                    return 'custom:object:123';
                }

                public function getKey(): mixed
                {
                    return 123;
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('custom:object:123');
        });

        it('handles authorizationObject returning numeric value', function (): void {
            $model = new class extends Model {
                protected $table = 'test_models';

                public function authorizationObject(): int
                {
                    return 456;
                }

                public function getKey(): mixed
                {
                    return 123;
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('456');
        });

        it('handles authorizationObject returning stringable object', function (): void {
            $stringable = new class {
                public function __toString(): string
                {
                    return 'stringable:789';
                }
            };

            $model = new class extends Model {
                protected $table = 'test_models';

                private object $stringable;

                public function __construct(array $attributes = [])
                {
                    parent::__construct($attributes);
                    $this->stringable = new class {
                        public function __toString(): string
                        {
                            return 'stringable:789';
                        }
                    };
                }

                public function authorizationObject(): object
                {
                    return $this->stringable;
                }

                public function getKey(): mixed
                {
                    return 123;
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('stringable:789');
        });

        it('handles authorizationObject returning non-stringable value', function (): void {
            $model = new class extends Model {
                protected $table = 'test_models';

                public function authorizationObject(): array
                {
                    return ['invalid'];
                }

                public function getKey(): mixed
                {
                    return 123;
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('');
        });

        it('uses authorizationType method when authorizationObject not available', function (): void {
            $model = new class extends Model implements AuthorizationType {
                protected $table = 'test_models';

                public function authorizationType(): string
                {
                    return 'document';
                }

                public function getKey(): mixed
                {
                    return 456;
                }

                public function getKeyType(): string
                {
                    return 'int';
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('document:456');
        });

        it('uses authorizationType with string return value', function (): void {
            $model = new class extends Model implements AuthorizationType {
                protected $table = 'test_models';

                public function authorizationType(): string
                {
                    return 'custom_type';
                }

                public function getKey(): mixed
                {
                    return 789;
                }

                public function getKeyType(): string
                {
                    return 'int';
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('custom_type:789');
        });

        it('falls back to table name and key', function (): void {
            $model = new class extends Model {
                protected $table = 'documents';

                public function getKey(): mixed
                {
                    return 999;
                }

                public function getKeyType(): string
                {
                    return 'int';
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('documents:999');
        });

        it('handles string model keys', function (): void {
            $model = new class extends Model {
                protected $table = 'files';

                public function getKey(): mixed
                {
                    return 'uuid-123-456';
                }

                public function getKeyType(): string
                {
                    return 'string';
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('files:uuid-123-456');
        });

        it('prioritizes authorizationObject over authorizationType', function (): void {
            $model = new class extends Model implements AuthorizationType {
                protected $table = 'test_models';

                public function authorizationObject(): string
                {
                    return 'custom:priority:test';
                }

                public function authorizationType(): string
                {
                    return 'document';
                }

                public function getKey(): mixed
                {
                    return 123;
                }

                public function getKeyType(): string
                {
                    return 'int';
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('custom:priority:test');
        });
    });

    describe('toStringValue', function (): void {
        it('returns string values as-is', function (): void {
            $result = $this->resolver->testToStringValue('test string');
            expect($result)->toBe('test string');
        });

        it('converts integer to string', function (): void {
            $result = $this->resolver->testToStringValue(123);
            expect($result)->toBe('123');
        });

        it('converts float to string', function (): void {
            $result = $this->resolver->testToStringValue(456.789);
            expect($result)->toBe('456.789');
        });

        it('converts stringable objects to string', function (): void {
            $stringable = new class {
                public function __toString(): string
                {
                    return 'stringable object';
                }
            };

            $result = $this->resolver->testToStringValue($stringable);
            expect($result)->toBe('stringable object');
        });

        it('returns empty string for arrays', function (): void {
            $result = $this->resolver->testToStringValue(['invalid']);
            expect($result)->toBe('');
        });

        it('returns empty string for non-stringable objects', function (): void {
            $object = new class {
                // No __toString method
            };

            $result = $this->resolver->testToStringValue($object);
            expect($result)->toBe('');
        });

        it('returns empty string for null', function (): void {
            $result = $this->resolver->testToStringValue(null);
            expect($result)->toBe('');
        });

        it('returns empty string for boolean values', function (): void {
            $result = $this->resolver->testToStringValue(true);
            expect($result)->toBe('');

            $result = $this->resolver->testToStringValue(false);
            expect($result)->toBe('');
        });

        it('handles zero values correctly', function (): void {
            $result = $this->resolver->testToStringValue(0);
            expect($result)->toBe('0');

            $result = $this->resolver->testToStringValue(0.0);
            expect($result)->toBe('0');
        });

        it('handles negative numbers', function (): void {
            $result = $this->resolver->testToStringValue(-123);
            expect($result)->toBe('-123');

            $result = $this->resolver->testToStringValue(-45.67);
            expect($result)->toBe('-45.67');
        });
    });

    describe('integration scenarios', function (): void {
        it('handles complex model hierarchies', function (): void {
            $model = new class extends Model {
                protected $table = 'complex_models';

                public function authorizationObject(): string
                {
                    return $this->getTable() . ':' . $this->getKey() . ':special';
                }

                public function getKey(): mixed
                {
                    return 'complex-123';
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('complex_models:complex-123:special');
        });

        it('throws exception for models with null keys', function (): void {
            $model = new class extends Model {
                protected $table = 'null_key_models';

                public function getKey(): mixed
                {
                    return null;
                }

                public function getKeyType(): string
                {
                    return 'int';
                }
            };

            expect(fn () => $this->resolver->testGetAuthorizationObjectFromModel($model))
                ->toThrow(InvalidArgumentException::class, 'Model key must be int or string, got: NULL');
        });

        it('handles models with composite-like keys', function (): void {
            $model = new class extends Model {
                protected $table = 'composite_models';

                public function authorizationObject(): string
                {
                    return 'composite:' . $this->getKey() . ':suffix';
                }

                public function getKey(): mixed
                {
                    return '123-456-789';
                }
            };

            $result = $this->resolver->testGetAuthorizationObjectFromModel($model);
            expect($result)->toBe('composite:123-456-789:suffix');
        });
    });
});
