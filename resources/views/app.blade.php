<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="author" content="Complexe La Providence — Prod RH">
    <meta name="copyright" content="© 2026 Complexe La Providence — Prod RH. Tous droits réservés.">
    <title>{{ config('app.name', 'مدرسة العناية') }}</title>
    
    <!-- Vite -->
    @viteReactRefresh
    @vite(['resources/js/main.tsx', 'resources/css/app.css'])
</head>
<body>
    <div id="root"></div>
</body>
</html>


