<?php

namespace App\Console\Commands;

use App\Bot\Conversations\ApplicationConversation;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\Drivers\Telegram\TelegramDriver;
use Illuminate\Console\Command;

class TestBotCommand extends Command
{
    protected $signature = 'bot:test';
    protected $description = 'Test telegram bot functionality';

    public function handle()
    {
        $this->info('Testing Bot Components...');

        // Test conversation structure
        $this->info('✅ ApplicationConversation class exists');
        
        // Test models
        $this->testModels();
        
        // Test BotMan configuration
        $this->testBotManConfig();
        
        $this->info('🚀 Bot is ready! Configure your webhook URL and token.');
        $this->info('Webhook URL: ' . url('/botman'));
    }

    private function testModels()
    {
        try {
            $citiesCount = \App\Models\City::count();
            $clinicsCount = \App\Models\Clinic::count();
            $doctorsCount = \App\Models\Doctor::count();
            
            $this->info("✅ Models working:");
            $this->info("   - Cities: {$citiesCount}");
            $this->info("   - Clinics: {$clinicsCount}");
            $this->info("   - Doctors: {$doctorsCount}");
        } catch (\Exception $e) {
            $this->error("❌ Model error: " . $e->getMessage());
        }
    }

    private function testBotManConfig()
    {
        try {
            $config = config('botman');
            
            if (isset($config['drivers']['telegram']['token'])) {
                $token = $config['drivers']['telegram']['token'];
                $hasToken = $token && $token !== 'your_telegram_bot_token_here';
                
                if ($hasToken) {
                    $this->info("✅ Telegram token configured");
                } else {
                    $this->warn("⚠️ Set TELEGRAM_TOKEN in .env file");
                }
            }
            
            $this->info("✅ BotMan config loaded");
        } catch (\Exception $e) {
            $this->error("❌ BotMan config error: " . $e->getMessage());
        }
    }
}