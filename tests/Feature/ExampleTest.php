<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
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
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
            'X-Telegram-Web-App-Init-Data' => 'sample',
        ])->get('/app');

        $response->assertOk();
    }

    public function test_telegram_origin_and_params_allow_access(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://web.telegram.org',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
        ])->get('/app?tg_user_id=789');

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

    public function test_partner_user_query_is_limited_to_self(): void
    {
        Role::create(['name' => 'partner', 'guard_name' => 'web']);

        $partner = User::factory()->create(['email' => 'partner@example.com']);
        $partner->assignRole('partner');

        $other = User::factory()->create(['email' => 'other@example.com']);
        $other->assignRole('partner');

        $this->actingAs($partner);

        $ids = UserResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$partner->id], $ids);
    }

    public function test_partner_cannot_create_users(): void
    {
        Role::create(['name' => 'partner', 'guard_name' => 'web']);

        $partner = User::factory()->create();
        $partner->assignRole('partner');

        $this->actingAs($partner);

        $this->assertFalse(UserResource::canCreate());
    }

    public function test_super_admin_can_create_users(): void
    {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        $this->assertTrue(UserResource::canCreate());
    }
}
