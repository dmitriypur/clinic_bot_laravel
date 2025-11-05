<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Онлайн запись</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    @vite('resources/js/app.js')
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background:#fafafa; }
        .card{ background:#fff;border-radius:16px;padding:16px;box-shadow:0 6px 24px rgba(0,0,0,.06);}
        .btn{ display:inline-block;padding:12px 16px;border-radius:12px;border:1px solid #e5e7eb; background:#fff; cursor:pointer; }
        .btn.primary{ background:#111; color:#fff; border-color:#111;}
        .list{ display:grid; gap:8px; margin:8px 0;}
        .row{ display:flex; gap:8px; flex-wrap:wrap;}
        input,select{ width:100%; padding:12px; border-radius:12px; border:1px solid #e5e7eb;}
    </style>
</head>
<body>
@inertia
</body>
</html>
