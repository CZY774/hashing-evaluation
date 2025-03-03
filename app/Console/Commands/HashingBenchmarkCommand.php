<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class HashingBenchmarkCommand extends Command
{
    protected $signature = 'hash:benchmark 
                            {--iterations=100 : Number of iterations for each test}
                            {--passwords=10 : Number of different passwords to test}
                            {--export=csv : Export format (csv or json)}';

    protected $description = 'Benchmark different password hashing algorithms in Laravel';

    // Konfigurasi untuk algoritma yang didukung
    protected $configs = [
        'bcrypt' => [
            'default' => ['rounds' => 10],
            'variations' => [
                ['rounds' => 8],
                ['rounds' => 12],
            ]
        ],
        'argon2id' => [
            'default' => ['memory' => 1024, 'time' => 2, 'threads' => 2],
            'variations' => [
                ['memory' => 1024, 'time' => 4, 'threads' => 2],
                ['memory' => 2048, 'time' => 2, 'threads' => 2],
            ]
        ],
        // Hapus argon2i karena tidak didukung di Laravel 12
    ];

    protected $results = [];
    protected $startTime;

    public function handle()
    {
        $iterations = $this->option('iterations');
        $passwordsCount = $this->option('passwords');
        $exportFormat = $this->option('export');

        $this->startTime = microtime(true);
        $this->info("Starting password hashing benchmark with {$iterations} iterations per test...");
        
        // Generate random passwords for testing
        $passwords = $this->generatePasswords($passwordsCount);
        
        // Run tests for each algorithm with default settings
        $this->info("\n== DEFAULT CONFIGURATION BENCHMARK ==");
        foreach (array_keys($this->configs) as $driver) {
            $this->runTest($driver, $passwords, $iterations, 'default');
        }
        
        // Run tests for each algorithm with parameter variations
        $this->info("\n== PARAMETER VARIATIONS BENCHMARK ==");
        foreach (array_keys($this->configs) as $driver) {
            foreach ($this->configs[$driver]['variations'] as $index => $config) {
                $this->runTest($driver, $passwords, $iterations, "variation_" . ($index + 1), $config);
            }
        }
        
        // Export results
        $this->exportResults($exportFormat);
        
        $totalTime = microtime(true) - $this->startTime;
        $this->info("\nBenchmark completed in " . number_format($totalTime, 2) . " seconds");
        
        return Command::SUCCESS;
    }
    
    protected function runTest($driver, $passwords, $iterations, $configType, $customConfig = null)
    {
        // Apply custom configuration if provided
        $originalConfig = config('hashing');
        
        // Set driver
        config(['hashing.driver' => $driver]);
        
        // Apply custom parameters if provided
        if ($customConfig) {
            foreach ($customConfig as $key => $value) {
                config(["hashing.{$driver}.{$key}" => $value]);
            }
        }
        
        $configLabel = $customConfig ? json_encode($customConfig) : 'default';
        $this->info("\nTesting {$driver} ({$configLabel}):");
        
        // Performance metrics
        $hashTimes = [];
        $verifyTimes = [];
        $memoryUsages = [];
        $hashLengths = [];
        
        // Process each password
        foreach ($passwords as $index => $password) {
            $this->output->write("  Password {$index}: ");
            
            // Hash timing
            $hashedPasswords = [];
            
            $hashStartTime = microtime(true);
            $memBefore = memory_get_usage();
            
            for ($i = 0; $i < $iterations; $i++) {
                $hashedPasswords[] = Hash::make($password);
            }
            
            $memAfter = memory_get_usage();
            $hashEndTime = microtime(true);
            
            $hashTime = ($hashEndTime - $hashStartTime) / $iterations;
            $hashTimes[] = $hashTime;
            
            $memoryUsage = ($memAfter - $memBefore) / $iterations;
            $memoryUsages[] = $memoryUsage;
            
            // Calculate average hash length
            $totalHashLength = 0;
            foreach ($hashedPasswords as $hash) {
                $totalHashLength += strlen($hash);
            }
            $avgHashLength = $totalHashLength / count($hashedPasswords);
            $hashLengths[] = $avgHashLength;
            
            // Verify timing
            $verifyStartTime = microtime(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                Hash::check($password, $hashedPasswords[$i % count($hashedPasswords)]);
            }
            
            $verifyEndTime = microtime(true);
            
            $verifyTime = ($verifyEndTime - $verifyStartTime) / $iterations;
            $verifyTimes[] = $verifyTime;
            
            $this->output->write("hash " . number_format($hashTime * 1000, 2) . " ms, ");
            $this->output->write("verify " . number_format($verifyTime * 1000, 2) . " ms\n");
        }
        
        // Calculate averages
        $avgHashTime = array_sum($hashTimes) / count($hashTimes);
        $avgVerifyTime = array_sum($verifyTimes) / count($verifyTimes);
        $avgMemoryUsage = array_sum($memoryUsages) / count($memoryUsages);
        $avgHashLength = array_sum($hashLengths) / count($hashLengths);
        
        // Display summary for this algorithm
        $this->info("  Summary:");
        $this->info("  - Average hash time: " . number_format($avgHashTime * 1000, 2) . " ms");
        $this->info("  - Average verify time: " . number_format($avgVerifyTime * 1000, 2) . " ms");
        $this->info("  - Memory usage per hash: " . number_format($avgMemoryUsage / 1024, 2) . " KB");
        $this->info("  - Average hash length: " . number_format($avgHashLength, 0) . " characters");
        
        // Store results
        $currentConfig = config("hashing.{$driver}");
        $this->results[] = [
            'algorithm' => $driver,
            'configuration' => $configType,
            'parameters' => json_encode($currentConfig),
            'avg_hash_time_ms' => number_format($avgHashTime * 1000, 2),
            'avg_verify_time_ms' => number_format($avgVerifyTime * 1000, 2),
            'avg_memory_usage_kb' => number_format($avgMemoryUsage / 1024, 2),
            'avg_hash_length' => number_format($avgHashLength, 0),
            'throughput_hash_per_sec' => number_format(1 / $avgHashTime, 2),
            'throughput_verify_per_sec' => number_format(1 / $avgVerifyTime, 2),
        ];
        
        // Restore original configuration
        config(['hashing' => $originalConfig]);
    }
    
    protected function generatePasswords($count)
    {
        $passwords = [];
        $complexities = [
            'simple' => 'password123',
            'medium' => 'P@ssw0rd!2024',
            'complex' => 'X7#9$fGh@2L*pQz&Kb3!',
        ];
        
        // Include fixed complexity passwords
        foreach ($complexities as $complexity => $password) {
            $passwords[] = $password;
        }
        
        // Generate random passwords to reach desired count
        for ($i = count($passwords); $i < $count; $i++) {
            $length = rand(8, 20);
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
            $password = '';
            for ($j = 0; $j < $length; $j++) {
                $password .= $chars[rand(0, strlen($chars) - 1)];
            }
            $passwords[] = $password;
        }
        
        return $passwords;
    }
    
    protected function exportResults($format)
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "hash_benchmark_results_{$timestamp}";
        
        switch ($format) {
            case 'json':
                $path = storage_path("app/{$filename}.json");
                file_put_contents($path, json_encode($this->results, JSON_PRETTY_PRINT));
                $this->info("Results exported to {$path}");
                break;
                
            case 'csv':
            default:
                $path = storage_path("app/{$filename}.csv");
                $fp = fopen($path, 'w');
                
                // Write header
                fputcsv($fp, array_keys($this->results[0]));
                
                // Write data
                foreach ($this->results as $result) {
                    fputcsv($fp, $result);
                }
                
                fclose($fp);
                $this->info("Results exported to {$path}");
                break;
        }
    }
}