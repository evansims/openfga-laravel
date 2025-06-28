<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Traits\HasAuthorization;

// Test user model
final class TestUser extends Model
{
    use HasAuthorization;

    public $exists = true;

    protected $guarded = [];

    protected $table = 'users';

    public function __construct(array $attributes = [])
    {
        // Skip parent constructor to avoid database setup
        $this->fill($attributes);
    }

    public function getKey()
    {
        return $this->id ?? 456;
    }

    // Override to avoid database calls
    protected static function registerModelEvent($event, $callback): void
    {
        // Do nothing
    }
}
