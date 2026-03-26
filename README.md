# Nightstats

PHP library for Nightscout data analysis (continuous glucose monitoring).

## Installation

```bash
composer install
```

## Basic Usage

```php
<?php

require_once 'vendor/autoload.php';

use Nightstats\Nightstats;
use GuzzleHttp\Exception\GuzzleException;

$nightstats = new Nightstats('https://your-nightscout.fly.dev', 70, 180);

try {
    $result = $nightstats->getStats(14, true);
    print_r($result);
} catch (GuzzleException $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
} catch (RuntimeException $e) {
    echo "API error: " . $e->getMessage() . "\n";
} catch (InvalidArgumentException $e) {
    echo "Data error: " . $e->getMessage() . "\n";
}
```

## Constructor

```php
new Nightstats(string $domain, int $minGlucose = 70, int $maxGlucose = 180)
```

- `$domain` - Base URL of Nightscout (e.g., `https://my-nightscout.fly.dev`)
- `$minGlucose` - Lower limit for TIR (default: 70 mg/dL)
- `$maxGlucose` - Upper limit for TIR (default: 180 mg/dL)

## Methods

### getStats()

```php
getStats(int $days = 14, bool $includeTreatments = false): array
```

Returns glucose statistics for the specified number of days.

- `$days` - Number of days for analysis (default: 14)
- `$includeTreatments` - Include insulin data (default: false)

**Return:**
```php
[
    'start' => '2026-03-11',
    'end' => '2026-03-25',
    'days' => 14,
    'glucose' => [
        'values' => [231, 217, 235, ...],
        'stats' => [
            'count' => 317,
            'mean' => 168.66,
            'sd' => 72.71,
            'cv' => 43.11,
            'tir_percent' => 62.46,
            'tbr_percent' => 1.58,
            'tar_percent' => 35.96
        ],
        'agp' => [
            0 => ['mean' => 88.3, 'p25' => 81.75, 'p50' => 87.5, 'p75' => 91, 'values' => [85, 87, 91, ...]],
            6 => ['mean' => 207.75, 'p25' => 184.5, 'p50' => 206, 'p75' => 232.75, 'values' => [180, 205, 220, ...]],
            8 => ['mean' => 357.25, 'p25' => 346.5, 'p50' => 359.5, 'p75' => 373.75, 'values' => [340, 355, 380, ...]],
            // ... (24 hours)
        ]
    ],
    'treatments' => [
        'values' => [6, 16, 12, 12, 15, 7, 10, 12, 7, 6, 10, 16, 6, 7],
        'byDate' => [
            '2026-03-23' => [7],
            '2026-03-24' => [7, 10, 12, 7, 6, 10, 16, 6],
            '2026-03-25' => [6, 16, 12, 12, 15],
        ],
        'byHour' => [
            1 => [6],
            2 => [15],
            8 => [10, 16],
            9 => [12, 12],
            // ...
        ]
    ]
]
```

## Error Handling

The class throws exceptions on errors:
- `InvalidArgumentException` - Invalid constructor parameters (domain, glucose range) or insufficient data
- `RuntimeException` - HTTP request error or API with no data
- `GuzzleException` - Connection error

### Constructor Validation

The constructor validates:
- Domain must be a valid URL (e.g., `https://example.com`)
- minGlucose must be greater than 0
- maxGlucose must be greater than 0
- minGlucose must be less than maxGlucose
- maxGlucose must be less than or equal to 600 mg/dL

## Result Structure

- **glucose.values**: Array of raw glucose readings
- **glucose.stats**: General statistics (mean, standard deviation, CV, TIR, TBR, TAR)
- **glucose.agp**: Ambulatory Glucose Profile by hour (mean, P25, P50, P75, values)
- **treatments**: Insulin data grouped by date and hour