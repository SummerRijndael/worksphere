<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HolidayService
{
    protected string $baseUrl = 'https://date.nager.at/api/v3';

    /**
     * Get public holidays for a specific country and year.
     */
    public function getHolidays(string $countryCode, int $year): array
    {
        $cacheKey = "holidays_{$countryCode}_{$year}";

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($countryCode, $year) {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/PublicHolidays/{$year}/{$countryCode}");

                if ($response->successful()) {
                    return $response->json();
                }

                return [];
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch holidays: {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Get available countries.
     */
    public function getCountries(): array
    {
        return Cache::remember('holiday_countries', now()->addDays(30), function () {
            try {
                $response = Http::timeout(10)->get("{$this->baseUrl}/AvailableCountries");

                if ($response->successful()) {
                    return $response->json();
                }

                return [];
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch countries: {$e->getMessage()}");

                return [];
            }
        });
    }

    /**
     * Get holidays for multiple years (useful for calendar spanning year boundaries).
     */
    public function getHolidaysForRange(string $countryCode, int $startYear, int $endYear): array
    {
        $holidays = [];

        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearHolidays = $this->getHolidays($countryCode, $year);
            $holidays = array_merge($holidays, $yearHolidays);
        }

        return $holidays;
    }
}
