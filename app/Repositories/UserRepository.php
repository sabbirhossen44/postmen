<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\User;

class UserRepository extends Repository
{
    public static function model()
    {
        return User::class;
    }

    public static function findByEmail(string $email): ?User
    {
        return self::query()->where('email', $email)->first();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
