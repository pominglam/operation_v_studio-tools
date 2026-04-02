<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Maintenance\ExternalAccessAuthService;
use App\Services\Maintenance\ExternalAccessSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExternalLoginController
{
    public function show(Request $request, ExternalAccessSettingsService $settings, ExternalAccessAuthService $auth): Response
    {
        if (! $settings->isEnabled() || ! $auth->isPasswordConfigured()) {
            abort(404);
        }

        $next = $request->query('next');
        $next = is_string($next) ? $next : '/';
        if (! str_starts_with($next, '/')) {
            $next = '/';
        }

        $error = $request->query('error');
        $error = is_string($error) ? $error : null;

        $html = $this->renderLoginHtml($next, $error);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function submit(Request $request, ExternalAccessSettingsService $settings, ExternalAccessAuthService $auth): RedirectResponse
    {
        if (! $settings->isEnabled() || ! $auth->isPasswordConfigured()) {
            abort(404);
        }

        $next = $request->input('next');
        $next = is_string($next) ? $next : '/';
        if (! str_starts_with($next, '/')) {
            $next = '/';
        }

        $pw = $request->input('password');
        $pw = is_string($pw) ? $pw : '';
        $role = $auth->resolveRoleForPassword($pw);

        if ($role === null) {
            return redirect('/external-login?next='.rawurlencode($next).'&error=invalid');
        }

        $cookieVal = $auth->cookieValueForRole($role);
        if (! is_string($cookieVal) || $cookieVal === '') {
            abort(500);
        }

        // Session cookie: no expiry. HttpOnly + Secure since trycloudflare is HTTPS.
        return redirect($next)->withCookie(cookie(
            name: ExternalAccessAuthService::COOKIE_NAME,
            value: $cookieVal,
            minutes: 0,
            path: '/',
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: 'Lax',
        ));
    }

    private function renderLoginHtml(string $next, ?string $error): string
    {
        $safeNext = htmlspecialchars($next, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hasError = $error === 'invalid';

        return <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Operation V · Login</title>
    <style>
      body{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial; background:#0f172a; color:#e2e8f0; margin:0; padding:0;}
      .wrap{max-width:420px; margin:10vh auto; padding:24px;}
      .card{background:#111827; border:1px solid #334155; border-radius:12px; padding:20px;}
      .title{font-size:18px; font-weight:700; margin:0 0 6px;}
      .sub{font-size:13px; color:#94a3b8; margin:0 0 16px;}
      label{display:block; font-size:12px; color:#cbd5e1; margin-bottom:6px;}
      input{width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #334155; background:#0b1220; color:#e2e8f0;}
      .btn{margin-top:12px; width:100%; padding:10px 12px; border-radius:10px; border:0; background:#e2e8f0; color:#0f172a; font-weight:700; cursor:pointer;}
      .err{margin-top:10px; font-size:13px; color:#fecaca;}
      .note{margin-top:14px; font-size:12px; color:#94a3b8;}
      .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="card">
        <h1 class="title">External access</h1>
        <p class="sub">Enter the password to access this app through the tunnel.</p>
        <form method="post" action="/external-login">
          <input type="hidden" name="next" value="{$safeNext}" />
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" autofocus />
          <button class="btn" type="submit">Log in</button>
        </form>
HTML
        .($hasError ? '<div class="err">Invalid password.</div>' : '')
        .<<<HTML
        <div class="note">Tip: this stays logged in for this browser session.</div>
      </div>
      <div class="note mono">Next: {$safeNext}</div>
    </div>
  </body>
</html>
HTML;
    }
}

