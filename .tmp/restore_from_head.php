<?php
$files = [
    'app/Filament/Resources/Messages/MessageResource.php',
    'app/Filament/Resources/Messages/Pages/ListMessages.php',
    'app/Filament/Resources/WhatsappAccounts/WhatsappAccountResource.php',
];
foreach ($files as $file) {
    $cmd = 'git show HEAD:' . escapeshellarg(str_replace('\\', '/', $file));
    $content = shell_exec($cmd);
    if (! is_string($content) || $content === '') {
        fwrite(STDERR, "Failed to restore {$file}\n");
        exit(1);
    }
    file_put_contents($file, $content);
}