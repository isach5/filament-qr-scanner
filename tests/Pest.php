<?php

use Emuniq\FilamentQrScanner\Tests\AppOwnedComponentTestCase;
use Emuniq\FilamentQrScanner\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Boots a host app that already owns a component name, so it needs its own
// base test case — Pest only allows one per directory.
uses(AppOwnedComponentTestCase::class)->in('Isolation');
