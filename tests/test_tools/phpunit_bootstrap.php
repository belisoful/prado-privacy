<?php

/**
 * Common settings for all unit tests of the extension.
 *
 * Autoloads the framework and the extension through Composer's PSR-4 map, then registers the
 * extension's error message file (so exception codes resolve to text) and its Prado3 short-name
 * class map -- the two things `extra.prado.error-messages` and `extra.prado.class-map` register
 * for an installed application. The unit suite does this by hand because nothing has run a real
 * `composer require`; tests/integration proves the installed path separately.
 *
 * It then constructs (without running) a TApplication on tests/unit/app, so modules and controls
 * have an application, a request and a response to attach to.
 */

require_once(__DIR__ . '/../../vendor/autoload.php');

// Lets a test construct another TApplication (Prado::setApplication refuses a second one otherwise).
define('PRADO_TEST_RUN', true);

\Prado\Exceptions\TException::addMessageFile(__DIR__ . '/../../config/errorMessages.txt');
\Prado\Prado::registerClassMap(
	json_decode((string) file_get_contents(__DIR__ . '/../../config/classes.json'), true, 512, JSON_THROW_ON_ERROR)
);

$application = new \Prado\TApplication(__DIR__ . '/../unit/app', false);

// The default response module starts an output buffer when it initializes; create it here and
// close that buffer, so phpunit's own output is not captured and no test is flagged for it.
$level = ob_get_level();
$application->getResponse();
while (ob_get_level() > $level) {
	ob_end_flush();
}
