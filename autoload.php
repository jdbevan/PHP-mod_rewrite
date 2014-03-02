<?php

spl_autoload_register(function ($class) {
    $path = 'classes/' . $class . '.class.php';
    if (file_exists($path)) {
        include_once $path;
    }
});
