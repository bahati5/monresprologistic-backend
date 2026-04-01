<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocationSeeder extends Seeder
{
    /**
     * Dossier contenant countries.json, states.json, states+cities.json
     * (à la racine du dépôt countries-states-cities-database, pas sous json/).
     */
    private function jsonPath(): string
    {
        return env('LOCATION_JSON_PATH', base_path('countries-states-cities-database'));
    }

    public function run(): void
    {
        $dir = $this->jsonPath();
        $this->command->info("Reading JSON from: {$dir}");

        $countriesFile = "{$dir}/countries.json";
        $statesFile = "{$dir}/states.json";
        $citiesFile = "{$dir}/states+cities.json";

        foreach ([$countriesFile, $statesFile] as $f) {
            if (! file_exists($f)) {
                $this->command->error("File not found: {$f}");
                return;
            }
        }

        Schema::disableForeignKeyConstraints();

        $this->seedCountries($countriesFile);
        $this->seedStates($statesFile);
        $this->seedCities($citiesFile);

        Schema::enableForeignKeyConstraints();

        $this->command->info('Location seeding complete.');
    }

    private function seedCountries(string $file): void
    {
        DB::table('countries')->truncate();
        $this->command->info('Seeding countries…');

        $items = json_decode(file_get_contents($file), true);
        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($items as $c) {
            $rows[] = [
                'id' => (int) $c['id'],
                'name' => $c['name'],
                'code' => $c['iso2'] ?? $c['iso3'] ?? '',
                'iso2' => $c['iso2'] ?? null,
                'iso3' => $c['iso3'] ?? null,
                'numeric_code' => $c['numeric_code'] ?? null,
                'phonecode' => $c['phonecode'] ?? null,
                'capital' => $c['capital'] ?? null,
                'currency' => $c['currency'] ?? null,
                'currency_name' => $c['currency_name'] ?? null,
                'currency_symbol' => $c['currency_symbol'] ?? null,
                'native' => $c['native'] ?? null,
                'nationality' => $c['nationality'] ?? null,
                'latitude' => $this->dec($c['latitude'] ?? null),
                'longitude' => $this->dec($c['longitude'] ?? null),
                'emoji' => $c['emoji'] ?? null,
                'emojiU' => $c['emojiU'] ?? null,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('countries')->insert($chunk);
        }

        $this->command->info(count($rows) . ' countries inserted.');
    }

    private function seedStates(string $file): void
    {
        DB::table('states')->truncate();
        $this->command->info('Seeding states…');

        $items = json_decode(file_get_contents($file), true);
        $now = now()->toDateTimeString();
        $batch = [];
        $count = 0;

        foreach ($items as $s) {
            $batch[] = [
                'id' => (int) $s['id'],
                'country_id' => (int) $s['country_id'],
                'name' => $s['name'],
                'code' => $s['iso2'] ?? null,
                'iso2' => $s['iso2'] ?? null,
                'type' => $s['type'] ?? null,
                'latitude' => $this->dec($s['latitude'] ?? null),
                'longitude' => $this->dec($s['longitude'] ?? null),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('states')->insert($batch);
                $count += count($batch);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            DB::table('states')->insert($batch);
            $count += count($batch);
        }

        $this->command->info("{$count} states inserted.");
    }

    private function seedCities(string $_jsonFallback): void
    {
        DB::table('cities')->truncate();
        $this->command->info('Seeding cities (this may take a minute)…');

        $csvFile = rtrim((string) env('LOCATION_CSV_PATH', base_path('countries-states-cities-database/csv')), '/\\') . '/cities.csv';
        if (file_exists($csvFile)) {
            $this->seedCitiesFromCsv($csvFile);
        } else {
            $this->command->error('No cities CSV found.');
        }
    }

    private function seedCitiesFromCsv(string $file): void
    {
        $handle = fopen($file, 'r');
        $headers = fgetcsv($handle);
        $count = 0;
        $now = now()->toDateTimeString();
        $batch = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, $line);

            $batch[] = [
                'id' => (int) $row['id'],
                'state_id' => (int) $row['state_id'],
                'country_id' => (int) $row['country_id'],
                'country_code' => $row['country_code'] ?? null,
                'name' => $row['name'],
                'latitude' => $this->dec($row['latitude'] ?? null),
                'longitude' => $this->dec($row['longitude'] ?? null),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 2000) {
                DB::table('cities')->insert($batch);
                $count += count($batch);
                $batch = [];

                if ($count % 20000 === 0) {
                    $this->command->info("  … {$count} cities inserted");
                }
            }
        }
        fclose($handle);

        if (count($batch) > 0) {
            DB::table('cities')->insert($batch);
            $count += count($batch);
        }

        $this->command->info("{$count} cities inserted.");
    }

    private function dec(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
