<?php

namespace App\Model\Auth;

class Permission extends \Spatie\Permission\Models\Permission
{
    protected $connection = 'tenant';

    public static function isExists($name)
    {
        if (! self::where('name', $name)->first()) {
            return false;
        }

        return true;
    }

    public static function createIfNotExists($name)
    {
        if (! self::isExists($name)) {
            self::create(['name' => $name]);
        }

        return self::where('name', $name)->first();
    }
}
