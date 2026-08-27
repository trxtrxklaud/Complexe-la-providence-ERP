<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="author" content="Complexe La Providence — Prod RH">
    <meta name="copyright" content="© 2026 Complexe La Providence — Prod RH. Tous droits réservés.">
    <title>{{ config('app.name', 'مدرسة العناية') }}</title>

    <!-- خطوط الهوية: Reem Kufi للعناوين، IBM Plex Sans Arabic للنصّ العربي، Inter للّاتيني/الأرقام. تتراجع بأمان لخطوط النظام دون اتصال. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Reem+Kufi:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Vite -->
    @viteReactRefresh
    @vite(['resources/js/main.tsx', 'resources/css/app.css'])
</head>
<body>
    <div id="root"></div>
</body>
</html>


