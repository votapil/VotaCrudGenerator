<?php

namespace Votapil\VotaCrudGenerator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Votapil\VotaCrudGenerator\VotaCrudGenerator
 */
class VotaCrudGenerator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Votapil\VotaCrudGenerator\VotaCrudGenerator::class;
    }
}
