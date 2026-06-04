<?php

declare(strict_types=1);

namespace App\Models\Corpus;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-mostly view over public.boundaries (PostGIS). The only writer is
 * App\Console\Commands\ImportBoundaries.
 */
class Boundary extends Model
{
    protected $connection = 'pgsql_corpus';

    protected $table = 'boundaries';

    protected $guarded = [];

    protected $casts = ['extra' => 'array'];
}
