<?php

declare(strict_types=1);

namespace Waypost\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

/** Runs the tests in the workbench application (testbench.yaml), on the database of phpunit.xml.dist. */
abstract class TestCase extends Orchestra
{
    use WithWorkbench;
}
