<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Browser\Runtime;

/**
 * Handles auto-installation of playwright-cli and Chromium into the workspace.
 *
 * Installs @playwright/cli as a local npm package in .workspace/browser/
 * and downloads the Chromium browser binary. All operations are sandboxed
 * to the workspace directory.
 */
final class BrowserInstaller
{
    private const PACKAGE_NAME = '@playwright/cli';

    public function __construct(
        private readonly string $browserDir,
        private readonly string $browsersPath = '',
    ) {}

    /**
     * Check if Node.js and npm are available on the system.
     *
     * @return array{node: bool, npm: bool, node_version: string, npm_version: string}
     */
    public function checkPrerequisites(): array
    {
        $nodeVersion = trim((string) shell_exec('node --version 2>/dev/null'));
        $npmVersion = trim((string) shell_exec('npm --version 2>/dev/null'));

        return [
            'node' => $nodeVersion !== '',
            'npm' => $npmVersion !== '',
            'node_version' => $nodeVersion,
            'npm_version' => $npmVersion,
        ];
    }

    /**
     * Install playwright-cli and Chromium browser into the workspace.
     *
     * @return array{success: bool, message: string}
     */
    public function install(): array
    {
        $prereqs = $this->checkPrerequisites();

        if (!$prereqs['node']) {
            return [
                'success' => false,
                'message' => "Node.js is required but not found on PATH.\n"
                    . "Install Node.js 18+ from https://nodejs.org/ or via your package manager:\n"
                    . "  Ubuntu/Debian: sudo apt install nodejs npm\n"
                    . "  macOS: brew install node\n"
                    . "  Arch: sudo pacman -S nodejs npm",
            ];
        }

        if (!$prereqs['npm']) {
            return [
                'success' => false,
                'message' => 'npm is required but not found on PATH. Install npm alongside Node.js.',
            ];
        }

        // Create browser directory
        if (!is_dir($this->browserDir) && !mkdir($this->browserDir, 0755, true)) {
            return [
                'success' => false,
                'message' => "Failed to create browser directory: {$this->browserDir}",
            ];
        }

        // Initialize npm project if needed
        $packageJson = $this->browserDir . '/package.json';
        if (!file_exists($packageJson)) {
            $initResult = $this->runInDir('npm init -y 2>&1');
            if ($initResult['exit_code'] !== 0) {
                return [
                    'success' => false,
                    'message' => "Failed to initialize npm project:\n{$initResult['output']}",
                ];
            }
        }

        // Install @playwright/cli
        $installResult = $this->runInDir('npm install ' . self::PACKAGE_NAME . ' 2>&1');
        if ($installResult['exit_code'] !== 0) {
            return [
                'success' => false,
                'message' => "Failed to install " . self::PACKAGE_NAME . ":\n{$installResult['output']}",
            ];
        }

        // Install Chromium browser
        $binary = $this->browserDir . '/node_modules/.bin/playwright-cli';
        if (!file_exists($binary)) {
            return [
                'success' => false,
                'message' => 'playwright-cli binary not found after npm install.',
            ];
        }

        // Use npx playwright (not playwright-cli) to download browser binaries
        $browserResult = $this->runInDir('npx playwright install chromium 2>&1', $this->buildBrowserEnv());

        // Create config so playwright-cli daemon uses bundled chromium,
        // not Google Chrome (which may not be installed on the system).
        $this->writeDefaultConfig();

        // playwright install may output to stderr even on success
        $output = "## Browser Setup Complete\n\n";
        $output .= "**Node.js:** {$prereqs['node_version']}\n";
        $output .= "**npm:** {$prereqs['npm_version']}\n";
        $output .= "**Package:** " . self::PACKAGE_NAME . "\n";
        $output .= "**Browser:** Chromium\n";
        $output .= "**Location:** {$this->browserDir}\n\n";

        if ($browserResult['exit_code'] !== 0) {
            $output .= "### Browser Download\n\n";
            $output .= "Chromium download may have had issues (exit code {$browserResult['exit_code']}):\n";
            $output .= "```\n{$browserResult['output']}\n```\n\n";
            $output .= "You can retry with: `browser_session` action `setup`";
        } else {
            $output .= "Chromium browser downloaded successfully.";
        }

        return [
            'success' => true,
            'message' => $output,
        ];
    }

    /**
     * Install system dependencies for Chromium on Linux.
     *
     * @return array{success: bool, message: string}
     */
    public function installDeps(): array
    {
        $binary = $this->browserDir . '/node_modules/.bin/playwright-cli';

        if (!file_exists($binary)) {
            return [
                'success' => false,
                'message' => 'playwright-cli not installed. Run setup first.',
            ];
        }

        $result = $this->runInDir(
            escapeshellarg($binary) . ' install --with-deps chromium 2>&1',
            $this->buildBrowserEnv(),
        );

        return [
            'success' => $result['exit_code'] === 0,
            'message' => $result['output'],
        ];
    }

    /**
     * Check if playwright-cli is installed in the workspace.
     */
    public function isInstalled(): bool
    {
        $binary = $this->browserDir . '/node_modules/.bin/playwright-cli';
        return file_exists($binary) && is_executable($binary);
    }

    /**
     * Write .playwright/cli.config.json to configure bundled Chromium.
     *
     * Without this config, playwright-cli defaults to Google Chrome
     * (channel: "chrome"), which may not be installed on the system.
     */
    private function writeDefaultConfig(): void
    {
        $configDir = $this->browserDir . '/.playwright';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $config = [
            'browser' => [
                'browserName' => 'chromium',
                'launchOptions' => [
                    'channel' => 'chromium',
                ],
            ],
        ];

        file_put_contents(
            $configDir . '/cli.config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * Build environment variables for browser install commands.
     *
     * @return array<string, string>|null
     */
    private function buildBrowserEnv(): ?array
    {
        if ($this->browsersPath === '') {
            return null;
        }

        return array_merge(getenv(), [
            'PLAYWRIGHT_BROWSERS_PATH' => $this->browsersPath,
        ]);
    }

    /**
     * @param array<string, string>|null $env
     * @return array{exit_code: int, output: string}
     */
    private function runInDir(string $command, ?array $env = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->browserDir, $env);

        if (!is_resource($process)) {
            return ['exit_code' => 1, 'output' => 'Failed to start process.'];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = trim($stdout);
        if ($stderr !== '') {
            $output .= ($output !== '' ? "\n" : '') . trim($stderr);
        }

        return ['exit_code' => $exitCode, 'output' => $output];
    }
}
