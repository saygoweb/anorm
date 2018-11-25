<?php

$fred->task('test', function () use ($fred) {
    include 'vendor/phpunit/phpunit/phpunit';
});