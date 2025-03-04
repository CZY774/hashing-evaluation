<?php
// TODO: First test never succeeds.
// XAMPP error, access denied
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AuthenticationSimulationCommand extends Command
{
    protected $signature = 'hash:auth-simulation
                            {--users=100 : Number of user accounts to create}
                            {--algorithm=bcrypt : Hashing algorithm to use}
                            {--login-attempts=50 : Number of login attempts to simulate}
                            {--concurrent=5 : Number of concurrent operations}';

    protected $description = 'Simulate user registration and authentication to test hashing performance';

    protected $testUsers = [];
    protected $results = [];

    public function handle()
    {
        $userCount = $this->option('users');
        $algorithm = $this->option('algorithm');
        $loginAttempts = $this->option('login-attempts');
        $concurrent = $this->option('concurrent');

        // Validate algorithm
        if (!in_array($algorithm, ['bcrypt', 'argon2id'])) {
            $this->error("Invalid algorithm. Please use bcrypt or argon2id.");
            return Command::FAILURE;
        }

        // Configure the hashing driver
        config(['hashing.driver' => $algorithm]);
        
        $this->info("Running authentication simulation with {$algorithm}");
        $this->info("Creating {$userCount} users and simulating {$loginAttempts} login attempts");
        
        // Create temporary test table if it doesn't exist
        $this->createTestTable();
        
        // Register test users
        $this->info("Registering users...");
        $registerStart = microtime(true);
        $this->registerUsers($userCount);
        $registerEnd = microtime(true);
        $registerTime = $registerEnd - $registerStart;
        
        $this->info("Registration completed in " . number_format($registerTime, 2) . " seconds");
        $this->info("Average registration time: " . number_format(($registerTime / $userCount) * 1000, 2) . " ms per user");
        
        // Simulate login attempts
        $this->info("\nSimulating login attempts...");
        $loginResults = $this->simulateLogins($loginAttempts, $concurrent);
        
        // Report results
        $this->displayResults($loginResults, $algorithm);
        
        // Clean up
        $this->cleanupTestTable();
        
        return Command::SUCCESS;
    }
    
    protected function createTestTable()
    {
        if (!Schema::hasTable('hash_test_users')) {
            $this->info("Creating temporary test table...");
            
            Schema::create('hash_test_users', function ($table) {
                $table->id();
                $table->string('email')->unique();
                $table->string('password');
                $table->timestamps();
            });
        } else {
            // Clear existing data
            DB::table('hash_test_users')->truncate();
        }
    }
    
    protected function registerUsers($count)
    {
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        
        $batchSize = 100;
        $batches = ceil($count / $batchSize);
        
        for ($batch = 0; $batch < $batches; $batch++) {
            $users = [];
            $currentBatchSize = min($batchSize, $count - ($batch * $batchSize));
            
            for ($i = 0; $i < $currentBatchSize; $i++) {
                $index = ($batch * $batchSize) + $i;
                $email = "testuser{$index}@example.com";
                $password = "Password" . $index . "!" . rand(1000, 9999);
                
                $users[] = [
                    'email' => $email,
                    'password' => Hash::make($password),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                
                $this->testUsers[] = [
                    'email' => $email,
                    'password' => $password,
                ];
                
                $bar->advance();
            }
            
            DB::table('hash_test_users')->insert($users);
        }
        
        $bar->finish();
        $this->newLine();
    }
    
    protected function simulateLogins($attempts, $concurrent)
    {
        $results = [
            'success_count' => 0,
            'failure_count' => 0,
            'times' => [],
        ];
        
        $bar = $this->output->createProgressBar($attempts);
        $bar->start();
        
        // Simulate login attempts
        for ($i = 0; $i < $attempts; $i += $concurrent) {
            $batch = min($concurrent, $attempts - $i);
            $promises = [];
            
            for ($j = 0; $j < $batch; $j++) {
                // Choose random user - 80% correct password, 20% incorrect
                $userIndex = rand(0, count($this->testUsers) - 1);
                $user = $this->testUsers[$userIndex];
                $useCorrectPassword = (rand(1, 100) <= 80);
                
                $password = $useCorrectPassword ? $user['password'] : 'WrongPassword' . rand(1000, 9999);
                
                $startTime = microtime(true);
                
                // Get user from DB
                $dbUser = DB::table('hash_test_users')
                    ->where('email', $user['email'])
                    ->first();
                
                if ($dbUser) {
                    // Check password
                    $passwordCorrect = Hash::check($password, $dbUser->password);
                    
                    $endTime = microtime(true);
                    $timeMs = ($endTime - $startTime) * 1000;
                    
                    if ($passwordCorrect) {
                        $results['success_count']++;
                    } else {
                        $results['failure_count']++;
                    }
                    
                    $results['times'][] = $timeMs;
                }
                
                $bar->advance();
            }
            
            // Small pause between batches to prevent overwhelming the system
            if ($i + $batch < $attempts) {
                usleep(10000); // 10ms
            }
        }
        
        $bar->finish();
        $this->newLine(2);
        
        return $results;
    }
    
    protected function displayResults($results, $algorithm)
    {
        $totalAttempts = $results['success_count'] + $results['failure_count'];
        $successRate = ($results['success_count'] / $totalAttempts) * 100;
        
        $avgTime = array_sum($results['times']) / count($results['times']);
        $minTime = min($results['times']);
        $maxTime = max($results['times']);
        
        // Calculate standard deviation
        $variance = 0;
        foreach ($results['times'] as $time) {
            $variance += pow(($time - $avgTime), 2);
        }
        $stdDev = sqrt($variance / count($results['times']));
        
        $this->info("Authentication Simulation Results ({$algorithm}):");
        $this->info("Total login attempts: {$totalAttempts}");
        $this->info("Successful logins: {$results['success_count']} (" . number_format($successRate, 2) . "%)");
        $this->info("Failed logins: {$results['failure_count']} (" . number_format(100 - $successRate, 2) . "%)");
        $this->info("Login time statistics:");
        $this->info(" - Average: " . number_format($avgTime, 2) . " ms");
        $this->info(" - Minimum: " . number_format($minTime, 2) . " ms");
        $this->info(" - Maximum: " . number_format($maxTime, 2) . " ms");
        $this->info(" - Std Dev: " . number_format($stdDev, 2) . " ms");
        
        // Export results
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "auth_simulation_{$algorithm}_{$timestamp}.csv";
        $path = storage_path("app/{$filename}");
        
        $fp = fopen($path, 'w');
        
        // Write header
        fputcsv($fp, ['attempt', 'time_ms']);
        
        // Write data
        foreach ($results['times'] as $index => $time) {
            fputcsv($fp, [$index + 1, number_format($time, 2)]);
        }
        
        fclose($fp);
        
        $this->info("\nDetailed results exported to {$path}");
    }
    
    protected function cleanupTestTable()
    {
        $this->info("\nCleaning up test data...");
        DB::table('hash_test_users')->truncate();
    }
}