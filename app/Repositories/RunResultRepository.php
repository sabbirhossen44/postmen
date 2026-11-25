<?php

namespace App\Repositories;

use App\Models\RunResult;
use Arafat\LaravelRepository\Repository;

class RunResultRepository extends Repository
{
    public static function model()
    {
        return RunResult::class;
    }

    public static function forRun(int $runId)
    {
        return self::query()->where('run_id', $runId)
            ->orderBy('iteration')->orderBy('id')->get();
    }

    public static function delete($primaryKey)
    {
        // The package's base delete() calls query()->destroy(), which does
        // not exist on the Eloquent builder in Laravel 13.
        return static::query()->whereKey($primaryKey)->delete();
    }
}
