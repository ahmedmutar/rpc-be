<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\DB;

class ReportCustomerController extends Controller
{
    public function indexGrowth(Request $request)
    {
        // 1. Setup Tanggal & Threshold
        $dateFrom = $request->input('dateFrom') ?: Carbon::today()->subDays(9)->format('Y-m-d');
        $dateTo = $request->input('dateTo') ?: Carbon::today()->format('Y-m-d');

        $start = Carbon::parse($dateFrom)->startOfDay();
        $end = Carbon::parse($dateTo)->endOfDay();

        $diffInDays = $start->diffInDays($end);

        $daysLabel = collect(range(0, $diffInDays))->map(function ($offset) use ($start) {
            return $start->copy()->addDays($offset)->format('j M');
        });

        $daysIso = collect(range(0, $diffInDays))->map(function ($offset) use ($start) {
            return $start->copy()->addDays($offset)->format('Y-m-d');
        });

        // Threshold 14 hari sesuai permintaan Anda
        $fourteenDaysAgo = Carbon::now()->subDays(14);

        // 2. Query Locations
        $locations = DB::table('location')
            ->where('isDeleted', 0)
            ->when($request->input('locationId'), function ($query, $ids) {
                return $query->whereIn('id', $ids);
            })
            ->get(['id', 'locationName']);

        $locationIds = $locations->pluck('id')->toArray();

        // 3. Query Customers
        $customers = DB::table('customer as c')
            ->select(
                'c.id',
                'c.locationId',
                'c.isDeleted',
                'l.locationName',
                'c.created_at',
                'c.lastTransaction', // Kolom transaksi terakhir
                DB::raw("DATE(c.created_at) as date"),
                DB::raw("DATE(c.lastTransaction) as lastTransactionDate")
            )
            ->join('location as l', 'l.id', '=', 'c.locationId')
            ->whereIn('l.id', $locationIds)
            // Filter Group jika ada
            ->when($request->input('customerGroup'), function ($query, $group) {
                return $query->whereIn('c.customerGroupId', $group);
            })
            ->get();

        // 4. Mapping Data Table
        $tableData = $locations->map(function ($loc) use ($customers, $fourteenDaysAgo) {

            $locCustomers = $customers->where('locationId', $loc->id);

            return [
                'location' => $loc->locationName,

                // NEW: Transaksi terakhir masih dalam rentang 14 hari terakhir
                'new' => $locCustomers->where('isDeleted', 0)
                    ->filter(function ($c) use ($fourteenDaysAgo) {
                        return !is_null($c->lastTransaction) && Carbon::parse($c->lastTransaction)->gte($fourteenDaysAgo);
                    })->count(),

                // INACTIVE: Transaksi terakhir sudah lebih dari 14 hari yang lalu (atau tidak pernah transaksi)
                'inactive' => $locCustomers->where('isDeleted', 0)
                    ->filter(function ($c) use ($fourteenDaysAgo) {
                        return is_null($c->lastTransaction) || Carbon::parse($c->lastTransaction)->lt($fourteenDaysAgo);
                    })->count(),

                // DELETED: Tetap berdasarkan flag isDeleted
                'deleted' => $locCustomers->where('isDeleted', 1)->count(),
            ];
        });

        // 5. Accumulate Totals
        $totalData = [
            'new'      => $tableData->sum('new'),
            'inactive' => $tableData->sum('inactive'),
            'deleted'  => $tableData->sum('deleted'),
        ];


        $customerData = [];
        $selectedLocationIds = $request->input('locationId');
        $locationNames = DB::table('location')
            ->where('isDeleted', 0)
            ->when($selectedLocationIds, function ($query, $selectedLocationIds) {
                return $query->whereIn('id', $selectedLocationIds);
            })
            ->pluck('locationName')
            ->toArray();
        foreach ($locationNames as $locationName) {
            // Filter customer berdasarkan lokasi ini dari hasil get() di atas
            $customerInLocation = $customers->where('locationName', $locationName);

            $customerData[$locationName] = $daysIso->map(function ($date) use ($customerInLocation) {
                // Sekarang where('date', $date) akan berfungsi karena kolom 'date' ada di select
                return $customerInLocation->where('date', $date)->count();
            })->toArray();
        }

        $series = collect($locationNames)->map(function ($location) use ($customerData) {
            return [
                'name' => $location,
                'data' => $customerData[$location],
            ];
        })->values();

        // 6. Response Construction
        $data = [
            'charts' => [
                'series' => $series,
                'categories' => $daysLabel,
            ],
            'table' => [
                'data' => $tableData->values(),
                'totalData' => $totalData,
            ]
        ];

        return response()->json($data);
    }

    public function exportGrowth(Request $request)
    {
        $selectedLocationIds = $request->input('locationId');
        $selectedCustomerGroup = $request->input('customerGroup');

        // Threshold 14 hari sesuai permintaan Anda
        $fourteenDaysAgo = Carbon::now()->subDays(14);

        // 2. Ambil daftar lokasi aktif
        $locations = DB::table('location')
            ->where('isDeleted', 0)
            ->when($selectedLocationIds, function ($query, $ids) {
                return $query->whereIn('id', $ids);
            })
            ->get(['id', 'locationName']);

        $locationIds = $locations->pluck('id')->toArray();

        // 3. Ambil data customer (ambil yang aktif maupun yang deleted untuk hitungan kolom deleted)
        $customers = DB::table('customer as c')
            ->select('c.id', 'c.locationId', 'c.isDeleted', 'c.lastTransaction')
            ->whereIn('c.locationId', $locationIds)
            ->when($selectedCustomerGroup, function ($query, $group) {
                return $query->whereIn('c.customerGroupId', $group);
            })
            ->get();

        // 4. Proses Mapping Data per Lokasi
        $data = $locations->map(function ($loc) use ($customers, $fourteenDaysAgo) {
            // Ambil semua customer di lokasi ini (termasuk yang deleted)
            $locCustomers = $customers->where('locationId', $loc->id);

            // Customer yang belum dihapus (untuk kategori New dan Inactive)
            $activeLocCustomers = $locCustomers->where('isDeleted', 0);

            return [
                'location' => $loc->locationName,

                // NEW: Transaksi terakhir <= 14 hari yang lalu
                'new' => $activeLocCustomers->filter(function ($c) use ($fourteenDaysAgo) {
                    return !is_null($c->lastTransaction) && Carbon::parse($c->lastTransaction)->gte($fourteenDaysAgo);
                })->count(),

                // INACTIVE: Transaksi terakhir > 14 hari yang lalu ATAU tidak pernah transaksi
                'inactive' => $activeLocCustomers->filter(function ($c) use ($fourteenDaysAgo) {
                    return is_null($c->lastTransaction) || Carbon::parse($c->lastTransaction)->lt($fourteenDaysAgo);
                })->count(),

                // DELETED: Customer dengan flag isDeleted = 1
                'deleted' => $locCustomers->where('isDeleted', 1)->count(),
            ];
        })->values()->toArray();

        // 5. Hitung Akumulasi Total
        $totalData = [
            'new'      => collect($data)->sum('new'),
            'inactive' => collect($data)->sum('inactive'),
            'deleted'  => collect($data)->sum('deleted'),
        ];

        $spreadsheet = IOFactory::load(public_path() . '/template/report/' . 'Template_Report_Growth.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $item['location']);
            $sheet->setCellValue("B{$row}", $item['new']);
            $sheet->setCellValue("C{$row}", $item['inactive']);
            $sheet->setCellValue("D{$row}", $item['deleted']);

            $row++;
        }

        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $sheet->getStyle("C{$row}")->getFont()->setBold(true);
        $sheet->getStyle("D{$row}")->getFont()->setBold(true);

        $sheet->setCellValue("A{$row}", "Total");
        $sheet->setCellValue("B{$row}", $totalData['new']);
        $sheet->setCellValue("C{$row}", $totalData['inactive']);
        $sheet->setCellValue("D{$row}", $totalData['deleted']);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        $timestamp = now()->format('Ymd');
        $newFilePath = public_path() . '/template_download/' . 'Export Report Customer Growth ' . $timestamp . '.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Report Customer Growth ' . $timestamp . '.xlsx"',
        ]);
    }

    public function indexGrowthByGroup(Request $request)
    {
        $selectedLocationIds = $request->input('locationId');
        $selectedCustomerGroup = $request->input('customerGroup');
        $fourteenDaysAgo = Carbon::now()->subDays(14);

        // 2. Ambil Daftar Group yang Aktif
        $groups = DB::table('customerGroups')
            // ->where('isDeleted', 0)
            ->when($selectedCustomerGroup, function ($query, $ids) {
                return $query->whereIn('id', $ids);
            })
            ->get(['id', 'customerGroup']);

        $groupIds = $groups->pluck('id')->toArray();

        // 3. Query Customer (Satu kali tarik data untuk performa)
        $customers = DB::table('customer as c')
            ->select('c.id', 'c.customerGroupId', 'c.isDeleted', 'c.lastTransaction')
            ->when($selectedLocationIds, function ($query, $locIds) {
                return $query->whereIn('c.locationId', $locIds);
            })
            ->whereIn('c.customerGroupId', $groupIds)
            ->get();

        // 4. Olah Data untuk Table & Chart
        $tableData = $groups->map(function ($group) use ($customers, $fourteenDaysAgo) {
            // Filter customer berdasarkan group ini
            $groupCustomers = $customers->where('customerGroupId', $group->id);

            // Customer Aktif (tidak di-delete) untuk hitungan Total, New, & Inactive
            $activeCustomers = $groupCustomers->where('isDeleted', 0);

            $newCount = $activeCustomers->filter(function ($c) use ($fourteenDaysAgo) {
                return !is_null($c->lastTransaction) && Carbon::parse($c->lastTransaction)->gte($fourteenDaysAgo);
            })->count();

            $inactiveCount = $activeCustomers->filter(function ($c) use ($fourteenDaysAgo) {
                return is_null($c->lastTransaction) || Carbon::parse($c->lastTransaction)->lt($fourteenDaysAgo);
            })->count();

            return [
                'reportingGroup' => $group->customerGroup,
                'total'          => $activeCustomers->count(),
                'new'            => $newCount,
                'inactive'       => $inactiveCount,
                'deleted'        => $groupCustomers->where('isDeleted', 1)->count(),
            ];
        });

        // 5. Susun Format Response Akhir
        $result = [
            'charts' => [
                'labels' => $tableData->pluck('reportingGroup')->toArray(),
                'series' => $tableData->pluck('total')->toArray(),
            ],
            'table' => [
                'data' => $tableData->values()->toArray(),
                'totalData' => [
                    'total'    => $tableData->sum('total'),
                    'new'      => $tableData->sum('new'),
                    'inactive' => $tableData->sum('inactive'),
                    'deleted'  => $tableData->sum('deleted'),
                ],
            ]
        ];

        return response()->json($result);
    }

    public function exportGrowthByGroup(Request $request)
    {

        $selectedLocationIds = $request->input('locationId');
        $selectedCustomerGroup = $request->input('customerGroup');
        $fourteenDaysAgo = Carbon::now()->subDays(14);

        // 2. Ambil Daftar Group
        $groups = DB::table('customerGroups')
            ->where('isDeleted', 0)
            ->when($selectedCustomerGroup, function ($query, $ids) {
                return $query->whereIn('id', $ids);
            })
            ->get(['id', 'customerGroup']);

        $groupIds = $groups->pluck('id')->toArray();

        // 3. Query Customer (Satu kali tarik data untuk semua grup)
        $customers = DB::table('customer as c')
            ->select('c.id', 'c.customerGroupId', 'c.isDeleted', 'c.lastTransaction')
            ->when($selectedLocationIds, function ($query, $locIds) {
                return $query->whereIn('c.locationId', $locIds);
            })
            ->whereIn('c.customerGroupId', $groupIds)
            ->get();

        // 4. Mapping Data Utama ($data)
        $data = $groups->map(function ($group) use ($customers, $fourteenDaysAgo) {
            // Filter customer milik grup ini
            $groupCustomers = $customers->where('customerGroupId', $group->id);

            // Customer Aktif (isDeleted = 0)
            $activeCustomers = $groupCustomers->where('isDeleted', 0);

            return [
                'reportingGroup' => $group->customerGroup,
                'total'          => $activeCustomers->count(),
                'new'            => $activeCustomers->filter(function ($c) use ($fourteenDaysAgo) {
                    return !is_null($c->lastTransaction) && Carbon::parse($c->lastTransaction)->gte($fourteenDaysAgo);
                })->count(),
                'inactive'       => $activeCustomers->filter(function ($c) use ($fourteenDaysAgo) {
                    return is_null($c->lastTransaction) || Carbon::parse($c->lastTransaction)->lt($fourteenDaysAgo);
                })->count(),
                'deleted'        => $groupCustomers->where('isDeleted', 1)->count(),
            ];
        })->values()->toArray();

        // 5. Hitung Total Akumulasi ($totalData)
        $totalData = [
            'total'    => collect($data)->sum('total'),
            'new'      => collect($data)->sum('new'),
            'inactive' => collect($data)->sum('inactive'),
            'deleted'  => collect($data)->sum('deleted'),
        ];

        $spreadsheet = IOFactory::load(public_path() . '/template/report/' . 'Template_Report_Growth_by_group.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $item['reportingGroup']);
            $sheet->setCellValue("B{$row}", $item['total']);
            $sheet->setCellValue("C{$row}", $item['new']);
            $sheet->setCellValue("D{$row}", $item['inactive']);
            $sheet->setCellValue("E{$row}", $item['deleted']);

            $row++;
        }

        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $sheet->getStyle("C{$row}")->getFont()->setBold(true);
        $sheet->getStyle("D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("E{$row}")->getFont()->setBold(true);

        $sheet->setCellValue("A{$row}", "Total");
        $sheet->setCellValue("B{$row}", $totalData['total']);
        $sheet->setCellValue("C{$row}", $totalData['new']);
        $sheet->setCellValue("D{$row}", $totalData['inactive']);
        $sheet->setCellValue("E{$row}", $totalData['deleted']);

        $timestamp = now()->format('Ymd');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Report Customer Growth by Group ' . $timestamp . '.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Report Customer Growth by Group.xlsx"',
        ]);
    }

    public function indexTotal(Request $request)
    {
        $dateFrom = $request->input('dateFrom') ?: Carbon::today()->subDays(9)->format('Y-m-d');
        $dateTo = $request->input('dateTo') ?: Carbon::today()->format('Y-m-d');

        // $startDate = Carbon::today()->subDays(9);
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        $diffInDays = $start->diffInDays($end);

        $daysLabel = collect(range(0, $diffInDays))->map(function ($offset) use ($start) {
            return $start->copy()->addDays($offset)->format('j M');
        });

        $daysIso = collect(range(0, $diffInDays))->map(function ($offset) use ($start) {
            return $start->copy()->addDays($offset)->format('Y-m-d');
        });

        $selectedLocationIds = $request->input('locationId');
        $selectedCustomerGroup = $request->input('customerGroup');

        $locationNames = DB::table('location')
            ->where('isDeleted', 0)
            ->when($selectedLocationIds, function ($query, $selectedLocationIds) {
                return $query->whereIn('id', $selectedLocationIds);
            })
            ->pluck('locationName')
            ->toArray();

        $customers = DB::table('customer as c')
            ->select(
                'c.id',
                'l.locationName', // <-- WAJIB DITAMBAHKAN agar bisa di-grouping di bawah
                DB::raw("DATE(c.created_at) as date"),
                DB::raw("CONCAT_WS(' ', NULLIF(c.firstName, ''), NULLIF(c.middleName, ''), NULLIF(c.lastName, '')) as customerName")
            )
            ->join('location as l', 'l.id', '=', 'c.locationId')
            ->where('c.isDeleted', 0)
            ->when($selectedCustomerGroup, function ($query, $selectedCustomerGroup) {
                return $query->whereIn('c.customerGroupId', $selectedCustomerGroup);
            })
            // Sebaiknya aktifkan whereIn agar query lebih spesifik dan cepat
            ->whereIn('l.locationName', $locationNames)
            // Pastikan rentang waktu mencakup hingga akhir hari terakhir
            ->whereBetween('c.created_at', [$daysIso->first() . ' 00:00:00', $daysIso->last() . ' 23:59:59'])
            ->get();


        $customerData = [];
        foreach ($locationNames as $locationName) {
            // Filter customer berdasarkan lokasi ini dari hasil get() di atas
            $customerInLocation = $customers->where('locationName', $locationName);

            $customerData[$locationName] = $daysIso->map(function ($date) use ($customerInLocation) {
                // Sekarang where('date', $date) akan berfungsi karena kolom 'date' ada di select
                return $customerInLocation->where('date', $date)->count();
            })->toArray();
        }

        $series = collect($locationNames)->map(function ($location) use ($customerData) {
            return [
                'name' => $location,
                'data' => $customerData[$location],
            ];
        })->values();

        $tableData = collect($locationNames)->map(function ($locationName) use ($customers) {
            return [
                'location' => $locationName,
                // Hitung semua customer di lokasi ini tanpa filter tanggal lagi (karena query $customers sudah terfilter tanggal)
                'total' => $customers->where('locationName', $locationName)->count(),
            ];
        })->values();

        // 2. Hitung total dari seluruh lokasi yang ada di tabel
        $grandTotal = $tableData->sum('total');

        $data = [
            'charts' => [
                'series' => $series,
                'categories' => $daysLabel,
            ],
            'table' => [
                'data' => $tableData,
                'totalData' => [
                    'total' => $grandTotal,
                ],
            ]
        ];

        return response()->json($data);
    }

    public function exportTotal(Request $request)
    {
        $dateFrom = $request->input('dateFrom') ?: Carbon::today()->subDays(9)->format('Y-m-d');
        $dateTo = $request->input('dateTo') ?: Carbon::today()->format('Y-m-d');

        // $startDate = Carbon::today()->subDays(9);
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        $diffInDays = $start->diffInDays($end);

        $daysLabel = collect(range(0, $diffInDays))->map(function ($offset) use ($start) {
            return $start->copy()->addDays($offset)->format('j M');
        });

        $daysIso = collect(range(0, $diffInDays))->map(function ($offset) use ($start) {
            return $start->copy()->addDays($offset)->format('Y-m-d');
        });

        $selectedLocationIds = $request->input('locationId');
        $selectedCustomerGroup = $request->input('customerGroup');

        $locationNames = DB::table('location')
            ->where('isDeleted', 0)
            ->when($selectedLocationIds, function ($query, $selectedLocationIds) {
                return $query->whereIn('id', $selectedLocationIds);
            })
            ->pluck('locationName')
            ->toArray();

        $customers = DB::table('customer as c')
            ->select(
                'c.id',
                'l.locationName', // <-- WAJIB DITAMBAHKAN agar bisa di-grouping di bawah
                DB::raw("DATE(c.created_at) as date"),
                DB::raw("CONCAT_WS(' ', NULLIF(c.firstName, ''), NULLIF(c.middleName, ''), NULLIF(c.lastName, '')) as customerName")
            )
            ->join('location as l', 'l.id', '=', 'c.locationId')
            ->where('c.isDeleted', 0)
            ->when($selectedCustomerGroup, function ($query, $selectedCustomerGroup) {
                return $query->whereIn('c.customerGroupId', $selectedCustomerGroup);
            })
            // Sebaiknya aktifkan whereIn agar query lebih spesifik dan cepat
            ->whereIn('l.locationName', $locationNames)
            // Pastikan rentang waktu mencakup hingga akhir hari terakhir
            ->whereBetween('c.created_at', [$daysIso->first() . ' 00:00:00', $daysIso->last() . ' 23:59:59'])
            ->get();

        $tableData = collect($locationNames)->map(function ($locationName) use ($customers) {
            return [
                'location' => $locationName,
                // Hitung semua customer di lokasi ini tanpa filter tanggal lagi (karena query $customers sudah terfilter tanggal)
                'total' => $customers->where('locationName', $locationName)->count(),
            ];
        })->values();

        $totalData = [
            'total' => $tableData->sum('total'),
        ];

        $spreadsheet = IOFactory::load(public_path() . '/template/report/' . 'Template_Report_Customer_Total.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($tableData as $item) {

            $sheet->setCellValue("A{$row}", $item['location']);
            $sheet->setCellValue("B{$row}", $item['total']);

            $row++;
        }

        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);

        $sheet->setCellValue("A{$row}", "Total");
        $sheet->setCellValue("B{$row}", $totalData['total']);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        $timestamp = now()->format('Ymd');

        $newFilePath = public_path() . '/template_download/' . 'Export Report Customer Total ' . $timestamp . '.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Report Customer Total ' . $timestamp . '.xlsx"',
        ]);
    }

    public function indexLeaving(Request $request)
    {

        $data = [
            'totalPagination' => 1,
            'data' => [
                [
                    'name' => 'Agus',
                    'location' => 'RPC Condet',
                    'date' => '12 May 2024',
                    'customerGroup' => 'VIP',
                    'customerFor' => '10 Days',
                    'total' => 50000,
                ],
                [
                    'name' => 'Budi',
                    'location' => 'RPC Condet',
                    'date' => '12 May 2024',
                    'customerGroup' => 'VIP',
                    'customerFor' => '10 Days',
                    'total' => 50000,
                ],
                [
                    'name' => 'Tono',
                    'location' => 'RPC Condet',
                    'date' => '12 May 2024',
                    'customerGroup' => 'VIP',
                    'customerFor' => '10 Days',
                    'total' => 50000,
                ],
                [
                    'name' => 'Susi',
                    'location' => 'RPC Condet',
                    'date' => '12 May 2024',
                    'customerGroup' => 'VIP',
                    'customerFor' => '10 Days',
                    'total' => 50000,
                ],
                [
                    'name' => 'Chandra',
                    'location' => 'RPC Condet',
                    'date' => '12 May 2024',
                    'customerGroup' => 'VIP',
                    'customerFor' => '10 Days',
                    'total' => 50000,
                ],
            ],
        ];

        return response()->json($data);
    }

    public function exportLeaving(Request $request)
    {
        $data = [
            [
                'name' => 'Agus',
                'location' => 'RPC Condet',
                'date' => '12 May 2024',
                'customerGroup' => 'VIP',
                'customerFor' => '10 Days',
                'total' => 50000,
            ],
            [
                'name' => 'Budi',
                'location' => 'RPC Condet',
                'date' => '12 May 2024',
                'customerGroup' => 'VIP',
                'customerFor' => '10 Days',
                'total' => 50000,
            ],
            [
                'name' => 'Tono',
                'location' => 'RPC Condet',
                'date' => '12 May 2024',
                'customerGroup' => 'VIP',
                'customerFor' => '10 Days',
                'total' => 50000,
            ],
            [
                'name' => 'Susi',
                'location' => 'RPC Condet',
                'date' => '12 May 2024',
                'customerGroup' => 'VIP',
                'customerFor' => '10 Days',
                'total' => 50000,
            ],
            [
                'name' => 'Chandra',
                'location' => 'RPC Condet',
                'date' => '12 May 2024',
                'customerGroup' => 'VIP',
                'customerFor' => '10 Days',
                'total' => 50000,
            ],
        ];

        $spreadsheet = IOFactory::load(public_path() . '/template/report/' . 'Template_Report_Customer_Leaving.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $item['name']);
            $sheet->setCellValue("B{$row}", $item['location']);
            $sheet->setCellValue("C{$row}", $item['date']);
            $sheet->setCellValue("D{$row}", $item['customerGroup']);
            $sheet->setCellValue("E{$row}", $item['customerFor']);
            $sheet->setCellValue("F{$row}", $item['total']);

            $row++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Report Customer Leaving.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Report Customer Leaving.xlsx"',
        ]);
    }

    public function indexList(Request $request)
    {

        $data = [
            'totalPagination' => 1,
            'data' => [
                [
                    'memberNo' => 'RPC 123',
                    'name' => 'Agus',
                    'location' => 'RPC Condet',
                    'status' => 'Active',
                    'gender' => 'Male',
                    'telephone' => '081245654859',
                    'email' => 'agus@gmail.com',
                ],
                [
                    'memberNo' => 'RPC 124',
                    'name' => 'Budi',
                    'location' => 'RPC Condet',
                    'status' => 'Active',
                    'gender' => 'Male',
                    'telephone' => '081245654859',
                    'email' => 'budi@gmail.com',
                ],
                [
                    'memberNo' => 'RPC 125',
                    'name' => 'Susi',
                    'location' => 'RPC Condet',
                    'status' => 'Active',
                    'gender' => 'Female',
                    'telephone' => '081245654859',
                    'email' => 'susi@gmail.com',
                ],
                [
                    'memberNo' => 'RPC 126',
                    'name' => 'Eka',
                    'location' => 'RPC Condet',
                    'status' => 'Active',
                    'gender' => 'Female',
                    'telephone' => '081245654859',
                    'email' => 'eka@gmail.com',
                ],
                [
                    'memberNo' => 'RPC 127',
                    'name' => 'Andi',
                    'location' => 'RPC Condet',
                    'status' => 'Active',
                    'gender' => 'Male',
                    'telephone' => '081245654859',
                    'email' => 'andi@gmail.com',
                ],
            ],
        ];

        return response()->json($data);
    }

    public function exportList(Request $request)
    {

        $data = [
            [
                'memberNo' => 'RPC 123',
                'name' => 'Agus',
                'location' => 'RPC Condet',
                'status' => 'Active',
                'gender' => 'Male',
                'telephone' => '081245654859',
                'email' => 'agus@gmail.com',
            ],
            [
                'memberNo' => 'RPC 124',
                'name' => 'Budi',
                'location' => 'RPC Condet',
                'status' => 'Active',
                'gender' => 'Male',
                'telephone' => '081245654859',
                'email' => 'budi@gmail.com',
            ],
            [
                'memberNo' => 'RPC 125',
                'name' => 'Susi',
                'location' => 'RPC Condet',
                'status' => 'Active',
                'gender' => 'Female',
                'telephone' => '081245654859',
                'email' => 'susi@gmail.com',
            ],
            [
                'memberNo' => 'RPC 126',
                'name' => 'Eka',
                'location' => 'RPC Condet',
                'status' => 'Active',
                'gender' => 'Female',
                'telephone' => '081245654859',
                'email' => 'eka@gmail.com',
            ],
            [
                'memberNo' => 'RPC 127',
                'name' => 'Andi',
                'location' => 'RPC Condet',
                'status' => 'Active',
                'gender' => 'Male',
                'telephone' => '081245654859',
                'email' => 'andi@gmail.com',
            ],
        ];

        $spreadsheet = IOFactory::load(public_path() . '/template/report/' . 'Template_Report_Customer_List.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $item['memberNo']);
            $sheet->setCellValue("B{$row}", $item['name']);
            $sheet->setCellValue("C{$row}", $item['location']);
            $sheet->setCellValue("D{$row}", $item['status']);
            $sheet->setCellValue("E{$row}", $item['gender']);
            $sheet->setCellValue("F{$row}", $item['telephone']);
            $sheet->setCellValue("G{$row}", $item['email']);

            $row++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Report Customer List.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Report Customer List.xlsx"',
        ]);
    }

    public function indexRefSpend(Request $request)
    {

        $data = [
            'totalPagination' => 1,
            'data' => [
                [
                    'referenceBy' => 'Budi',
                    'reference' => 'Langsung Datang',
                    'customer' => 'Yudi',
                    'totalSpend' => 250000,
                ],
                [
                    'referenceBy' => 'Udin',
                    'reference' => 'Langsung Datang',
                    'customer' => 'Wira',
                    'totalSpend' => 750000,
                ],
                [
                    'referenceBy' => 'Hafis',
                    'reference' => 'Langsung Datang',
                    'customer' => 'Yudi',
                    'totalSpend' => 600000,
                ],
                [
                    'referenceBy' => 'Chandra',
                    'reference' => 'Langsung Datang',
                    'customer' => 'Yudi',
                    'totalSpend' => 20000,
                ],
                [
                    'referenceBy' => 'Rudi',
                    'reference' => 'Langsung Datang',
                    'customer' => 'Yudi',
                    'totalSpend' => 450000,
                ],
            ],
        ];

        return response()->json($data);
    }

    public function exportRefSpend(Request $request)
    {

        $data = [
            [
                'referenceBy' => 'Budi',
                'reference' => 'Langsung Datang',
                'customer' => 'Yudi',
                'totalSpend' => 250000,
            ],
            [
                'referenceBy' => 'Udin',
                'reference' => 'Langsung Datang',
                'customer' => 'Wira',
                'totalSpend' => 750000,
            ],
            [
                'referenceBy' => 'Hafis',
                'reference' => 'Langsung Datang',
                'customer' => 'Yudi',
                'totalSpend' => 600000,
            ],
            [
                'referenceBy' => 'Chandra',
                'reference' => 'Langsung Datang',
                'customer' => 'Yudi',
                'totalSpend' => 20000,
            ],
            [
                'referenceBy' => 'Rudi',
                'reference' => 'Langsung Datang',
                'customer' => 'Yudi',
                'totalSpend' => 450000,
            ],
        ];

        $spreadsheet = IOFactory::load(public_path() . '/template/report/' . 'Template_Report_Customer_Ref_Spend.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $item['referenceBy']);
            $sheet->setCellValue("B{$row}", $item['reference']);
            $sheet->setCellValue("C{$row}", $item['customer']);
            $sheet->setCellValue("D{$row}", $item['totalSpend']);

            $row++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Report Customer Referral Spend.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Report Customer Referral Spend.xlsx"',
        ]);
    }

    public function indexSubAccount(Request $request)
    {

        $data = [
            'totalPagination' => 1,
            'data' => [
                [
                    'customerName' => 'Budi',
                    'petName' => 'Ciko',
                    'condition' => 'Good',
                    'type' => 'Kucing',
                    'race' => 'Anggora',
                    'gender' => 'Jantan',
                    'sterile' => 'sudah',
                    'birthDate' => '01/10/2022',
                    'color' => 'white',
                ],
                [
                    'customerName' => 'Chika',
                    'petName' => 'Hiro',
                    'condition' => 'Good',
                    'type' => 'Kucing',
                    'race' => 'Anggora',
                    'gender' => 'Jantan',
                    'sterile' => 'sudah',
                    'birthDate' => '02/05/2022',
                    'color' => 'white',
                ],
                [
                    'customerName' => 'Rudi',
                    'petName' => 'Rocky',
                    'condition' => 'Good',
                    'type' => 'Anjing',
                    'race' => 'Bulldog',
                    'gender' => 'Jantan',
                    'sterile' => 'belum',
                    'birthDate' => '01/10/2020',
                    'color' => 'black',
                ],
                [
                    'customerName' => 'Budi',
                    'petName' => 'Ciko',
                    'condition' => 'Good',
                    'type' => 'Kucing',
                    'race' => 'Anggora',
                    'gender' => 'Jantan',
                    'sterile' => 'sudah',
                    'birthDate' => '01/10/2022',
                    'color' => 'white',
                ],
                [
                    'customerName' => 'Budi',
                    'petName' => 'Ciko',
                    'condition' => 'Good',
                    'type' => 'Kucing',
                    'race' => 'Anggora',
                    'gender' => 'Jantan',
                    'sterile' => 'sudah',
                    'birthDate' => '01/10/2022',
                    'color' => 'white',
                ],
            ],
        ];

        return response()->json($data);
    }

    public function exportSubAccount(Request $request)
    {

        $data = [
            [
                'customerName' => 'Budi',
                'petName' => 'Ciko',
                'condition' => 'Good',
                'type' => 'Kucing',
                'race' => 'Anggora',
                'gender' => 'Jantan',
                'sterile' => 'sudah',
                'birthDate' => '01/10/2022',
                'color' => 'white',
            ],
            [
                'customerName' => 'Chika',
                'petName' => 'Hiro',
                'condition' => 'Good',
                'type' => 'Kucing',
                'race' => 'Anggora',
                'gender' => 'Jantan',
                'sterile' => 'sudah',
                'birthDate' => '02/05/2022',
                'color' => 'white',
            ],
            [
                'customerName' => 'Rudi',
                'petName' => 'Rocky',
                'condition' => 'Good',
                'type' => 'Anjing',
                'race' => 'Bulldog',
                'gender' => 'Jantan',
                'sterile' => 'belum',
                'birthDate' => '01/10/2020',
                'color' => 'black',
            ],
            [
                'customerName' => 'Budi',
                'petName' => 'Ciko',
                'condition' => 'Good',
                'type' => 'Kucing',
                'race' => 'Anggora',
                'gender' => 'Jantan',
                'sterile' => 'sudah',
                'birthDate' => '01/10/2022',
                'color' => 'white',
            ],
            [
                'customerName' => 'Budi',
                'petName' => 'Ciko',
                'condition' => 'Good',
                'type' => 'Kucing',
                'race' => 'Anggora',
                'gender' => 'Jantan',
                'sterile' => 'sudah',
                'birthDate' => '01/10/2022',
                'color' => 'white',
            ],
        ];

        $spreadsheet = IOFactory::load(public_path() . '/template/report/' . 'Template_Report_Customer_Sub_Account.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $item['customerName']);
            $sheet->setCellValue("B{$row}", $item['petName']);
            $sheet->setCellValue("C{$row}", $item['condition']);
            $sheet->setCellValue("D{$row}", $item['type']);
            $sheet->setCellValue("E{$row}", $item['race']);
            $sheet->setCellValue("F{$row}", $item['gender']);
            $sheet->setCellValue("G{$row}", $item['sterile']);
            $sheet->setCellValue("H{$row}", $item['birthDate']);
            $sheet->setCellValue("I{$row}", $item['color']);

            $row++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Report Sub Account List.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Report Sub Account List.xlsx"',
        ]);
    }
}
