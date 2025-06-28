<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    protected $fillable = ['id', 'name', 'email'];

    /**
     * Factory method for creating test users.
     */
    public static function factory(): UserFactory
    {
        return new UserFactory;
    }

    /**
     * Get the authorization object identifier for this user.
     */
    public function authorizationObject(): string
    {
        return "user:{$this->id}";
    }

    /**
     * Get the authorization user identifier.
     */
    public function authorizationUser(): string
    {
        return "user:{$this->id}";
    }
}

/**
 * Simple factory for creating test users.
 */
final class UserFactory
{
    private array $attributes = [];

    public function create(array $attributes = []): User
    {
        $user = new User;
        $user->id = $attributes['id'] ?? random_int(1, 10000);
        $user->name = $attributes['name'] ?? 'Test User';
        $user->email = $attributes['email'] ?? "test{$user->id}@example.com";

        // Simulate saving
        $user->exists = true;

        return $user;
    }

    public function make(array $attributes = []): User
    {
        $user = new User;
        $user->id = $attributes['id'] ?? random_int(1, 10000);
        $user->name = $attributes['name'] ?? 'Test User';
        $user->email = $attributes['email'] ?? "test{$user->id}@example.com";

        return $user;
    }
}
