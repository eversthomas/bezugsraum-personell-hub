<?php

declare(strict_types=1);

namespace App\Install;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityHeaders;
use App\Core\Session;
use App\Core\View;

/**
 * Läuft nur, solange config/config.php fehlt. Darf eine Sitzung öffnen.
 */
final class InstallerApp
{
    public function __construct(
        private readonly string $root,
        private readonly ?Session $session = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $session = $this->session ?? new Session(
            $this->root . '/storage/sessions',
            $request->isHttps(false),
        );
        $controller = new InstallController(
            new InstallCode(
                $this->root . '/storage/install-code.txt',
                $this->root . '/storage/install-attempts.txt',
            ),
            new SystemCheck($this->root),
            $session,
            new Csrf($session),
            new View($this->root . '/templates'),
        );

        return SecurityHeaders::apply($controller->handle($request), $request->isHttps(false));
    }
}
