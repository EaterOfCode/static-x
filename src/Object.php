<?php

namespace Eater\StaticX;


interface Object
{
    /**
     * Applies the object
     * @param boolean $dryRun
     */
    public function apply($dryRun);

    /**
     * Reverts the object
     * @param boolean $dryRun
     */
    public function revert($dryRun);
}