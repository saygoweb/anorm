<?php

$fred->task('test', function () use ($fred) {
    include 'vendor/phpunit/phpunit/phpunit';
});

$fred->task('test-coverage', function () use ($fred) {
    // exec('/usr/bin/env php vendor/bin/phpunit --coverage-html docs/code_coverage --coverage-text');
    $f = popen('/usr/bin/env php vendor/bin/phpunit --coverage-html build/code_coverage --coverage-text', 'r');
    while (!feof($f)) {
        echo fread($f, 1024);
    }
    pclose($f);
});