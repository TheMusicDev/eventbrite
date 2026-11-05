<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Pest's root helpers.php auto-include proved flaky across environments
// (loaded by Pest locally, not at all in CI); Support/helpers.php is
// explicit and include_once makes double-loads harmless everywhere.
require_once __DIR__ . '/Support/helpers.php';
