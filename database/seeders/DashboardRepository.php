<?php

namespace App\Module\BakeTeach\Repository;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Package\Exception\HttpException;

class DashboardRepository
{
    public function overview($request)
    {
        $start_date = $request['start_date'] ?? null;
        $end_date = $request['end_date'] ?? null;

        // Sử dụng Carbon để xác định khoảng thời gian
        $start = $start_date ? Carbon::parse($start_date) : Carbon::now()->startOfMonth(Carbon::MONDAY);
        $end = $end_date ? Carbon::parse($end_date) : Carbon::now()->endOfMonth(Carbon::SUNDAY);
        $dateRange = [$start->toDateString(), $end->toDateString()];

        // Query các số liệu trong khoảng thời gian
        $totalAmountCourseInvoice = DB::table('course_invoices')
            ->where('status_complete', 'completed')
            ->whereBetween('created_at', $dateRange)
            ->sum('total');

        $totalAmountProductInvoice = DB::table('orders')
            ->where('status', 'complete')
            ->whereBetween('order_date', $dateRange)
            ->sum('total');

        $refunds = DB::table('refunds')
            ->where('status', 'approved')
            ->whereBetween('created_at', $dateRange);

        $totalCountRefund = $refunds->count();
        $totalAmountRefund = $refunds->sum('total');

        $withdrawals = DB::table('request_withdrawal')
            ->where('type', 'withdrawal')
            ->where('type_withdrawal', 'withdrawal_commission')
            ->where('status', 'approve')
            ->whereBetween('created_at', $dateRange);

        $totalAmountWithdrawalCommissionOnline = $withdrawals->where('classification', 'online')->sum('money');
        $totalAmountWithdrawalCommissionOffline = $withdrawals->where('classification', 'offline')->sum('money');

        return [
            "sales" => [
                "course_invoice" => $totalAmountCourseInvoice,
                "product_invoice" => $totalAmountProductInvoice,
            ],
            "refund" => [
                "total_count" => $totalCountRefund,
                "total_amount" => $totalAmountRefund,
            ],
            "commission_course" => [
                "online" => $totalAmountWithdrawalCommissionOnline,
                "offline" => $totalAmountWithdrawalCommissionOffline,
            ]
        ];
    }

    public function pie($request)
    {
        $start_date = $request['start_date'] ?? null;
        $end_date = $request['end_date'] ?? null;

        // Sử dụng Carbon để xác định khoảng thời gian
        $start = $start_date ? Carbon::parse($start_date) : Carbon::now()->startOfMonth(Carbon::MONDAY);
        $end = $end_date ? Carbon::parse($end_date) : Carbon::now()->endOfMonth(Carbon::SUNDAY);
        $dateRange = [$start->toDateString(), $end->toDateString()];

        $percentagePieCount = DB::table('course_invoices')
            ->select(
                DB::raw("classification"),
                DB::raw("COUNT(*) as count"),
                DB::raw("
                  ROUND(
                      (COUNT(*) /
                      (SELECT COUNT(*) FROM course_invoices
                       WHERE status_complete = 'completed'
                       AND created_at BETWEEN '{$dateRange[0]}' AND '{$dateRange[1]}')) * 100,
                  2) as percentage")
            )
            ->where('status_complete', 'completed')
            ->whereBetween('created_at', $dateRange)
            ->groupBy('classification')
            ->get();

        $percentagePieRevenue = DB::table('course_invoices')
            ->select(
                DB::raw("classification"),
                DB::raw("SUM(total) as total_sum"),
                DB::raw("
                  ROUND(
                      (SUM(total) /
                      (SELECT SUM(total) FROM course_invoices
                       WHERE status_complete = 'completed'
                       AND created_at BETWEEN '{$dateRange[0]}' AND '{$dateRange[1]}')) * 100,
                  2) as percentage")
            )
            ->where('status_complete', 'completed')
            ->whereBetween('created_at', $dateRange)
            ->groupBy('classification')
            ->get();

        return [
            'revenue' => json_decode(json_encode($percentagePieRevenue), true),
            'count' => json_decode(json_encode($percentagePieCount), true)
        ];
    }

    public function growth($request)
    {
        $from = $request['from'];
        $to = $request['to'];
        //
        $course_invoices = DB::table('course_invoices')
            ->where('status_complete', 'completed')
            ->when(isset($request['user_id']), function ($query) use ($request) {
                return $query->where('user_id', $request['user_id']);
            });

        $product_invoices = DB::table('orders')
            ->where('status', 'complete')
            ->when(isset($request['user_id']), function ($query) use ($request) {
                return $query->where('user_id', $request['user_id']);
            });
        //
        //
        $typeChart = $request['type_chart'] ?? 'date';
        if ($typeChart) {
            if ($typeChart == 'date') {
                $time = $this->getParamsTimeFormatGrowth($typeChart, $from, $to);
                //
                $startDate = $time['from'];
                $endDate = $time['to'];
                //
                $course_invoices
                    ->whereDate('created_at', '>=', $startDate)
                    ->whereDate('created_at', '<=', $endDate)
                    ->select(
                        DB::raw("DATE_FORMAT(created_at, '%d/%m/%Y') as date"),
                        DB::raw('SUM(total) as total')
                    )->groupBy(DB::raw("DATE_FORMAT(created_at, '%d/%m/%Y')"));

                $product_invoices
                    ->whereDate('order_date', '>=', $startDate)
                    ->whereDate('order_date', '<=', $endDate)
                    ->select(
                        DB::raw("DATE_FORMAT(order_date, '%d/%m/%Y') as date"),
                        DB::raw('SUM(final_total) as total')
                    )->groupBy(DB::raw("DATE_FORMAT(order_date, '%d/%m/%Y')"));
            }
            if ($typeChart == 'month') {
                $time = $this->getParamsTimeFormatGrowth($typeChart, $from, $to);
                //
                $startDate = $time['from'];
                $endDate = $time['to'];
                //
                $course_invoices
                    ->whereDate('created_at', '>=', $startDate)
                    ->whereDate('created_at', '<=', $endDate)
                    ->select(
                        DB::raw('SUM(total) as total'),
                        DB::raw('DATE_FORMAT(created_at, "%m/%Y") as month_year')
                    )->groupBy('month_year');

                $product_invoices
                    ->whereDate('order_date', '>=', $startDate)
                    ->whereDate('order_date', '<=', $endDate)
                    ->select(
                        DB::raw('SUM(final_total) as total'),
                        DB::raw('DATE_FORMAT(order_date, "%m/%Y") as month_year')
                    )->groupBy('month_year');
            }
            if ($typeChart == 'quarter') {
                $time = $this->getParamsTimeFormatGrowth($typeChart, $from, $to);
                //
                $startDate = $time['from'];
                $endDate = $time['to'];
                //
                $course_invoices
                    ->whereDate('created_at', '>=', $startDate)
                    ->whereDate('created_at', '<=', $endDate)
                    ->select(
                        DB::raw('YEAR(created_at) as year'),
                        DB::raw('QUARTER(created_at) as quarter'),
                        DB::raw('SUM(total) as total')
                    )->groupBy(
                        DB::raw('YEAR(created_at)'),
                        DB::raw('QUARTER(created_at)'),
                    );

                $product_invoices
                    ->whereDate('order_date', '>=', $startDate)
                    ->whereDate('order_date', '<=', $endDate)
                    ->select(
                        DB::raw('YEAR(order_date) as year'),
                        DB::raw('QUARTER(order_date) as quarter'),
                        DB::raw('SUM(final_total) as total')
                    )->groupBy(
                        DB::raw('YEAR(order_date)'),
                        DB::raw('QUARTER(order_date)'),
                    );
            }
            if ($typeChart == 'year') {
                $time = $this->getParamsTimeFormatGrowth($typeChart, $from, $to);
                //
                $startDate = $time['from'];
                $endDate = $time['to'];
                //
                $course_invoices
                    ->whereYear('created_at', '>=', $startDate->format('Y'))
                    ->whereYear('created_at', '<=', $endDate->format('Y'))
                    ->select(
                        DB::raw('SUM(total) as total'),
                        DB::raw('DATE_FORMAT(created_at, "%Y") as year')
                    )->groupBy('year');

                $product_invoices
                    ->whereYear('order_date', '>=', $startDate->format('Y'))
                    ->whereYear('order_date', '<=', $endDate->format('Y'))
                    ->select(
                        DB::raw('SUM(final_total) as total'),
                        DB::raw('DATE_FORMAT(order_date, "%Y") as year')
                    )->groupBy('year');
            }
        }
        //

        $response = $this->convertDataGrowth($typeChart, $course_invoices, $product_invoices, $from, $to);
        return $response;
        //
    }

    private function getQuarters($start, $end)
    {
        $quarters = [];

        while ($start->lessThanOrEqualTo($end)) {
            $year = $start->year;
            $month = $start->month;

            if ($month >= 1 && $month <= 3) {
                $quarters[] = "Q1-{$year}";
                $start->addMonths(3);
            } elseif ($month >= 4 && $month <= 6) {
                $quarters[] = "Q2-{$year}";
                $start->addMonths(3);
            } elseif ($month >= 7 && $month <= 9) {
                $quarters[] = "Q3-{$year}";
                $start->addMonths(3);
            } elseif ($month >= 10 && $month <= 12) {
                $quarters[] = "Q4-{$year}";
                $start->addMonths(3);
            }
        }

        return $quarters;
    }

    private function convertDataGrowth($typeChart, $course_invoices, $product_invoices, $from, $to)
    {
        $course_invoices = $course_invoices->get();
        $product_invoices = $product_invoices->get();

        // Xác định định dạng khóa (key) theo loại biểu đồ
        $keyFormat = match ($typeChart) {
            'date' => fn($item) => $item->date,
            'month' => fn($item) => $item->month_year,
            'quarter' => fn($item) => "Q{$item->quarter}-{$item->year}",
            'year' => fn($item) => $item->year,
        };

        // Áp dụng định dạng và chuyển đổi thành mảng key-value
        $data_course_invoice = $course_invoices->mapWithKeys(fn($item) => [
            $keyFormat($item) => $item->total,
        ])->toArray();

        $data_product_invoice = $product_invoices->mapWithKeys(fn($item) => [
            $keyFormat($item) => $item->total,
        ])->toArray();

        // Điều chỉnh dữ liệu để phù hợp với khoảng thời gian
        $this->fitData($data_course_invoice, $typeChart, $from, $to);
        $this->fitData($data_product_invoice, $typeChart, $from, $to);

        return [
            'course' => $data_course_invoice,
            'product' => $data_product_invoice,
        ];
    }

    private function fitData(&$data, $typeChart, $from, $to)
    {
        $mapping = [];
        $time = $this->getParamsTimeFormatGrowth($typeChart, $from, $to);
        $startDate = $time['from'];
        $endDate = $time['to'];

        switch ($typeChart) {
            case 'date':
                $this->generateDateRange($mapping, $startDate, $endDate, 'd/m/Y', 'addDay');
                break;

            case 'month':
                $this->generateDateRange($mapping, $startDate, $endDate, 'm/Y', 'addMonth');
                break;

            case 'quarter':
                $quarters = $this->getQuarters($startDate, $endDate);
                $mapping = array_fill_keys($quarters, 0);
                break;

            case 'year':
                $this->generateDateRange($mapping, $startDate, $endDate, 'Y', 'addYear', 'Y-');
                $data = collect($data)->mapWithKeys(fn($value, $key) => ["Y-$key" => $value])->toArray();
                break;
        }

        // Merge data with mapping and clean up prefix if necessary
        $dataMerged = array_merge($mapping, $data);
        $data = collect($dataMerged)->mapWithKeys(fn($value, $key) => [ltrim($key, 'Y-') => $value])
            ->map(fn($value, $key) => ['time' => $key, 'total' => $value])
            ->values()
            ->toArray();
    }

    // Helper function to generate date range mappings
    private function generateDateRange(&$mapping, $startDate, $endDate, $format, $intervalMethod, $prefix = '')
    {
        while ($startDate->lte($endDate)) {
            $mapping["$prefix" . $startDate->format($format)] = 0;
            $startDate->{$intervalMethod}();
        }
    }

    private function getParamsTimeFormatGrowth($typeChart, $from, $to)
    {
        try {
            return match ($typeChart) {
                'date' => [
                    'from' => Carbon::parse($from),
                    'to' => Carbon::parse($to),
                ],
                'month' => [
                    'from' => Carbon::createFromFormat('Y-m', $from)->startOfMonth(),
                    'to' => Carbon::createFromFormat('Y-m', $to)->endOfMonth(),
                ],
                'quarter' => [
                    'from' => Carbon::parse($this->getQuarterDateRange($from)[0]),
                    'to' => Carbon::parse($this->getQuarterDateRange($to)[1]),
                ],
                'year' => [
                    'from' => Carbon::createFromFormat('Y', $from)->startOfYear(),
                    'to' => Carbon::createFromFormat('Y', $to)->endOfYear(),
                ],
                default => throw new HttpException("Invalid chart type: $typeChart"),
            };
        } catch (HttpException $e) {
            throw new HttpException($e->getMessage());
        }
    }

    private function getQuarterDateRange($quarterString)
    {
        try {
            $parts = explode('-', $quarterString);
            if (count($parts) != 2 || strpos($parts[0], 'Q') === false) {
                throw new HttpException("Thời gian không hợp lệ !");
            }
            $quarter = (int)substr($parts[0], 1);
            $year = (int)$parts[1];

            switch ($quarter) {
                case 1:
                    $startDate = "$year-01-01";
                    $endDate = "$year-03-31";
                    break;
                case 2:
                    $startDate = "$year-04-01";
                    $endDate = "$year-06-30";
                    break;
                case 3:
                    $startDate = "$year-07-01";
                    $endDate = "$year-09-30";
                    break;
                case 4:
                    $startDate = "$year-10-01";
                    $endDate = "$year-12-31";
                    break;
                default:
                    throw new HttpException("Invalid quarter: $quarter");
            }

            return [$startDate, $endDate];
        } catch (HttpException $e) {
            throw new HttpException($e->getMessage());
        }
    }

    public function topLecturer($request)
    {
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;

        $data = DB::table('users as lecturer')
            ->leftJoin('course_invoices as ci', 'ci.lecturer_id', '=', 'lecturer.id')
            ->where('ci.status_complete', 'completed')
            ->whereMonth('ci.created_at', $currentMonth)
            ->whereYear('ci.created_at', $currentYear)
            ->select([
                'lecturer.id',
                'lecturer.full_name',
                'lecturer.avatar',
                DB::raw('SUM(ci.total) as total')
            ])
            ->groupBy('lecturer.id')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();
        return $data;
    }

    public function topCourse($request)
    {
        $currentMonth = 10;
        $currentYear = 2024;

        $number_of_purchases = DB::table('course_invoices')
            ->join('invoices as i', function ($join) {
                $join->on('i.invoice_id', '=', 'course_invoices.id');
            })->where('i.invoice_type', 'invoice_course')
            ->where('i.status', 'approved')
            ->whereMonth('i.created_at', $currentMonth)
            ->whereYear('i.created_at', $currentYear)
            ->select([
                'course_invoices.course_id',
                DB::raw('COUNT(*) as count_register')
            ])
            ->groupBy('course_invoices.course_id');

        $number_of_ratings = DB::table('ratings')
            ->where('ratings.object_type', 'course')
            ->whereMonth('ratings.created_at', $currentMonth)
            ->whereYear('ratings.created_at', $currentYear)
            ->select([
                'ratings.object_id as course_id',
                DB::raw('COUNT(*) as count_rating')
            ])
            ->groupBy('course_id');

        $avg_of_ratings = DB::table('ratings')
            ->where('ratings.object_type', 'course')
            ->whereMonth('ratings.created_at', $currentMonth)
            ->whereYear('ratings.created_at', $currentYear)
            ->select([
                'ratings.object_id as course_id',
                DB::raw('AVG(ratings.star_count) as avg_star')
            ])
            ->groupBy('course_id');

        $number_of_completed = DB::table('course_invoices')
            ->whereMonth('course_invoices.created_at', $currentMonth)
            ->whereYear('course_invoices.created_at', $currentYear)
            ->where('course_invoices.status_complete', 'completed')
            ->select([
                'course_invoices.course_id',
                DB::raw('COUNT(*) as count_completed')
            ])
            ->groupBy('course_invoices.course_id');

        $courses_time_active = DB::table('courses')
            ->select([
                'id as course_id',
                DB::raw("($currentYear - YEAR(created_at)) * 12 + ($currentMonth - MONTH(created_at)) AS time_active")
            ])
            ->groupBy('course_id');

        $query = DB::table('courses as c')
            ->leftJoinSub(
                $number_of_purchases,
                'number_of_purchases',
                'number_of_purchases.course_id',
                '=',
                'c.id'
            )->leftJoinSub(
                $number_of_ratings,
                'number_of_ratings',
                'number_of_ratings.course_id',
                '=',
                'c.id'
            )->leftJoinSub(
                $avg_of_ratings,
                'avg_of_ratings',
                'avg_of_ratings.course_id',
                '=',
                'c.id'
            )->leftJoinSub(
                $number_of_completed,
                'number_of_completed',
                'number_of_completed.course_id',
                '=',
                'c.id'
            )->leftJoinSub(
                $courses_time_active,
                'courses_time_active',
                'courses_time_active.course_id',
                '=',
                'c.id'
            );

        $query->select([
            'c.id',
            'c.name',
            DB::raw("
            (
                COALESCE(count_register, 0) +
                (COALESCE(count_rating, 0) * COALESCE(avg_star, 0)) +
                c.views +
                (CASE WHEN COALESCE(count_register, 0) = 0 THEN 0 ELSE (COALESCE(count_completed, 0) / NULLIF(count_register, 0)) * 100 END) -
                COALESCE(time_active, 0)
            ) as popularity
            ")
        ]);

        $data = $query
            ->orderByDesc('popularity')
            ->limit(10)
            ->get();

        return $data;
    }
}
