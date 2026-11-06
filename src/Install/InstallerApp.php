<?php

declare(strict_types=1);

namespace App\Install;

use App\Auth\TotpVerifier;
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
        private readonly ?DatabaseProbe $probe = null,
        private readonly ?InstallFinisher $finisher = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $session = $this->session ?? new Session(
            $this->root . '/storage/sessions',
            $request->isHttps(false),
        );
        $code = new InstallCode(
            $this->root . '/storage/install-code.txt',
            $this->root . '/storage/install-attempts.txt',
        );
        $controller = new InstallController(
            $code,
            new SystemCheck($this->root),
            $session,
            new Csrf($session),
            new View($this->root . '/templates'),
            new InstallDraft($session),
            $this->probe ?? new DatabaseProbe(),
            $this->finisher ?? new InstallFinisher($this->root, new DefaultInstallActions($this->root), $code),
            new TotpVerifier(),
            new TotpQr(),
            $this->root,
        );

        return SecurityHeaders::apply($controller->handle($request), $request->isHttps(false));
    }
}
