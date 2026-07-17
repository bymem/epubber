<?php

class Config
{
    public readonly string $scanFolder;
    public readonly string $outputFolder;
    public readonly string $language;

    public function __construct(string $projectRoot, string $envFile)
    {
        $env = $this->loadEnvFile($envFile);

        $scanFolder   = $this->resolvePath($projectRoot, $env['SCAN_FOLDER'] ?? 'stories');
        $outputFolder = $this->resolvePath($projectRoot, $env['OUTPUT_FOLDER'] ?? 'temp');

        [$this->scanFolder, $this->outputFolder] = $this->confirmPaths($scanFolder, $outputFolder);
        $this->language = $env['LANGUAGE'] ?? 'en';
    }

    // Small built-in KEY=VALUE parser, no need for an external dependency for this
    private function loadEnvFile(string $path): array
    {
        $values = [];

        if (!file_exists($path)) {
            return $values;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim(trim($value), "\"'"); // trim whitespace, then surrounding quotes

            $values[$key] = $value;
        }

        return $values;
    }

    private function resolvePath(string $projectRoot, string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return rtrim($path, '/');
        }

        return rtrim($projectRoot . '/' . $path, '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    // Show the resolved folders and let the user override them for this run only (not persisted to .env)
    private function confirmPaths(string $scanFolder, string $outputFolder): array
    {
        echo "Scan folder:   $scanFolder\n";
        echo "Output folder: $outputFolder\n";

        $answer = strtolower(Prompt::ask('Is this correct? [Y/n]: '));

        if ($answer === '' || $answer === 'y') {
            return [$scanFolder, $outputFolder];
        }

        $newScan   = Prompt::ask("Scan folder for this run [$scanFolder]: ");
        $newOutput = Prompt::ask("Output folder for this run [$outputFolder]: ");

        return [
            $newScan !== '' ? rtrim($newScan, '/') : $scanFolder,
            $newOutput !== '' ? rtrim($newOutput, '/') : $outputFolder,
        ];
    }
}
