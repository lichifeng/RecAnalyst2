<?php

namespace RecAnalyst;

/**
 * Miscellaneous utilities for working with RecAnalyst.
 */
class Utils
{
    /**
     * Format a game time as "HH:MM:SS".
     *
     * @param int $time Game time in milliseconds.
     * @param string $format sprintf-style format.
     *     Defaults to %02d:%02d:%02d, for HH:MM:SS.
     * @return string Formatted string, or "-" if the time is 0. (Zero usually
     *     means "did not occur" or "unknown" in recorded game timestamps.)
     */
    public static function formatGameTime($time, $ms_fix = 1000, $format = '%02d:%02d:%02d')
    {
        if ($time <= 0) {
            return '-';
        }
        $hour = (int)($time / $ms_fix / 3600);
        $minute = ((int)($time / $ms_fix / 60)) % 60;
        $second = ((int)($time / $ms_fix)) % 60;
        return sprintf($format, $hour, $minute, $second);
    }

    /**
     * Convert strings in record to UTF-8 encoded
     *
     * @param $str
     * @return string
     */
    public static function stringToUTF8($str, $raw_encoding = 'gbk')
    {
        $utf8_str = mb_convert_encoding($str, "UTF-8", $raw_encoding);
        return $utf8_str;
    }

    /**
     * Convert chat message to array
     *
     * @param $rawMsg
     * @return array
     */
    public static function msgToArray($rawMsg) {
        $msgArray = [];
        foreach ($rawMsg as  $msg) {
            $msgArray[] = $msg->toArray();
        }
        return $msgArray;
    }
}
