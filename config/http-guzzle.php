<?php

declare(strict_types=1);

use Marko\Config\Env;

return [
    // Seconds to wait for the whole request before giving up. 0 waits forever.
    'timeout' => Env::float('HTTP_GUZZLE_TIMEOUT', 30.0, min: 0.0),
    // Seconds to wait while establishing the connection. 0 waits forever.
    'connect_timeout' => Env::float('HTTP_GUZZLE_CONNECT_TIMEOUT', 10.0, min: 0.0),
];
