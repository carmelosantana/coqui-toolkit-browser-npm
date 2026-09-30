<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Browser;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\Browser\Runtime\BrowserInstaller;
use CoquiBot\Toolkits\Browser\Runtime\PlaywrightRunner;

/**
 * Browser automation toolkit for Coqui.
 *
 * Wraps playwright-cli to give agents full web browsing capabilities including
 * navigation, interaction, snapshots, screenshots, cookie/storage management,
 * and session lifecycle control.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * No API keys required — uses a locally installed Chromium browser.
 *
 * @see https://github.com/anthropics/playwright-cli
 */
final class BrowserToolkit implements ToolkitInterface
{
    private readonly PlaywrightRunner $runner;
    private readonly BrowserInstaller $installer;

    public function __construct(
        private readonly string $workspacePath,
        ?PlaywrightRunner $runner = null,
        ?BrowserInstaller $installer = null,
        string $browsersPath = '',
    ) {
        $this->runner = $runner ?? new PlaywrightRunner($workspacePath, browsersPath: $browsersPath);
        $browserDir = $this->runner->browserDir();
        $this->installer = $installer ?? new BrowserInstaller($browserDir, browsersPath: $this->runner->resolveBrowsersPath());
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        $browsersPath = getenv('PLAYWRIGHT_BROWSERS_PATH');
        if ($browsersPath === false) {
            $browsersPath = '';
        }

        return new self(workspacePath: $workspacePath, browsersPath: $browsersPath);
    }

    public function tools(): array
    {
        return [
            new BrowserTool($this->runner),
            new BrowserCaptureTool($this->runner, $this->workspacePath),
            new BrowserStorageTool($this->runner, $this->workspacePath),
            new BrowserSessionTool($this->runner, $this->installer),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <BROWSER-TOOLKIT-GUIDELINES>
            ## Browser Automation via playwright-cli

            You have full web browsing capabilities through 4 tools:

            ### Setup
            If any browser tool returns "playwright-cli not found", call `browser_session`
            with action `setup` first. This installs playwright-cli and Chromium into the
            workspace. Requires Node.js 18+ on the host.

            ### Workflow
            1. **Navigate**: `browser` action `open` with a URL → opens the page
            2. **Observe**: `browser_capture` action `snapshot` → returns an accessibility
               tree with `ref` identifiers for every interactive element
            3. **Interact**: Use `ref` values from the snapshot with `browser` actions like
               `click`, `fill`, `type`, `select`, `hover`, `press`
            4. **Capture**: `browser_capture` action `screenshot` or `pdf` for visual output
            5. **Clean up**: `browser_session` action `close` when done

            ### Key Concepts
            - **Refs**: The snapshot returns elements with numeric ref identifiers (e.g. ref="42").
              Use these with `ref` parameter in browser/browser_capture actions.
            - **Sessions**: Each workspace auto-gets a deterministic session name. All tools
              share the same session by default. Override with the `session` parameter.
            - **Headless**: All browsing is headless by default. Set `headed: true` for visible browser.
            - **State persists**: Cookies, localStorage, and login state persist within a session.
              Use `browser_storage` to manage state. Use `browser_session` action `delete_data`
              to reset.

            ### Tips
            - Always take a snapshot before interacting to get current refs.
            - Fill forms with `fill` (replaces content) or `type` (appends characters).
            - Use `browser_storage` to save/load state across sessions with `state_save`/`state_load`.
            - The `eval` action can run arbitrary JavaScript on the page for advanced scraping.
            - Screenshots are saved to `.workspace/browser/screenshots/` and can be shared with the user.

            ### When NOT to Use
            - For simple HTTP requests without JS rendering, prefer curl or HTTP tools if available.
            - For API calls, use direct HTTP requests instead of browser automation.
            - Do not use browser tools for downloading large files.
            </BROWSER-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
