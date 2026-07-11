<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SqliteToMariaDbSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Temporarily disable foreign key checks in MariaDB to avoid insert order issues
        Schema::connection('mysql')->disableForeignKeyConstraints();

        // 2. Get all table names from the SQLite database
        $tables = DB::connection('sqlite')->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

        foreach ($tables as $table) {
            $tableName = $table->name;

            $this->command->info("Migrating table: {$tableName}");

            // Clear the existing MariaDB table (useful for the 'migrations' table or if you rerun this)
            DB::connection('mysql')->table($tableName)->truncate();

            $batch = [];

            // 3. Use cursor() to stream data (prevents memory exhaustion on large tables)
            foreach (DB::connection('sqlite')->table($tableName)->cursor() as $row) {
                // Convert the stdClass object to an array
                $batch[] = (array) $row;

                // Insert in batches of 500
                if (count($batch) >= 500) {
                    DB::connection('mysql')->table($tableName)->insert($batch);
                    $batch = []; // Reset batch
                }
            }

            // Insert any remaining rows that didn't make up a full batch of 500
            if (!empty($batch)) {
                DB::connection('mysql')->table($tableName)->insert($batch);
            }
        }

        // 4. Re-enable foreign key constraints
        Schema::connection('mysql')->enableForeignKeyConstraints();

        $this->command->info('🎉 Data migration from SQLite to MariaDB completed successfully!');
    }
}
