<?php

namespace kanalumaddela\SteamLogin;

use RuntimeException;

/**
 * @method
 */
class SteamLoginFacade
{
    private static SteamLogin $steamLogin;

    public static function __callStatic(string $name, array $arguments)
    {
        if (empty(static::$steamLogin)) {
            throw new RuntimeException('$steamLogin not binded yet');
        }

        $arguments ??= [];

        return call_user_func_array([static::$steamLogin, $name], $arguments);
    }

    public static function bind(SteamLogin $steamLogin): void
    {
        static::$steamLogin = $steamLogin;
    }
}