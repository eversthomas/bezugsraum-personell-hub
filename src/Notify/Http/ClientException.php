<?php

declare(strict_types=1);

namespace App\Notify\Http;

use Psr\Http\Client\ClientExceptionInterface;

final class ClientException extends \RuntimeException implements ClientExceptionInterface
{
}
