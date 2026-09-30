<?php

require(__DIR__ . '/../../../vendor/autoload.php');

// In an application that installed the package, Composer's extra.prado registers the class map
// and the error messages. Here the package is the root project, so the entry script does it.
\Prado\Prado::registerClassMap(json_decode((string) file_get_contents(__DIR__ . '/../../../config/classes.json'), true));
\Prado\Exceptions\TException::addMessageFile(__DIR__ . '/../../../config/errorMessages.txt');

$application = new \Prado\TApplication(__DIR__ . '/protected', false);
$application->run();
