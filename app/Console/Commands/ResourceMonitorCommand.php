<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResourceMonitorCommand extends Command
{
    protected $signature = 'hash:resource-test
                            {algorithm=bcrypt : The hashing algorithm to test (bcrypt, argon2id, argon2i)}
                            {--duration=60 : Duration of the test in seconds}
                            {--users=1000 : Number of user passwords to hash}';

    protected $description = 'Test resource usage of password hashing under load';

    protected $startMemory;
    protected $startTime;
    protected $resourceData = [];
    protected $interval = 1; // seconds between measurements

    public function handle()
    {
        $algorithm = $this->argument('algorithm');
        $duration = $this->option('duration');
        $userCount = $this->option('users');
        
        // Validate algorithm
        if (!in_array($algorithm, ['bcrypt', 'argon2id', 'argon2i'])) {
            $this->error("Invalid algorithm. Please choose bcrypt, argon2id, or argon2i.");
            return Command::FAILURE;
        }
        
        // Set up the hashing driver
        config(['hashing.driver' => $algorithm]);
        
        $this->info("Starting resource monitoring for {$algorithm} hashing...");
        $this->info("Duration: {$duration} seconds | Simulating {$userCount} users");
        
        // Initialize monitoring
        $this->startMemory = memory_get_usage();
        $this->startTime = microtime(true);
        
        // Generate test data
        $passwords = $this->generatePasswords($userCount);
        
        // Start resource monitoring in background
        $this->monitorResources($duration);
        
        // Perform continuous hashing operations
        $hashCount = 0;
        $endTime = $this->startTime + $duration;
        
        $this->output->write("\nHashing passwords");
        
        while (microtime(true) < $endTime) {
            $index = $hashCount % count($passwords);
            $hash = Hash::make($passwords[$index]);
            
            // Verify about 20% of hashes (simulating login)
            if (rand(1, 5) == 1) {
                Hash::check($passwords[$index], $hash);
            }
            
            $hashCount++;
            
            // Visual progress indicator
            if ($hashCount % 10 == 0) {
                $this->output->write(".");
            }
            
            // Small sleep to prevent CPU from maxing out
            if ($hashCount % 100 == 0) {
                usleep(1000); // 1ms
            }
        }
        
        $elapsedTime = microtime(true) - $this->startTime;
        $hashesPerSecond = $hashCount / $elapsedTime;
        
        $this->newLine(2);
        $this->info("Test completed!");
        $this->info("Hashed {$hashCount} passwords in {$elapsedTime} seconds");
        $this->info("Performance: " . number_format($hashesPerSecond, 2) . " hashes/second");
        
        // Export resource data
        $this->exportResourceData($algorithm);
        
        return Command::SUCCESS;
    }
    
    protected function monitorResources($duration)
    {
        $this->info("\nMonitoring system resources...");
        
        // Record initial state
        $this->recordResourceUsage();
        
        // Schedule future measurements
        $measurementCount = floor($duration / $this->interval);
        
        for ($i = 1; $i <= $measurementCount; $i++) {
            // Use pcntl_alarm if available, otherwise we'll rely on the main loop timing
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm($i * $this->interval);
            }
        }
        
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, function () {
                $this->recordResourceUsage();
            });
        } else {
            // Fall back to manual timing in the main loop
            $this->info("Note: Using manual resource monitoring (pcntl not available)");
        }
    }
    
    protected function recordResourceUsage()
    {
        $timestamp = microtime(true) - $this->startTime;
        $memory = memory_get_usage();
        $peakMemory = memory_get_peak_usage();
        
        // Get CPU usage if possible
        $cpuUsage = null;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpuUsage = $load[0];
        }
        
        $this->resourceData[] = [
            'timestamp' => number_format($timestamp, 2),
            'memory_mb' => number_format(($memory / 1024 / 1024), 2),
            'peak_memory_mb' => number_format(($peakMemory / 1024 / 1024), 2),
            'memory_diff_mb' => number_format((($memory - $this->startMemory) / 1024 / 1024), 2),
            'cpu_load' => $cpuUsage,
        ];
    }
    
    protected function exportResourceData($algorithm)
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "hash_resource_usage_{$algorithm}_{$timestamp}.csv";
        $path = storage_path("app/{$filename}");
        
        $fp = fopen($path, 'w');
        
        // Write header
        fputcsv($fp, array_keys($this->resourceData[0]));
        
        // Write data
        foreach ($this->resourceData as $data) {
            fputcsv($fp, $data);
        }
        
        fclose($fp);
        
        $this->info("Resource usage data exported to {$path}");
    }
    
    protected function generatePasswords($count)
    {
        $passwords = [];
        
        for ($i = 0; $i < $count; $i++) {
            $length = rand(8, 16);
            $password = '';
            
            for ($j = 0; $j < $length; $j++) {
                $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
                $password .= $chars[rand(0, strlen($chars) - 1)];
            }
            
            $passwords[] = $password;
        }
        
        return $passwords;
    }
}