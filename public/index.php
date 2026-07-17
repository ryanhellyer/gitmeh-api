<?php

use App\Kernel;

// Captured at the very first line of the entry point — before the autoloader,
// the Runtime, the kernel, and the container — so the /gitmeh status page can
// report the full request lifecycle, matching Laravel's LARAVEL_START scope.
$GLOBALS['_gitmeh_request_start'] = microtime(true);

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
