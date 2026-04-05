<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Données géographiques depuis le dépôt embarqué
 * `backend/countries-states-cities-database` :
 * - countries.json, states.json à la racine du dossier
 * - csv/cities.csv pour les villes (fichier volumineux)
 *
 * Surcharge : LOCATION_JSON_PATH = répertoire racine du dataset ;
 * LOCATION_CSV_PATH = répertoire contenant cities.csv (défaut : {LOCATION_JSON_PATH}/csv).
 */
class LocationSeeder extends Seeder
{
    private function dataRoot(): string
    {
        return rtrim((string) env('LOCATION_JSON_PATH', base_path('countries-states-cities-database')), '/\\');
    }

    private function csvRoot(): string
    {
        $override = env('LOCATION_CSV_PATH');

        return $override !== null && $override !== ''
            ? rtrim((string) $override, '/\\')
            : $this->dataRoot().DIRECTORY_SEPARATOR.'csv';
    }

    public function run(): void
    {
        $dir = $this->dataRoot();
        $countriesFile = "{$dir}/countries.json";
        $statesFile = "{$dir}/states.json";
        $citiesCsv = $this->csvRoot().DIRECTORY_SEPARATOR.'cities.csv';

        foreach ([$countriesFile, $statesFile] as $f) {
            if (! file_exists($f)) {
                $this->command?->error("Fichier introuvable : {$f}");

                return;
            }
        }

        if (! file_exists($citiesCsv)) {
            $this->command?->error("Fichier introuvable : {$citiesCsv}");

            return;
        }

        $this->command?->info("Dataset : {$dir}");

        Schema::disableForeignKeyConstraints();

        $this->seedCountries($countriesFile);
        $this->seedStates($statesFile);
        $this->seedCitiesFromCsv($citiesCsv);

        Schema::enableForeignKeyConstraints();

        $this->command?->info('Seed localisation terminé (pays, états, villes).');
    }

    private function seedCountries(string $file): void
    {
        DB::table('countries')->truncate();
        $this->command?->info('Pays…');

        $items = json_decode(file_get_contents($file), true);
        if (! is_array($items)) {
            $this->command?->error('countries.json invalide.');

            return;
        }

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

        $this->command?->info(count($rows).' pays (emoji / emojiU inclus).');
    }

    private function seedStates(string $file): void
    {
        DB::table('states')->truncate();
        $this->command?->info('États / régions…');

        $items = json_decode(file_get_contents($file), true);
        if (! is_array($items)) {
            $this->command?->error('states.json invalide.');

            return;
        }

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

        $this->command?->info("{$count} états insérés.");
    }

    private function seedCitiesFromCsv(string $file): void
    {
        DB::table('cities')->truncate();
        $this->command?->info('Villes (CSV, peut prendre une minute)…');

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->command?->error("Impossible d'ouvrir : {$file}");

            return;
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            $this->command?->error('cities.csv vide ou illisible.');

            return;
        }

        $count = 0;
        $now = now()->toDateTimeString();
        $batch = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, $line);
            if ($row === false) {
                continue;
            }

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
                    $this->command?->info("  … {$count} villes");
                }
            }
        }

        fclose($handle);

        if (count($batch) > 0) {
            DB::table('cities')->insert($batch);
            $count += count($batch);
        }

        $this->command?->info("{$count} villes insérées.");
    }

    private function dec(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
