<?php

// Stub for PHPStan only: this package ships controllers that extend the
// consuming application's own base controller, which does not exist when
// analysing this package in isolation. This lets PHPStan resolve the
// symbol against the framework's real base Controller instead of
// reporting an unignorable "unknown class" error.

namespace App\Http\Controllers;

use Phaseolies\Http\Controllers\Controller as BaseController;

class Controller extends BaseController
{
}
