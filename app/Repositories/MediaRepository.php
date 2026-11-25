<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\Media;

class MediaRepository extends Repository
{
    public static function model()
    {
        return Media::class;
    }

    public static function findForUser(int $id, int $userId): ?Media
    {
        return self::query()->where('id', $id)->where('user_id', $userId)->first();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
