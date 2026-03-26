<?php

namespace Nightstats;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HiFolks\Statistics\Statistics;

class Nightstats
{
    private Client $httpClient;
    private string $sgvUrl;
    private string $treatmentsUrl;
    private int $minGlucose;
    private int $maxGlucose;

    public function __construct(string $domain, int $minGlucose = 70, int $maxGlucose = 180)
    {
        $domain = rtrim($domain, '/');
        $this->validateDomain($domain);
        $this->validateGlucoseRange($minGlucose, $maxGlucose);

        $this->httpClient = new Client();
        $this->sgvUrl = $domain . '/api/v1/entries/sgv.json';
        $this->treatmentsUrl = $domain . '/api/v1/treatments.json';
        $this->minGlucose = $minGlucose;
        $this->maxGlucose = $maxGlucose;
    }

    private function validateDomain(string $domain): void
    {
        if (empty(trim($domain))) {
            throw new \InvalidArgumentException("Domain cannot be empty");
        }

        if (!filter_var($domain, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("Invalid domain format. Must be a valid URL (e.g., https://example.com)");
        }
    }

    private function validateGlucoseRange(int $minGlucose, int $maxGlucose): void
    {
        if ($minGlucose <= 0) {
            throw new \InvalidArgumentException("minGlucose must be greater than 0");
        }

        if ($maxGlucose <= 0) {
            throw new \InvalidArgumentException("maxGlucose must be greater than 0");
        }

        if ($minGlucose >= $maxGlucose) {
            throw new \InvalidArgumentException("minGlucose must be less than maxGlucose");
        }

        if ($maxGlucose > 600) {
            throw new \InvalidArgumentException("maxGlucose must be less than or equal to 600 mg/dL");
        }
    }

    public function getStats(int $days = 14, bool $includeTreatments = false): array
    {
        [$start, $end] = $this->getDateRange($days);

        $glucoseData = $this->fetchGlucoseData($days);
        $glucoseExtracted = $this->extractGlucoseData($glucoseData);
        $stats = $this->calculateStatistics($glucoseExtracted['values']);
        $agp = $this->calculateAgp($glucoseExtracted['byHour']);

        $result = [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'days' => $days,
            'glucose' => [
                'values' => $glucoseExtracted['values'],
                'stats' => $stats,
                'agp' => $agp,
            ]
        ];

        if ($includeTreatments) {
            $treatmentsData = $this->fetchTreatmentsData($days);
            $treatments = $this->extractTreatmentsData($treatmentsData);
            $result['treatments'] = $treatments;
        }

        return $result;
    }



    private function fetchGlucoseData(int $days): array
    {
        [$start, $end] = $this->getDateRange($days);

        $count = $days * 300;

        $url = $this->sgvUrl
            . '?find[dateString][$gte]=' . $start->format('Y-m-d')
            . '&find[dateString][$lte]=' . $end->format('Y-m-d')
            . "&count={$count}";

        return $this->fetchData($url);
    }

    private function getDateRange(int $days): array
    {
        $end = (new \DateTime())->modify("-1 days");
        $start = (clone $end)->modify("-{$days} days");
        return [$start, $end];
    }

    private function fetchData(string $url): array
    {
        try {
            $response = $this->httpClient->get($url);
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException("Error fetching API data: HTTP " . $response->getStatusCode());
            }

            if (empty($data)) {
                throw new \RuntimeException("No data returned from API");
            }

            return $data;
        }
        catch (GuzzleException $e) {
            throw new \RuntimeException("HTTP request error: " . $e->getMessage(), 0, $e);
        }
    }

    private function extractGlucoseData(array $data): array
    {
        $values = [];
        $byHour = [];

        foreach ($data as $item) {
            if (!isset($item['sgv'], $item['dateString'])) {
                continue;
            }

            $glucose = (int)$item['sgv'];

            if ($glucose <= 0) {
                continue;
            }

            $values[] = $glucose;
            $hour = (int)date('H', strtotime($item['dateString']));
            $byHour[$hour][] = $glucose;
        }

        return ['values' => $values, 'byHour' => $byHour];
    }

    private function calculateStatistics(array $values): array
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("Insufficient data for analysis");
        }

        $stat = Statistics::make($values);
        $count = count($values);
        $mean = $stat->mean();
        $sd = $stat->stdev();
        $cv = $stat->coefficientOfVariation() ?? ($sd / $mean) * 100;

        $tir = count(array_filter($values, fn($v) => $v >= $this->minGlucose && $v <= $this->maxGlucose));
        $tbr = count(array_filter($values, fn($v) => $v < $this->minGlucose));
        $tar = count(array_filter($values, fn($v) => $v > $this->maxGlucose));

        return [
            'count' => $count,
            'mean' => round($mean, 2),
            'sd' => round($sd, 2),
            'cv' => round($cv, 2),
            'tir_percent' => round(($tir / $count) * 100, 2),
            'tbr_percent' => round(($tbr / $count) * 100, 2),
            'tar_percent' => round(($tar / $count) * 100, 2),
        ];
    }

    //Ambulatory Glucose Profile
    private function calculateAgp(array $byHour): array
    {
        $agp = [];

        foreach ($byHour as $hour => $values) {
            if (count($values) < 2) {
                continue;
            }

            $stat = Statistics::make($values);

            $agp[$hour] = [
                'mean' => $stat->mean(),
                'p25' => $stat->firstQuartile(),
                'p50' => $stat->median(),
                'p75' => $stat->thirdQuartile(),
                'values' => $values
            ];
        }

        ksort($agp);

        return $agp;
    }

    private function fetchTreatmentsData(int $days = 14): array
    {
        [$start, $end] = $this->getDateRange($days);

        $url = $this->treatmentsUrl
            . '?find[created_at][$gte]=' . $start->format('Y-m-d\TH:i:s\Z')
            . '&find[created_at][$lte]=' . $end->format('Y-m-d\TH:i:s\Z');

        return $this->fetchData($url);
    }

    private function extractTreatmentsData(array $data): array
    {
        $insulinValues = [];
        $insulinByDate = [];
        $insulinByHour = [];

        foreach ($data as $item) {
            if (!isset($item['insulin']) || !isset($item['sysTime'])) {
                continue;
            }

            $insulin = (float)$item['insulin'];

            if ($insulin <= 0) {
                continue;
            }

            $date = date('Y-m-d', strtotime($item['sysTime']));
            $hour = (int)date('H', strtotime($item['sysTime']));

            $insulinValues[] = $insulin;
            $insulinByDate[$date][] = $insulin;
            $insulinByHour[$hour][] = $insulin;
        }

        ksort($insulinByDate);
        ksort($insulinByHour);

        return [
            'values' => $insulinValues,
            'byDate' => $insulinByDate,
            'byHour' => $insulinByHour,
        ];
    }
}