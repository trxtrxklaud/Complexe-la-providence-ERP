<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'مدرسة العناية') }}</title>
    
    <!-- Vite -->
    @vite(['resources/js/main.tsx', 'resources/css/app.css'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
