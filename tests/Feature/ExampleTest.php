<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_request_can_access_app_route(): void
    {
        $response = $this->withHeaders([
            'User-Agent' => 'TelegramBot 1.0',
        ])->get('/app?tg_user_id=123&tg_chat_id=456');

        $response->assertOk();
    }

    public function test_telegram_header_allows_access_without_query_params(): void
    {
        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 TelegramDesktop/5.2',
            'X-Telegram-Web-App-Init-Data' => 'sample',
        ])->get('/app');

        $response->assertOk();
    }

    public function test_super_admin_can_access_app_route(): void
    {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $response = $this->actingAs($user)->get('/app');

        $response->assertOk();
    }

    public function test_guest_without_telegram_context_is_forbidden(): void
    {
        $response = $this->get('/app');

        $response->assertNotFound();
    }
}
