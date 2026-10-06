<?php

declare(strict_types=1);

describe('config/http-guzzle.php', function (): void {
    it('defaults to a 30 second timeout and a 10 second connect timeout', function (): void {
        $config = require dirname(__DIR__, 2) . '/config/http-guzzle.php';

        expect($config)->toBe([
            'timeout' => 30.0,
            'connect_timeout' => 10.0,
        ]);
    });
});
