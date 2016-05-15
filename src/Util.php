<?php
/**
 * Created by PhpStorm.
 * User: eater
 * Date: 5/14/16
 * Time: 10:11 PM
 */

namespace Eater\StaticX;


class Util
{
    /**
     * @param $cidr
     * @return boolean
     */
    public function validateCidr($cidr) {
        $parts = explode('/', $cidr);

        if (count($parts) > 2) {
            return false;
        }

        $address = $parts[0];

        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (count($parts) === 1) {
            return true;
        }


        $mask = $parts[1];

        if (!filter_var($mask, FILTER_VALIDATE_INT)) {
            return false;
        }

        $mask = intval($mask);

        if (strpos($address, ':') === false) {
            if ($mask > 32 || $mask < 0) {
                return false;
            }
        } else {
            if ($mask > 128 || $mask < 0) {
                return false;
            }
        }

        return true;
    }
}