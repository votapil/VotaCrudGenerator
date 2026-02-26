<?php

namespace Votapil\VotaCrudGenerator\Commands;

use Illuminate\Console\Command;

class VotaCrudGeneratorCommand extends Command
{
    public $signature = 'votacrudgenerator';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
