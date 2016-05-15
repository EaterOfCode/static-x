<?php
/**
 * Created by PhpStorm.
 * User: eater
 * Date: 5/14/16
 * Time: 9:07 PM
 */

namespace Eater\StaticX\Objects;

use Eater\StaticX\IpWrapper;
use Monolog\Logger;

class DefaultRoute extends Route
{
    /**
     * DefaultRoute constructor.
     * @param Logger $log
     * @param IpWrapper $ipWrapper
     * @param string $via
     */
    public function __construct(Logger $log, $ipWrapper, $via)
    {
        parent::__construct($log, $ipWrapper, 'default', $via, null);
    }
}