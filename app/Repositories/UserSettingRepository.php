<?php

namespace App\Repositories;

use Arafat\LaravelRepository\Repository;
use App\Models\UserSetting;

class UserSettingRepository extends Repository
{
    public static function model()
    {
        return UserSetting::class;
    }

    public static function forUser(int $userId): ?UserSetting
    {
        return self::query()->where('user_id', $userId)->first();
    }

    /** The row for this user, created with defaults on first use. */
    public static function forUserOrCreate(int $userId): UserSetting
    {
        $settings = self::query()->firstOrCreate(['user_id' => $userId]);

        if ($settings->wasRecentlyCreated) {
            // DB column defaults are not loaded into a freshly created
            // model — refresh so the response carries the real defaults.
            $settings->refresh();
        }

        return $settings;
    }
}
