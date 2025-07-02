<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Traits\HasAuthorization;
use Override;

// Test model that uses the trait
final class TestDocument extends Model
{
    use HasAuthorization;

    public $exists = true;

    protected $guarded = [];

    protected $table = 'documents';

    public function __construct(array $attributes = [])
    {
        // Skip parent constructor to avoid database setup
        $this->fill($attributes);
    }

    // Override for testing
    #[Override]
    public function getKey()
    {
        return $this->id ?? 123;
    }

    #[Override]
    public function getKeyName()
    {
        return 'id';
    }

    // Override to avoid database calls
    protected static function registerModelEvent($event, $callback): void
    {
        // Do nothing
    }
}
