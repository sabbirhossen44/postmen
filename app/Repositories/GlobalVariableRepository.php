<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\GlobalVariable;

class GlobalVariableRepository extends Repository
{
    public static function model()
    {
        return GlobalVariable::class;
    }

    public static function forUser(int $userId)
    {
        return self::query()->where('user_id', $userId)->orderBy('key')->get();
    }

    public static function findForUser(int $id, int $userId): ?GlobalVariable
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
