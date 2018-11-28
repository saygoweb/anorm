<?php

$fred->task('test', function () use ($fred) {
    // This included coveralls clover.xml code coverage by default.
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

$fred->task('test-quick', function () use ($fred) {
    // exec('/usr/bin/env php vendor/bin/phpunit --coverage-html docs/code_coverage --coverage-text');
    $f = popen('/usr/bin/env php vendor/bin/phpunit -c phpunit-no-coverage.xml', 'r');
    while (!feof($f)) {
        echo fread($f, 1024);
    }
    pclose($f);
});