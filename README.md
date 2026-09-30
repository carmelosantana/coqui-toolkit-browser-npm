# Coqui Browser Toolkit

> Deprecated: this npm-backed toolkit has been superseded by `carmelosantana/coqui-browser`, which uses the Playwright PHP library directly.

If you are adopting the new toolkit, remove this package first to avoid confusion between two browser toolkits:

```bash
composer remove coquibot/coqui-toolkit-browser
composer require carmelosantana/coqui-browser
```

The new toolkit writes artifacts under `.workspace/browser-playwright/` and supports multi-tab page management, storage-state workflows, and a richer Playwright-native runtime.

Browser automation toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Wraps [playwright-cli](https://github.com/anthropics/playwright-cli) to give agents full web browsing capabilities including navigation, page interaction, screenshots, cookie/storage management, and session control.

## Requirements

- PHP 8.4+
- Node.js 18+ and npm (for playwright-cli installation)

## Installation

```bash
composer require coquibot/coqui-toolkit-browser
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` -- no manual registration needed.

On first use, the agent will automatically install playwright-cli and Chromium into `.workspace/browser/` via the `browser_session setup` action. No manual Node.js setup is needed.

## Tools Provided

### `browser`

Navigate web pages and interact with elements.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `open`, `click`, `dblclick`, `fill`, `type`, `press`, `keydown`, `keyup`, `hover`, `select`, `check`, `uncheck`, `scroll`, `drag`, `upload`, `back`, `forward`, `reload`, `eval`, `resize`, `wait`, `close` |
| `url` | string | No | URL for `open` action |
| `ref` | string | No | Element ref from snapshot (for interaction actions) |
| `value` | string | No | Value for `fill`, `type`, `select`, `eval` |
| `key` | string | No | Key for `press`, `keydown`, `keyup` (e.g. "Enter", "Tab") |
| `session` | string | No | Override session name |
| `headed` | bool | No | Show visible browser (default: headless) |
| `browser_engine` | enum | No | `chromium`, `firefox`, `webkit` |
| `persistent` | bool | No | Use persistent browser profile |

### `browser_capture`

Capture page state as snapshots, screenshots, or PDFs.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `snapshot`, `screenshot`, `pdf` |
| `ref` | string | No | Target element ref |
| `filename` | string | No | Output filename (auto-generated if omitted) |
| `session` | string | No | Override session name |

### `browser_storage`

Manage cookies, localStorage, sessionStorage, and persist browser state.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `cookie_get`, `cookie_get_all`, `cookie_set`, `cookie_delete`, `cookie_clear`, `localstorage_get`, `localstorage_set`, `localstorage_delete`, `localstorage_clear`, `sessionstorage_get`, `sessionstorage_set`, `sessionstorage_delete`, `sessionstorage_clear`, `state_save`, `state_load` |
| `name` | string | No | Cookie/storage key name |
| `value` | string | No | Value for set operations |
| `domain` | string | No | Cookie domain |
| `filename` | string | No | State filename for save/load |
| `session` | string | No | Override session name |

### `browser_session`

Manage browser sessions and playwright-cli installation.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `setup`, `setup_deps`, `status`, `list`, `close`, `close_all`, `kill_all`, `delete_data` |
| `session` | string | No | Target session for close/delete_data |

## Session Management

Sessions are auto-scoped to the workspace using a deterministic hash (`coqui-{md5_8chars}`). All four tools share the same session by default, so cookies and state persist across tool calls.

Override the session name with the `session` parameter on any tool to manage multiple independent browser contexts.

## Agent Workflow

1. `browser_session` action `setup` -- install playwright-cli (first time only)
2. `browser` action `open` -- navigate to a URL
3. `browser_capture` action `snapshot` -- get the accessibility tree with element refs
4. `browser` action `click`/`fill`/`type` -- interact using refs from the snapshot
5. `browser_capture` action `screenshot` -- capture visual output
6. `browser_session` action `close` -- clean up when done

## Standalone Usage

```php
<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Browser\BrowserToolkit;

require __DIR__ . '/vendor/autoload.php';

$toolkit = BrowserToolkit::fromEnv();

foreach ($toolkit->tools() as $tool) {
    echo $tool->name() . ': ' . $tool->description() . PHP_EOL;
}

// Navigate to a page
$browser = $toolkit->tools()[0];
$result = $browser->execute([
    'action' => 'open',
    'url' => 'https://example.com',
]);
echo $result->content;
```

## Development

```bash
git clone https://github.com/AgentCoqui/coqui-toolkit-browser.git
cd coqui-toolkit-browser
composer install
```

### Run tests

```bash
./vendor/bin/pest
```

### Static analysis

```bash
./vendor/bin/phpstan analyse
```

## License

MIT
