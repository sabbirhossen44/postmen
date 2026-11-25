<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\History;

class HistoryRepository extends Repository
{
    public static function model()
    {
        return History::class;
    }

    public static function forUser(int $userId)
    {
        return self::query()->where('user_id', $userId)->latest();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
