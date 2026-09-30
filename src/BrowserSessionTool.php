<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Browser;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\Browser\Runtime\BrowserInstaller;
use CoquiBot\Toolkits\Browser\Runtime\PlaywrightRunner;

/**
 * Tool for managing browser sessions and playwright-cli setup.
 *
 * Handles session lifecycle (list, close, close-all, kill-all), setup/installation
 * of playwright-cli, and browser data management.
 */
final class BrowserSessionTool implements ToolInterface
{
    public function __construct(
        private readonly PlaywrightRunner $runner,
        private readonly BrowserInstaller $installer,
    ) {}

    public function name(): string
    {
        return 'browser_session';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage browser sessions and playwright-cli installation.

            Use this tool to set up the browser automation environment, manage
            active browser sessions, and clean up resources.

            Available actions:
            - setup: Install playwright-cli and Chromium browser into the workspace.
              Run this first if browser tools report "playwright-cli not found".
              Requires Node.js 18+ and npm to be available on PATH.
            - setup_deps: Install system dependencies for Chromium on Linux
              (requires sudo). Run if Chromium fails to launch with missing library errors.
            - status: Check if playwright-cli is installed and show current session info.
            - list: List all active browser sessions.
            - close: Close a specific browser session (default session if none specified).
            - close_all: Close all active browser sessions.
            - kill_all: Forcefully kill all browser daemon processes.
              Use when sessions become unresponsive.
            - delete_data: Delete persisted browser profile data for a session.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The session management action to perform',
                values: ['setup', 'setup_deps', 'status', 'list', 'close', 'close_all', 'kill_all', 'delete_data'],
                required: true,
            ),
            new StringParameter(
                name: 'session',
                description: 'Target session name for close/delete_data. Uses default session if not specified.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $session = $input['session'] ?? '';

        return match ($action) {
            'setup' => $this->setup(),
            'setup_deps' => $this->setupDeps(),
            'status' => $this->status(),
            'list' => $this->listSessions(),
            'close' => $this->closeSession($session),
            'close_all' => $this->closeAll(),
            'kill_all' => $this->killAll(),
            'delete_data' => $this->deleteData($session),
            default => ToolResult::error("Unknown session action: {$action}"),
        };
    }

    private function setup(): ToolResult
    {
        if ($this->installer->isInstalled()) {
            return ToolResult::success(
                "## Browser Setup\n\n"
                . "playwright-cli is already installed at:\n"
                . $this->runner->browserDir() . "/node_modules/.bin/playwright-cli\n\n"
                . "Default session: `" . $this->runner->defaultSession() . "`",
            );
        }

        $result = $this->installer->install();

        if (!$result['success']) {
            return ToolResult::error("## Browser Setup Failed\n\n" . $result['message']);
        }

        // Clear cached binary path so runner picks up the new install
        $this->runner->clearBinaryCache();

        return ToolResult::success($result['message']);
    }

    private function setupDeps(): ToolResult
    {
        if (!$this->installer->isInstalled()) {
            return ToolResult::error(
                "playwright-cli is not installed. Run `setup` first before installing system dependencies.",
            );
        }

        $result = $this->installer->installDeps();

        if (!$result['success']) {
            return ToolResult::error("## System Dependencies Failed\n\n" . $result['message']);
        }

        return ToolResult::success("## System Dependencies Installed\n\n" . $result['message']);
    }

    private function status(): ToolResult
    {
        $output = "## Browser Status\n\n";

        $installed = $this->runner->isInstalled();
        $output .= "**playwright-cli:** " . ($installed ? 'Installed' : 'Not installed') . "\n";
        $output .= "**Default session:** `" . $this->runner->defaultSession() . "`\n";
        $output .= "**Browser directory:** " . $this->runner->browserDir() . "\n";

        $output .= "**Browsers path:** " . $this->runner->resolveBrowsersPath() . "\n";

        if ($installed) {
            // Check if binary resolves
            $binary = $this->runner->resolveBinary();
            $output .= "**Binary:** {$binary}\n";

            // Try to list sessions to check if daemon is running
            $result = $this->runner->run('list', [], '', timeout: 5);
            if ($result['exit_code'] === 0 && $result['stdout'] !== '') {
                $output .= "\n### Active Sessions\n\n" . $result['stdout'];
            } else {
                $output .= "\nNo active browser sessions.";
            }
        } else {
            $prereqs = $this->installer->checkPrerequisites();
            $output .= "**Node.js:** " . ($prereqs['node'] ? $prereqs['node_version'] : 'Not found') . "\n";
            $output .= "**npm:** " . ($prereqs['npm'] ? $prereqs['npm_version'] : 'Not found') . "\n\n";
            $output .= "Run `browser_session` with action `setup` to install.";
        }

        return ToolResult::success($output);
    }

    private function listSessions(): ToolResult
    {
        $result = $this->runner->run('list', [], '', timeout: 5);

        if ($result['exit_code'] === 127) {
            return ToolResult::error(
                "playwright-cli is not installed. Use action `setup` to install it.",
            );
        }

        $output = "## Browser Sessions\n\n";

        if ($result['exit_code'] !== 0) {
            $error = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            return ToolResult::error($output . $error);
        }

        $stdout = $result['stdout'];
        $output .= $stdout !== '' ? $stdout : 'No active sessions.';

        return ToolResult::success($output);
    }

    private function closeSession(string $session): ToolResult
    {
        $result = $this->runner->run('close', [], $session, timeout: 10);

        if ($result['exit_code'] === 127) {
            return ToolResult::error("playwright-cli is not installed.");
        }

        $sessionName = $session !== '' ? $session : $this->runner->defaultSession();
        $output = "## Session Closed: {$sessionName}\n\n";

        if ($result['exit_code'] !== 0) {
            $error = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            return ToolResult::error($output . $error);
        }

        $output .= $result['stdout'] !== '' ? $result['stdout'] : 'Browser closed.';

        return ToolResult::success($output);
    }

    private function closeAll(): ToolResult
    {
        $result = $this->runner->run('close-all', [], '', timeout: 10);

        if ($result['exit_code'] === 127) {
            return ToolResult::error("playwright-cli is not installed.");
        }

        $output = "## All Sessions Closed\n\n";
        $output .= $result['stdout'] !== '' ? $result['stdout'] : 'All browsers closed.';

        return ToolResult::success($output);
    }

    private function killAll(): ToolResult
    {
        $result = $this->runner->run('kill-all', [], '', timeout: 10);

        if ($result['exit_code'] === 127) {
            return ToolResult::error("playwright-cli is not installed.");
        }

        $output = "## All Processes Killed\n\n";
        $output .= $result['stdout'] !== '' ? $result['stdout'] : 'All browser processes terminated.';

        return ToolResult::success($output);
    }

    private function deleteData(string $session): ToolResult
    {
        $result = $this->runner->run('delete-data', [], $session, timeout: 10);

        if ($result['exit_code'] === 127) {
            return ToolResult::error("playwright-cli is not installed.");
        }

        $sessionName = $session !== '' ? $session : $this->runner->defaultSession();
        $output = "## Data Deleted: {$sessionName}\n\n";

        if ($result['exit_code'] !== 0) {
            $error = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            return ToolResult::error($output . $error);
        }

        $output .= $result['stdout'] !== '' ? $result['stdout'] : 'Browser data deleted.';

        return ToolResult::success($output);
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The session management action to perform',
                            'enum' => ['setup', 'setup_deps', 'status', 'list', 'close', 'close_all', 'kill_all', 'delete_data'],
                        ],
                        'session' => [
                            'type' => 'string',
                            'description' => 'Target session name for close/delete_data.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
