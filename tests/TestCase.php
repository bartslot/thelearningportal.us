<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Runs before RefreshDatabase migrates — guarantees the app schema + PostGIS exist. */
    protected function beforeRefreshingDatabase()
    {
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::connection('pgsql')->statement('CREATE SCHEMA IF NOT EXISTS app');
    }
}
