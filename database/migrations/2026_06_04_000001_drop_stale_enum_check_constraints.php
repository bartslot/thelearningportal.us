<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app was built on SQLite, where `$table->enum()` and later `->change()` to string
 * left no enforced constraint. On Postgres (Supabase), enum() creates a CHECK constraint
 * (`<table>_<column>_check` with an `= ANY (ARRAY[...])` / `IN (...)` body) that the app's
 * own evolving values violate (e.g. lessons.status='previewable', lessons.tone=...).
 *
 * Validation lives in PHP Enums, not the database, so we drop these enum-style CHECK
 * constraints. Only IN-list / ANY-array checks are dropped — numeric/range checks are left
 * intact. Runs on Postgres only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = DB::select(
            "select con.conname, rel.relname as table_name, pg_get_constraintdef(con.oid) as def
             from pg_constraint con
             join pg_class rel on rel.oid = con.conrelid
             join pg_namespace ns on ns.oid = con.connamespace
             where con.contype = 'c'
               and ns.nspname = current_schema()"
        );

        foreach ($checks as $check) {
            // Postgres renders enum() checks as `... = ANY ((ARRAY[...])::text[])`; older
            // ones may use `IN (...)`. Match both; leave numeric/range checks untouched.
            $isEnumStyle = str_contains($check->def, 'ANY (')
                || preg_match('/\bIN \(/i', $check->def) === 1;

            if ($isEnumStyle) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                    $check->table_name,
                    $check->conname
                ));
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: the enum CHECK constraints were never the source of
        // truth (PHP Enums are), and their exact value lists are not reconstructable here.
    }
};
