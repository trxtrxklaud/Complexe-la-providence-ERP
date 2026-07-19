<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo e(config('app.name', 'مدرسة العناية')); ?></title>
    
    <!-- Vite -->
    <?php echo app('Illuminate\Foundation\Vite')(['resources/js/main.tsx', 'resources/css/app.css']); ?>
</head>
<body>
    <div id="root"></div>
</body>
</html>
<?php /**PATH /data/data/com.termux/files/home/projects/providence/resources/views/app.blade.php ENDPATH**/ ?>