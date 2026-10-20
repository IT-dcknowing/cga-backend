<?php

namespace App\Support\Files;

use App\Support\Config\SettingsService;
use App\Support\Http\Exceptions\ExternalServiceException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Pilote « libreoffice » (02-TRD §8.2) : soffice --headless --convert-to pdf sur une copie du fichier,
 * dans un dossier de travail jetable, avec un profil LibreOffice propre au worker (deux conversions
 * simultanées ne se bloquent pas) et un délai maximal (paramètre pdf.timeout_seconds).
 */
class LibreOfficePdfConverter implements PdfConverter
{
    public function __construct(
        private readonly Repository $config,
        private readonly SettingsService $settings,
    ) {}

    public function convert(string $sourcePath): string
    {
        if (! is_file($sourcePath)) {
            throw new ExternalServiceException('libreoffice', "Fichier à convertir introuvable : {$sourcePath}");
        }

        $workDirectory = $this->config->string('pdf.work_path').'/'.Str::lower((string) Str::ulid());
        File::ensureDirectoryExists($workDirectory);

        try {
            $input = $workDirectory.'/document.'.pathinfo($sourcePath, PATHINFO_EXTENSION);
            File::copy($sourcePath, $input);

            $result = Process::timeout((int) $this->settings->get('pdf.timeout_seconds'))->run([
                $this->config->string('pdf.libreoffice.binary'),
                '-env:UserInstallation='.$this->fileUri($this->profileDirectory()),
                '--headless', '--norestore', '--nologo', '--nodefault', '--nolockcheck',
                '--convert-to', 'pdf', '--outdir', $workDirectory, $input,
            ]);

            $output = $workDirectory.'/document.pdf';
            if ($result->failed() || ! is_file($output)) {
                throw new ExternalServiceException('libreoffice', Str::limit(trim($result->errorOutput()."\n".$result->output()), 1000));
            }

            $pdf = (string) file_get_contents($output);
            if (! str_starts_with($pdf, '%PDF')) {
                throw new ExternalServiceException('libreoffice', 'La conversion n\'a pas produit de PDF.');
            }

            return $pdf;
        } catch (ProcessTimedOutException $exception) {
            throw new ExternalServiceException('libreoffice', 'Délai de conversion dépassé.', $exception);
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * Profil du worker : LIBREOFFICE_PROFILE_SLOT (Supervisor), sinon le processus courant.
     */
    public function profileDirectory(): string
    {
        $slot = $this->config->get('pdf.libreoffice.profile_slot');
        $slot = is_string($slot) && preg_match('/^[A-Za-z0-9_-]{1,20}$/', $slot) === 1 ? $slot : 'pid-'.getmypid();

        return rtrim($this->config->string('pdf.libreoffice.profiles_path'), '/\\').'/profile-'.$slot;
    }

    /**
     * Chemin local au format URI attendu par -env:UserInstallation (file:///…, sous Windows aussi).
     */
    private function fileUri(string $path): string
    {
        $segments = explode('/', ltrim(str_replace('\\', '/', $path), '/'));
        $encoded = array_map(static fn (string $segment): string => preg_match('/^[A-Za-z]:$/', $segment) === 1 ? $segment : rawurlencode($segment), $segments);

        return 'file:///'.implode('/', $encoded);
    }
}
