<?php

use Emuniq\FilamentQrScanner\Tests\AppOwnedComponentTestCase;
use Emuniq\FilamentQrScanner\Tests\BrokenConfigTestCase;
use Emuniq\FilamentQrScanner\Tests\DisabledAliasesTestCase;
use Emuniq\FilamentQrScanner\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Each of these boots a host app configured differently, so each needs its own
// base test case — and Pest only allows one per directory.
uses(AppOwnedComponentTestCase::class)->in('Isolation/AppOwned');
uses(DisabledAliasesTestCase::class)->in('Isolation/DisabledAliases');
uses(BrokenConfigTestCase::class)->in('Isolation/BrokenConfig');
