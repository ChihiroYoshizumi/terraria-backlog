<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * MVP は永続 DB を前提にしない (docs/design.md 2.1)。
     * Registry / Mapping の実体は Backlog であり、ここでは何もしない。
     */
    public function run(): void
    {
        //
    }
}
