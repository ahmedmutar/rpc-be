<?php

namespace App\Http\Controllers;

use App\Models\bookings;
use Illuminate\Http\Request;
use DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function overview(Request $request)
    {
        if (!checkAccessIndex('dashboard-menu', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $now = Carbon::now();

        $startOfCurrentMonth = $now->copy()->startOfMonth();
        $endOfCurrentMonth = $now->copy();

        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonthCompare = $now->copy()->subMonth();

        $chartsBookingCategory = $this->chartsBookingCategory();

        $reportingGroup = $this->reportingGroup();
        $reportingGroup = collect($reportingGroup);

        $bookings = $this->bookings($startOfCurrentMonth, $endOfCurrentMonth, $startOfLastMonth, $endOfLastMonthCompare);

        $newCustomer = $this->newCustomer($startOfCurrentMonth, $endOfCurrentMonth, $startOfLastMonth, $endOfLastMonthCompare);

        $rebookRate = $this->calculateRebookRateTrend($startOfCurrentMonth, $endOfCurrentMonth, $startOfLastMonth, $endOfLastMonthCompare);

        $data = [
            'chartsBookingCategory' => [
                'labels' => $chartsBookingCategory->pluck('serviceType')->toArray(),
                'series' => $chartsBookingCategory->pluck('total')->toArray(),
            ],
            'chartsReportingGroup' => [
                'labels' => $reportingGroup->pluck('group')->toArray(),
                'series' => $reportingGroup->pluck('total')->toArray(),
            ],
            'bookings' => [
                'percentage' => $bookings['percentageBookings'],
                'total' => $bookings['bookings'],
                'isLoss' => $bookings['isLoss']
            ],
            'totalSaleValue' => [
                'percentage' => '27.5',
                'total' => '250',
                'isLoss' => 0
            ],
            'newCustomer' => [
                'percentage' => $newCustomer['percentageNewCustomer'],
                'total' => $newCustomer['newCustomer'],
                'isLoss' => $newCustomer['isLoss']
            ],
            'rebookRate' => [
                'percentage' => $rebookRate['percentage'],
                'total' => $rebookRate['rebookCount'],
                'isLoss' => $rebookRate['isLoss']
            ],
            'customerRetention' => [
                'percentage' => '40',
                'total' => '400',
                'isLoss' => 1
            ],
            'avgSaleValue' => [
                'percentage' => '68',
                'total' => '1,400',
                'isLoss' => 0
            ],
        ];

        return response()->json($data);
    }

    private function calculateRebookRateTrend($startOfCurrentMonth, $endOfCurrentMonth, $startOfLastMonth, $endOfLastMonthCompare)
    {
        // 1. Hitung Rate Bulan Ini
        $currentData = $this->getRebookMetrics($startOfCurrentMonth, $endOfCurrentMonth);
        $currentRate = $currentData['rate']; // misal 22.5%

        // 2. Hitung Rate Bulan Lalu
        $prevData = $this->getRebookMetrics($startOfLastMonth, $endOfLastMonthCompare);
        $prevRate = $prevData['rate']; // misal 18.3%

        // 3. Hitung Persentase Kenaikan Tren (Relative Growth)
        $trendPercentage = 0;
        if ($prevRate > 0) {
            $trendPercentage = (($currentRate - $prevRate) / $prevRate) * 100;
        } elseif ($currentRate > 0) {
            $trendPercentage = 100;
        }

        return [
            'rebookCount' => $currentData['rebooked_count'], // Angka 200 di chart
            'percentage' => round($trendPercentage, 1), // Angka 22.5% di label biru
            'isLoss' => $currentRate >= $prevRate ? 0 : 1
        ];
    }

    private function getRebookMetrics($startDate, $endDate)
    {
        $totalUnique = Bookings::whereBetween('created_at', [$startDate, $endDate])
            ->distinct('customerId')
            ->count('customerId');

        $rebookedCount = Bookings::whereBetween('created_at', [$startDate, $endDate])
            ->select('customerId')
            ->groupBy('customerId')
            ->havingRaw('COUNT(customerId) > 1')
            ->get()
            ->count();

        return [
            'rebooked_count' => $rebookedCount,
            'rate' => $totalUnique > 0 ? ($rebookedCount / $totalUnique) * 100 : 0
        ];
    }

    private function newCustomer($startOfCurrentMonth, $endOfCurrentMonth, $startOfLastMonth, $endOfLastMonthCompare)
    {
        // 1. Ambil data jumlah customer periode sekarang
        $newCustomer = DB::table('customer')
            ->whereBetween('created_at', [
                $startOfCurrentMonth,
                $endOfCurrentMonth
            ])
            ->count();

        // 2. Ambil data jumlah customer periode sebelumnya
        $prevNewCustomer = DB::table('customer')
            ->whereBetween('created_at', [
                $startOfLastMonth,
                $endOfLastMonthCompare
            ])
            ->count();

        // 3. Hitung Persentase Tren
        $percentageNewCustomer = 0;
        if ($prevNewCustomer > 0) {
            $percentageNewCustomer = (($newCustomer - $prevNewCustomer) / $prevNewCustomer) * 100;
        } elseif ($newCustomer > 0) {
            $percentageNewCustomer = 100;
        }

        // Mengembalikan 3 value dalam bentuk array asosiatif
        return [
            'newCustomer' => $newCustomer,
            'percentageNewCustomer' => round($percentageNewCustomer, 2), // Dibulatkan agar rapi di UI
            'isLoss' => $newCustomer >= $prevNewCustomer ? 0 : 1
        ];
    }

    private function reportingGroup()
    {
        return DB::query()
            ->from(function ($query) {
                $query->select('customerId')->from('transactionPetClinics')
                    ->unionAll(function ($q) {
                        $q->select('customerId')->from('transaction_pet_hotels');
                    })
                    ->unionAll(function ($q) {
                        $q->select('customerId')->from('transaction_breedings');
                    })
                    ->unionAll(function ($q) {
                        $q->select('customerId')->from('transaction_pet_salons');
                    });
            }, 'transactions')
            ->join('customer as b', 'transactions.customerId', 'b.id')
            ->leftJoin('customerGroups as c', 'b.customerGroupId', 'c.id')
            ->select(
                DB::raw("COALESCE(c.customerGroup, 'Uncategorized') as `group`"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('group')
            ->get();
    }

    private function chartsBookingCategory()
    {
        return DB::table('bookings')
            ->select(
                'serviceType',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('serviceType')
            ->get();
    }

    private function bookings($startOfCurrentMonth, $endOfCurrentMonth, $startOfLastMonth, $endOfLastMonthCompare)
    {
        // 1. Ambil data jumlah booking periode sekarang
        $bookings = Bookings::whereBetween('created_at', [
            $startOfCurrentMonth,
            $endOfCurrentMonth
        ])->count();

        // 2. Ambil data jumlah booking periode sebelumnya
        $prevBookings = Bookings::whereBetween('created_at', [
            $startOfLastMonth,
            $endOfLastMonthCompare
        ])->count();

        // 3. Hitung Persentase Tren
        $percentageBookings = 0;
        if ($prevBookings > 0) {
            $percentageBookings = (($bookings - $prevBookings) / $prevBookings) * 100;
        } elseif ($bookings > 0) {
            $percentageBookings = 100;
        }

        // Mengembalikan 3 value dalam bentuk array asosiatif
        return [
            'bookings' => $bookings,
            'percentageBookings' => round($percentageBookings, 2), // Dibulatkan agar rapi di UI
            'isLoss' => $bookings >= $prevBookings ? 1 : 0
        ];
    }

    public function upcomingBookInpatien(Request $request)
    {
        if (!checkAccessIndex('dashboard-menu', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $data = [
            'totalPagination' => 1,
            'data' => [
                [
                    'id' => 9,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '05/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 8,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 7,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 6,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 5,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
            ],
        ];

        return response()->json($data);
    }

    public function upcomingBookOutpatien(Request $request)
    {
        if (!checkAccessIndex('dashboard-menu', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $data = [
            'totalPagination' => 1,
            'data' => [
                [
                    'id' => 9,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '05/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 8,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 7,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 6,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
                [
                    'id' => 5,
                    'startTime' => '03/12/2024 9:00 AM',
                    'endTime' => '06/12/2024 9:00 AM',
                    'location' => 'RPC Hankam',
                    'customer' => 'Rusli',
                    'serviceName' => 'Operasi',
                    'staff' => 'Yusuf',
                    'status' => 'On Progress',
                    'bookingNote' => '',
                ],
            ],
        ];

        return response()->json($data);
    }

    public function recentActivity(Request $request)
    {
        if (!checkAccessIndex('dashboard-menu', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $data = DB::table('recentActivities as ra')
            ->join('users as u', 'ra.userId', 'u.id')
            ->select(
                'ra.id as id',
                'ra.module as module',
                'ra.event as event',
                'ra.details as detail',
                'u.firstName as staff',
                DB::raw("DATE_FORMAT(ra.created_at, '%d %b, %Y %l:%i %p') as date")
            );

        if ($request->locationId) {

            $data = $data->whereIn('loc.id', $request->locationId);
        }

        if ($request->search) {
            $res = $this->Search($request);
            if ($res) {
                $data = $data->where($res[0], 'like', '%' . $request->search . '%');

                for ($i = 1; $i < count($res); $i++) {

                    $data = $data->orWhere($res[$i], 'like', '%' . $request->search . '%');
                }
            } else {
                $data = [];
                return response()->json([
                    'totalPagination' => 0,
                    'data' => $data
                ], 200);
            }
        }


        if ($request->orderValue) {
            $data = $data->orderBy($request->orderColumn, $request->orderValue);
        }

        $data = $data->orderBy('ra.updated_at', 'desc');

        $offset = ($page - 1) * $itemPerPage;

        $count_data = $data->count();
        $count_result = $count_data - $offset;

        if ($count_result < 0) {
            $data = $data->offset(0)->limit($itemPerPage)->get();
        } else {
            $data = $data->offset($offset)->limit($itemPerPage)->get();
        }

        $totalPaging = $count_data / $itemPerPage;

        return response()->json([
            'totalPagination' => ceil($totalPaging),
            'data' => $data
        ], 200);
    }
}
