<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="/logo.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,400;0,600;0,800;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <div id="app">
        {{-- Shown until the web app starts (Vue replaces it), so a slow start never looks frozen. --}}
        <div class="grid min-h-dvh place-items-center" aria-busy="true">
            <div class="flex flex-col items-center gap-5">
                <img src="/logo.svg" alt="{{ config('app.name') }}" width="56" height="56" class="rounded-xl">
                <span class="size-7 animate-spin rounded-full border-3 border-line border-t-mint"></span>
            </div>
        </div>
    </div>
    <noscript>Clinical Trial Watcher needs JavaScript enabled.</noscript>
</body>
</html>
