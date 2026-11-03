<?php

declare(strict_types=1);

http_response_code(404);
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Content-Type: text/plain; charset=utf-8');

echo "Nicht gefunden\n";
