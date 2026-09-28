<?php
require '/var/www/providence/vendor/autoload.php';
$app = require_once '/var/www/providence/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('phone', '20054625')
    ->orWhere('email', 'like', '%20054625%')
    ->orWhere('email', 'like', '%parent%')
    ->get();

foreach ($user as $u) {
    echo "ID: {$u->id} | Name: {$u->name} | Email: {$u->email} | Phone: {$u->phone} | PhonePass: {$u->phone_password} | Role: " . ($u->role?->name ?? 'none') . "\n";
}
