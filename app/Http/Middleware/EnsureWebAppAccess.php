<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebAppAccess
{
    private const SESSION_KEY = 'telegram_web_app_allowed';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasRole('super_admin')) {
            return $next($request);
        }

        if ($request->session()->get(self::SESSION_KEY, false)) {
            return $next($request);
        }

        if ($this->isTelegramWebAppRequest($request)) {
            $request->session()->put(self::SESSION_KEY, true);

            return $next($request);
        }

        abort(404);
    }

    private function isTelegramWebAppRequest(Request $request): bool
    {
        $userAgent = (string) $request->header('User-Agent', '');
        $hasTelegramUserAgent = Str::contains(Str::lower($userAgent), 'telegram');

        $hasTelegramParams = filled($request->query('tg_user_id'))
            || filled($request->query('tg_chat_id'));

        $initDataHeader = collect([
            $request->header('X-Telegram-Web-App-Init-Data'),
            $request->header('X-Telegram-Init-Data'),
            $request->header('X-Telegram-WebApp-Init-Data'),
        ])->first(fn ($value) => filled($value));

        $hasInitDataHeader = filled($initDataHeader);

        $origin = (string) $request->header('Origin', '');
        $referer = (string) $request->header('Referer', '');
        $hasTelegramOrigin = $this->containsTelegramDomain($origin) || $this->containsTelegramDomain($referer);

        if ($hasInitDataHeader) {
            return true;
        }

        return ($hasTelegramUserAgent && ($hasTelegramParams || $hasTelegramOrigin))
            || ($hasTelegramOrigin && $hasTelegramParams);
    }

    private function containsTelegramDomain(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $normalized = Str::lower($value);

        return Str::contains($normalized, [
            'telegram.org',
            't.me',
            'telegram.me',
        ]);
    }
}
