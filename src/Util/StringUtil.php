<?php

namespace App\Util;

class StringUtil
{
    public static function startsWith ($haystack, $needle)
    {
        return (substr($haystack, 0, strlen($needle)) === $needle);
    }

    public static function endsWith($haystack, $needle) {
        $length = strlen($needle);
        if(!$length) {
            return true;
        }
        return substr($haystack, -$length) === $needle;
    }
}
