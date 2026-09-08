<?php

// Prepend stubs BEFORE Composer so unit tests do not boot Flarum's User/Gate.
spl_autoload_register(function ($class) {
    $stubs = [
        'Flarum\\User\\Exception\\PermissionDeniedException' => __DIR__ . '/_stubs/PermissionDeniedException.php',
        'Flarum\\User\\User' => __DIR__ . '/_stubs/User.php',
        'Flarum\\Database\\AbstractModel' => __DIR__ . '/_stubs/AbstractModel.php',
        'Flarum\\Settings\\SettingsRepositoryInterface' => __DIR__ . '/_stubs/SettingsRepositoryInterface.php',
        'Flarum\\Api\\Serializer\\AbstractSerializer' => __DIR__ . '/_stubs/AbstractSerializer.php',
        'Flarum\\Api\\Serializer\\BasicUserSerializer' => __DIR__ . '/_stubs/BasicUserSerializer.php',
        'Flarum\\Api\\Serializer\\UserSerializer' => __DIR__ . '/_stubs/UserSerializer.php',
        'Flarum\\Foundation\\AbstractServiceProvider' => __DIR__ . '/_stubs/AbstractServiceProvider.php',
        'Illuminate\\Database\\Eloquent\\ModelNotFoundException' => __DIR__ . '/_stubs/Illuminate/Database/Eloquent/ModelNotFoundException.php',
    ];
    if (isset($stubs[$class]) && file_exists($stubs[$class])) {
        require_once $stubs[$class];
        return true;
    }
    return false;
}, true, true);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Run composer install before phpunit\n");
    exit(1);
}
require $autoload;
