<?php
/**
 * Copyright 2013 MessageMedia Group
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @copyright 2013 MessageMedia Group
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @see https://messagemedia.github.io/
 */

namespace MessageMedia\shmop;

/**
 * @brief   A simple class with methods for working with PHP's pack and unpack functions.
 *
 * @see http://php.net/manual/en/function.pack.php
 * @see http://php.net/manual/en/function.unpack.php
 */
class Packing {

    /// @todo Add other types listed on http://php.net/manual/en/function.pack.php
    const UINT16 = 'S'; ///< unsigned short (always 16 bit, machine byte order)
    const INT16  = 's'; ///< signed short (always 16 bit, machine byte order)
    const UINT32 = 'L'; ///< unsigned long (always 32 bit, machine byte order)
    const INT32  = 'l'; ///< signed long (always 32 bit, machine byte order)
    const UCHAR  = 'C'; ///< unsigned char
    const CHAR   = 'c'; ///< signed char

    private static $packFormats = array(); ///< Cache of generated pack formats.

    /**
     * @brief   Gets the length of a value when packed into a binary string using
     *          the pack() function.
     *
     * @param   $type   Type of the value being packed.
     *
     * @return  Length of the value when packed.
     *
     * @see     http://php.net/manual/en/function.pack.php
     *
     * @todo    Add other types listed on http://php.net/manual/en/function.pack.php
     */
    public static function getTypeLength($type) {
        switch ($type) {
            case self::CHAR:   return 1;
            case self::UCHAR:  return 1;
            case self::INT16:  return 2;
            case self::UINT16: return 2;
            case self::INT32:  return 4;
            case self::UINT32: return 4;
            default: return 0;
        }
    }

    /**
     * @brief   Gets the length of a set of data when packed using pack with a
     *          format specified by $structure.
     *
     * $structure is expected to be an associative array of name => type pairs.
     *
     * @param   $structure  The structure of the data being packed.
     *
     * @return  Length of the data when packed.
     *
     * @see     http://php.net/manual/en/function.pack.php
     */
    public static function getPackLength($structure) {
        $length = 0;
        foreach ($structure as $type) {
            $length += self::getTypeLength($type);
        }
        return $length;
    }

    /**
     * @brief   Create a pack format based on a structure for packing or unpacking.
     *
     * \a $structure is expected to be an associative array of name => type pairs.
     *
     * \a $id can be any string unique to the \a $structure parameter. It is used to
     * cache the results, such that calling this function a second time with the
     * \a $id and \a $named values, will result in this function returning an array
     * cached from the previous call. This is necessary to prevent a potential (though
     * very small) performance overhead in the metrics classes.
     *
     * @param   $id         An ID to give to this structure so the returned value
     *                      can be stored for fast retrival in subsequent calls.
     * @param   $structure  The structure of the data being packed or unpacked.
     * @param   $named      Whether or not to name each value, ie for use with unpack.
     *
     * @return  Format string to be used in pack() and unpack() functions.
     *
     * @see     http://php.net/manual/en/function.pack.php
     * @see     http://php.net/manual/en/function.unpack.php
     */
    public static function getPackFormat($id, $structure, $named = false) {
        if (isset(self::$packFormats[$id][$named])) {
            return self::$packFormats[$id][$named];
        }
        $format = '';
        foreach ($structure as $item => $type) {
            $format .= $type;
            if ($named) {
                $format .= $item . '/';
            }
        }
        if ($named) {
            $format = substr($format, 0, strlen($format) - 1);
            self::$packFormats[$id][$named] = $format;
            return $format;
        }
        self::$packFormats[$id][$named] = $format;
        return $format;
    }
}
