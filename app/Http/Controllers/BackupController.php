<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupController extends Controller
{
    /**
     * Create and download database backup
     */
    public function download(): JsonResponse
    {
        $this->authorize('manage_backups');

        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $path = storage_path('app/backups/' . $filename);

        // Ensure backups directory exists
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // Get database configuration
        $connection = config('database.default');
        $config = config('database.connections.' . $connection);

        // Create backup using mysqldump
        if ($config['driver'] === 'mysql') {
            $command = [
                'mysqldump',
                '-h', $config['host'],
                '-P', $config['port'] ?? '3306',
                '-u', $config['username'],
                '-p' . $config['password'],
                '--no-tablespaces',
                $config['database'],
            ];

            $process = new Process($command);
            $process->run();

            if (!$process->isSuccessful()) {
                abort(500, 'Failed to create backup: ' . $process->getErrorOutput());
            }

            file_put_contents($path, $process->getOutput());
        } else {
            // For SQLite or other databases, use Laravel's built-in functionality
            $tables = DB::select('SHOW TABLES');
            $output = "-- Backup created on " . now() . "\n\n";
            
            foreach ($tables as $table) {
                $tableName = array_values((array)$table)[0];
                $output .= "-- Table: {$tableName}\n";
                
                // Get CREATE TABLE statement
                $create = DB::selectOne("SHOW CREATE TABLE {$tableName}");
                $output .= $create->{'Create Table'} . ";\n\n";
                
                // Get data
                $rows = DB::table($tableName)->get();
                foreach ($rows as $row) {
                    $values = array_map(function ($value) {
                        return is_null($value) ? 'NULL' : "'" . addslashes($value) . "'";
                    }, (array)$row);
                    
                    $output .= "INSERT INTO {$tableName} (" . implode(', ', array_keys((array)$row)) . ") VALUES (" . implode(', ', $values) . ");\n";
                }
                $output .= "\n";
            }
            
            file_put_contents($path, $output);
        }

        // Return file as download
        $content = file_get_contents($path);
        unlink($path); // Clean up temp file

        return response($content, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($content),
        ]);
    }

    /**
     * Get list of available tables for selective backup
     */
    public function tables(): \Illuminate\Http\JsonResponse
    {
        $this->authorize('manage_backups');

        $tables = DB::select('SHOW TABLES');
        $tableNames = array_map(function ($table) {
            return array_values((array)$table)[0];
        }, $tables);

        return response()->json(['tables' => $tableNames]);
    }

    /**
     * Create selective backup of specific tables
     */
    public function downloadSelective(): JsonResponse
    {
        $this->authorize('manage_backups');

        $tables = request('tables', []);
        if (empty($tables)) {
            abort(400, 'No tables selected for backup');
        }

        $filename = 'backup_selective_' . date('Y-m-d_H-i-s') . '.sql';
        
        $output = "-- Selective backup created on " . now() . "\n";
        $output .= "-- Tables: " . implode(', ', $tables) . "\n\n";

        foreach ($tables as $tableName) {
            // Validate table name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                continue;
            }

            $output .= "-- Table: {$tableName}\n";
            
            try {
                $create = DB::selectOne("SHOW CREATE TABLE {$tableName}");
                $output .= $create->{'Create Table'} . ";\n\n";
                
                $rows = DB::table($tableName)->get();
                foreach ($rows as $row) {
                    $values = array_map(function ($value) {
                        return is_null($value) ? 'NULL' : "'" . addslashes($value) . "'";
                    }, (array)$row);
                    
                    $output .= "INSERT INTO {$tableName} (" . implode(', ', array_keys((array)$row)) . ") VALUES (" . implode(', ', $values) . ");\n";
                }
                $output .= "\n";
            } catch (\Exception $e) {
                $output .= "-- Error backing up {$tableName}: " . $e->getMessage() . "\n\n";
            }
        }

        return response($output, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($output),
        ]);
    }
}
