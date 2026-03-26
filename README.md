# Nightstats

PHP library for Nightscout data analysis (continuous glucose monitoring).

## Installation

```bash
composer require hifolks/statistics guzzlehttp/guzzle
```

## Basic Usage

```php
<?php

require_once 'vendor/autoload.php';
require_once 'Nightstats.php';

$nightstats = new Nightstats('https://your-nightscout.fly.dev', 70, 180);

$result = $nightstats->getStats(14, true);

print_r($result);
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
    'start' => '2026-03-12',
    'end' => '2026-03-25',
    'days' => 14,
    'glucose' => [
        'stats' => [
            'count' => 4032,
            'mean' => 145.5,
            'sd' => 32.1,
            'cv' => 22.1,
            'tir_percent' => 68.5,
            'hypo_percent' => 2.3,
            'hyper_percent' => 29.2
        ],
        'agp' => [
            0 => ['mean' => 140.2, 'p25' => 120.5, 'p50' => 135.0, 'p75' => 160.0],
            6 => ['mean' => 130.1, 'p25' => 110.0, 'p50' => 125.0, 'p75' => 145.0],
            // ...
        ]
    ],
    'treatments' => [
        'values' => [1.5, 2.0, 1.0, ...],
        'byDate' => [
            '2026-03-12' => [1.5, 2.0],
            '2026-03-13' => [1.0, 1.5, 2.0],
            // ...
        ],
        'byHour' => [
            0 => [1.5],
            6 => [2.0, 1.0],
            // ...
        ]
    ]
]
```

### fetchTreatmentsData()

```php
fetchTreatmentsData(int $days = 14): array
```

Fetches treatments data directly from the API.

### extractTreatmentsData()

```php
extractTreatmentsData(array $data): array
```

Processes raw treatments data.

## Error Handling

The class throws exceptions on errors:
- `RuntimeException` - HTTP request error or API with no data
- `InvalidArgumentException` - Insufficient data for analysis
- `GuzzleException` - Connection error

## Result Structure

- **glucose.stats**: General statistics (mean, standard deviation, CV, TIR, hypo/hyper)
- **glucose.agp**: Ambulatory Glucose Profile by hour (mean, P25, P50, P75)
- **treatments**: Insulin data grouped by date and hour