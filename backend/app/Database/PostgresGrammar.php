<?php

namespace App\Database;

class PostgresGrammar extends \Illuminate\Database\Query\Grammars\PostgresGrammar
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.uP';
    }
}
