<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HolidayService;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function __construct(protected HolidayService $holidayService) {}

    /**
     * Get holidays for a specific country and date range.
     */
    public function index(Request $request)
    {
        $request->validate([
            'country' => 'required|string|size:2',
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        $countryCode = strtoupper($request->country);
        $startYear = (int) date('Y', strtotime($request->start));
        $endYear = (int) date('Y', strtotime($request->end));

        $holidays = $this->holidayService->getHolidaysForRange($countryCode, $startYear, $endYear);

        // Filter to only holidays within the requested date range
        $startDate = date('Y-m-d', strtotime($request->start));
        $endDate = date('Y-m-d', strtotime($request->end));

        $filteredHolidays = array_filter($holidays, function ($holiday) use ($startDate, $endDate) {
            return $holiday['date'] >= $startDate && $holiday['date'] <= $endDate;
        });

        // Format for FullCalendar
        $formattedHolidays = array_map(function ($holiday) {
            return [
                'id' => 'holiday_'.$holiday['date'],
                'title' => $holiday['localName'] ?? $holiday['name'],
                'start' => $holiday['date'],
                'allDay' => true,
                'display' => 'background',
                'backgroundColor' => 'rgba(239, 68, 68, 0.15)',
                'borderColor' => 'transparent',
                'classNames' => ['holiday-event'],
                'extendedProps' => [
                    'type' => 'holiday',
                    'name' => $holiday['name'],
                    'localName' => $holiday['localName'] ?? $holiday['name'],
                    'countryCode' => $holiday['countryCode'],
                    'fixed' => $holiday['fixed'] ?? false,
                    'global' => $holiday['global'] ?? true,
                    'types' => $holiday['types'] ?? [],
                ],
            ];
        }, array_values($filteredHolidays));

        return response()->json($formattedHolidays);
    }

    /**
     * Get list of available countries.
     */
    public function countries()
    {
        $countries = $this->holidayService->getCountries();

        return response()->json($countries);
    }
}
