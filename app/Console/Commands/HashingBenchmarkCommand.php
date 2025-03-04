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
            'default' => ['rounds' => 12],
            'variations' => [
                ['rounds' => 8],
                ['rounds' => 10],
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
    protected $processId;

    public function handle()
    {
        $iterations = $this->option('iterations');
        $passwordsCount = $this->option('passwords');
        $exportFormat = $this->option('export');

        $this->startTime = microtime(true);
        $this->processId = getmypid();
        
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
        $cpuUsages = [];
        
        // Process each password
        foreach ($passwords as $index => $password) {
            $this->output->write("  Password {$index}: ");
            
            // Hash timing
            $hashedPasswords = [];
            
            // Get initial CPU measurement
            $startCPUInfo = $this->getCPUInfo();
            $hashStartTime = microtime(true);
            $memBefore = memory_get_usage();
            
            for ($i = 0; $i < $iterations; $i++) {
                $hashedPasswords[] = Hash::make($password);
            }
            
            $memAfter = memory_get_usage();
            $hashEndTime = microtime(true);
            // Get final CPU measurement
            $endCPUInfo = $this->getCPUInfo();
            
            $hashTime = ($hashEndTime - $hashStartTime) / $iterations;
            $hashTimes[] = $hashTime;
            
            $memoryUsage = ($memAfter - $memBefore) / $iterations;
            $memoryUsages[] = $memoryUsage;
            
            // Calculate CPU usage percentage
            $cpuUsage = $this->calculateCPUUsage($startCPUInfo, $endCPUInfo, $hashEndTime - $hashStartTime);
            $cpuUsages[] = $cpuUsage;
            
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
            $this->output->write("verify " . number_format($verifyTime * 1000, 2) . " ms, ");
            $this->output->write("CPU " . number_format($cpuUsage, 2) . "%\n");
        }
        
        // Calculate averages
        $avgHashTime = array_sum($hashTimes) / count($hashTimes);
        $avgVerifyTime = array_sum($verifyTimes) / count($verifyTimes);
        $avgMemoryUsage = array_sum($memoryUsages) / count($memoryUsages);
        $avgHashLength = array_sum($hashLengths) / count($hashLengths);
        $avgCPUUsage = array_sum($cpuUsages) / count($cpuUsages);
        
        // Display summary for this algorithm
        $this->info("  Summary:");
        $this->info("  - Average hash time: " . number_format($avgHashTime * 1000, 2) . " ms");
        $this->info("  - Average verify time: " . number_format($avgVerifyTime * 1000, 2) . " ms");
        $this->info("  - Average CPU usage: " . number_format($avgCPUUsage, 2) . "%");
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
            'avg_cpu_usage_percent' => number_format($avgCPUUsage, 2),
            'avg_memory_usage_kb' => number_format($avgMemoryUsage / 1024, 2),
            'avg_hash_length' => number_format($avgHashLength, 0),
            'throughput_hash_per_sec' => number_format(1 / $avgHashTime, 2),
            'throughput_verify_per_sec' => number_format(1 / $avgVerifyTime, 2),
        ];
        
        // Restore original configuration
        config(['hashing' => $originalConfig]);
    }
    
    protected function getCPUInfo()
    {
        $cpuInfo = [
            'timestamp' => microtime(true),
            'value' => 0
        ];
        
        // Deteksi sistem operasi dan gunakan metode yang sesuai
        if (PHP_OS_FAMILY === 'Windows') {
            try {
                // Gunakan pendekatan PowerShell seperti di ResourceMonitorCommand
                $cpuUsage = trim(shell_exec('powershell -command "Get-Counter \'\\Processor(_Total)\\% Processor Time\' | Select-Object -ExpandProperty CounterSamples | Select-Object -ExpandProperty CookedValue"'));
                if ($cpuUsage !== null && is_numeric(trim($cpuUsage))) {
                    $cpuInfo['value'] = (float)trim($cpuUsage);
                }
            } catch (\Exception $e) {
                // Tangani error jika ada masalah dengan PowerShell
                $this->error("Error getting CPU info: " . $e->getMessage());
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            // Metode Linux dengan /proc/stat
            $stat = @file_get_contents('/proc/stat');
            if ($stat !== false) {
                $lines = explode("\n", $stat);
                if (isset($lines[0]) && strpos($lines[0], 'cpu ') === 0) {
                    $data = preg_split('/\s+/', trim($lines[0]));
                    // user, nice, system, idle, iowait, irq, softirq, steal
                    if (count($data) >= 5) {
                        $cpuInfo['user'] = $data[1];
                        $cpuInfo['nice'] = $data[2];
                        $cpuInfo['system'] = $data[3];
                        $cpuInfo['idle'] = $data[4];
                        $cpuInfo['iowait'] = isset($data[5]) ? $data[5] : 0;
                    }
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS menggunakan top command
            $cmd = 'top -l 1 -n 0 | grep "CPU usage"';
            $output = shell_exec($cmd);
            if ($output !== null) {
                if (preg_match('/CPU usage: ([\d.]+)% user, ([\d.]+)% sys, ([\d.]+)% idle/', $output, $matches)) {
                    $user = floatval($matches[1]);
                    $sys = floatval($matches[2]);
                    $cpuInfo['value'] = $user + $sys;
                }
            }
        }
        
        // Fallback menggunakan getrusage jika tersedia
        if (function_exists('getrusage')) {
            $usage = getrusage();
            $cpuInfo['process_time'] = $usage["ru_utime.tv_sec"] + 
                                     $usage["ru_utime.tv_usec"] / 1000000 + 
                                     $usage["ru_stime.tv_sec"] + 
                                     $usage["ru_stime.tv_usec"] / 1000000;
        }
        
        return $cpuInfo;
    }
    
    protected function calculateCPUUsage($startInfo, $endInfo, $elapsedTime)
    {
        // Jika kita punya nilai CPU langsung dari command line (Windows/macOS)
        if (isset($startInfo['value']) && isset($endInfo['value'])) {
            // Ambil rata-rata dari nilai awal dan akhir
            return ($startInfo['value'] + $endInfo['value']) / 2;
        }
        
        // Jika kita punya data Linux /proc/stat
        if (isset($startInfo['user']) && isset($endInfo['user'])) {
            $startTotal = $startInfo['user'] + $startInfo['nice'] + $startInfo['system'] + $startInfo['idle'] + $startInfo['iowait'];
            $endTotal = $endInfo['user'] + $endInfo['nice'] + $endInfo['system'] + $endInfo['idle'] + $endInfo['iowait'];
            
            $startIdle = $startInfo['idle'];
            $endIdle = $endInfo['idle'];
            
            $diffTotal = $endTotal - $startTotal;
            $diffIdle = $endIdle - $startIdle;
            
            if ($diffTotal > 0) {
                return 100 * (1 - $diffIdle / $diffTotal);
            }
        }
        
        // Jika kita punya data process_time dari getrusage
        if (isset($startInfo['process_time']) && isset($endInfo['process_time'])) {
            $processCPUTime = $endInfo['process_time'] - $startInfo['process_time'];
            $numCores = $this->getNumberOfCores();
            // Hitung persentase berdasarkan waktu yang berlalu dan jumlah core
            return min(100, ($processCPUTime / $elapsedTime) * 100 / $numCores);
        }
        
        // Fallback menggunakan sys_getloadavg jika tersedia
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0] * 100 / $this->getNumberOfCores();
        }
        
        return 0; // Default ke 0 jika kita tidak bisa menghitung CPU usage
    }
    
    protected function getNumberOfCores()
    {
        static $cores = null;
        
        if ($cores !== null) {
            return $cores;
        }
        
        $cores = 1; // Default ke 1 core
        
        if (PHP_OS_FAMILY === 'Linux') {
            $cmd = "nproc";
            $coresStr = shell_exec($cmd);
            if ($coresStr !== null) {
                $cores = (int)trim($coresStr);
            } else {
                // Metode alternatif
                $cpuinfo = shell_exec('cat /proc/cpuinfo | grep processor | wc -l');
                if ($cpuinfo !== null) {
                    $cores = (int)trim($cpuinfo);
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') { // macOS
            $cmd = "sysctl -n hw.ncpu";
            $coresStr = shell_exec($cmd);
            if ($coresStr !== null) {
                $cores = (int)trim($coresStr);
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'powershell -command "Get-WmiObject Win32_Processor | Select-Object -ExpandProperty NumberOfCores"';
            $output = shell_exec($cmd);
            if ($output !== null) {
                $output = trim($output);
                if (is_numeric($output)) {
                    $cores = (int)$output;
                } else {
                    // Coba cara lain jika output bukan angka
                    $lines = explode("\n", $output);
                    foreach ($lines as $line) {
                        if (is_numeric(trim($line))) {
                            $cores = (int)trim($line);
                            break;
                        }
                    }
                }
            }
            
            // Alternatif lain untuk Windows
            if ($cores === 1) {
                $cmd = 'powershell -command "Get-CimInstance -ClassName Win32_ComputerSystem | Select-Object -ExpandProperty NumberOfLogicalProcessors"';
                $output = trim(shell_exec($cmd));
                if ($output !== null && is_numeric($output)) {
                    $cores = (int)$output;
                }
            }
        }
        
        return max(1, $cores); // Pastikan minimal 1 core
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