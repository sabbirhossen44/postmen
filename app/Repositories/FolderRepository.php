<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\Folder;

class FolderRepository extends Repository
{
    public static function model()
    {
        return Folder::class;
    }

    public static function forCollection(int $collectionId)
    {
        return self::query()->where('collection_id', $collectionId)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
