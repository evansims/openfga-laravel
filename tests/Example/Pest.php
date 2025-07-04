<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Example Test Suite Configuration
|--------------------------------------------------------------------------
|
| This file configures the Pest PHP testing framework for the example
| application test suite. It validates that the example app structure
| and code quality meet the expected standards.
|
*/

// Ensure we're testing from the correct directory
uses()->beforeEach(function (): void {
    $examplePath = __DIR__ . '/../../example';
    
    if (!is_dir($examplePath)) {
        $this->markTestSkipped('Example directory not found. Please ensure the example application exists at: ' . $examplePath);
    }
})->in(__DIR__);

