<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceDashboardController extends Controller
{
    public function index()
    {
        $last10Days = collect(range(0, 9))->map(function ($daysAgo) {
            return Carbon::today()->subDays($daysAgo)->format('j');
        });

        $location = DB::table('location as l')
            ->select('l.id', 'l.locationName')
            ->get();

        $customerGroup = DB::table('customerGroups as c')
            ->select('c.id', 'c.customerGroup')
            ->get();

        $data = [
            'charts' => [
                'series' => [
                    [
                        'name' => 'Previous',
                        'data' => [10, 10, 10, 10, 30, 20, 15, 20, 18, 29],
                    ],
                    [
                        'name' => 'Current',
                        'data' => [20, 40, 20, 10, 80, 30, 15, 20, 18, 29],
                    ],
                ],
                'categories' => $last10Days,
            ],
            //upper chart
            'numberSales' => [
                'amount' => '75.35',
                'isLoss' => 1
            ],
            'totalSalesValue' => [
                'amount' => '75.35',
                'isLoss' => 1
            ],
            'averageSalesValue' => [
                'amount' => '75.35',
                'isLoss' => 1
            ],

            //chart pie
            'salesByItemType' => [
                'labels' => ['Service', 'Product', 'Bundle', 'Based Sales'],
                'series' => [150, 40, 60, 70],
            ],

            'salesByLocation' => [
                //'labels' => $location->pluck('locationName'),
                'labels' => ['Jakarta', 'Bandung', 'Surabaya', 'Yogyakarta'],
                'series' => [150, 40, 60, 70],
            ],

            'salesByReportingGroup' => [
                //'labels' => $customerGroup->pluck('customerGroup'),
                'labels' => ['VIP', 'PMS', 'Komunitas'],
                'series' => [150, 40, 60],
            ]
        ];

        return response()->json($data);
    }
}
