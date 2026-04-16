<?php

namespace App\Http\Controllers\Staff;

use App\Exports\Absent\AbsentReport;
use App\Http\Controllers\Controller;
use App\Models\Location\Location;
use App\Models\StaffAbsents;
use App\Models\Timekeeper;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Support\Carbon;
use DB;
use Symfony\Component\HttpFoundation\Response;
use Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class AbsentController extends Controller
{
    public function Index(Request $request)
    {
        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $data = DB::table('staffAbsents as sa')
            ->join('presentStatuses as ps', 'sa.statusPresent', 'ps.id')
            ->leftJoin('presentStatuses as ps1', 'sa.statusHome', 'ps1.id')
            ->join('users as u', 'sa.userId', 'u.id')
            ->join('jobTitle as j', 'u.jobTitleId', 'j.id')
            ->join('usersLocation as ul', 'ul.usersId', 'u.id')
            ->join('location as l', 'ul.locationId', 'l.id')
            ->join('jobTitle as jt', 'u.jobTitleId', 'jt.id')
            ->select(
                'sa.id',
                'u.firstName as name',
                // DB::raw("TRIM(CONCAT(CASE WHEN u.firstName = '' or u.firstName is null THEN '' ELSE CONCAT(u.firstName,' ') END
                // ,CASE WHEN u.middleName = '' or u.middleName is null THEN '' ELSE CONCAT(u.middleName,' ') END,
                // case when u.lastName = '' or u.lastName is null then '' else u.lastName end)) as name"),
                'j.jobName',
                'sa.shift',
                'sa.status',
                DB::raw("
                CONCAT(
                    CASE DAYOFWEEK(sa.presentTime)
                        WHEN 1 THEN 'Minggu'
                        WHEN 2 THEN 'Senin'
                        WHEN 3 THEN 'Selasa'
                        WHEN 4 THEN 'Rabu'
                        WHEN 5 THEN 'Kamis'
                        WHEN 6 THEN 'Jumat'
                        WHEN 7 THEN 'Sabtu'
                    END,
                    ', ',
                    DATE_FORMAT(sa.presentTime, '%e %b %Y')
                ) AS day
                "),
                DB::raw("TIME_FORMAT(sa.presentTime, '%H:%i') AS presentTime"),
                DB::raw("CASE WHEN sa.homeTime is null THEN '' ELSE TIME_FORMAT(sa.homeTime, '%H:%i') END AS homeTime"),
                DB::raw("CASE WHEN sa.duration is null THEN '' ELSE CONCAT(
                    HOUR(sa.duration), ' jam ',
                    MINUTE(sa.duration), ' menit'
                ) END AS duration"),
                'ps.statusName as presentStatus',
                DB::raw("CASE WHEN ps1.statusName is null THEN '' ELSE ps1.statusName END as homeStatus"),
                'sa.cityPresent as presentLocation',
                DB::raw("CASE WHEN sa.cityHome is null THEN '' ELSE sa.cityHome END as homeLocation"),
            )
            ->where('sa.isDeleted', '=', 0);

        if ($request->user()->roleId <> 1 && $request->user()->roleId <> 6) {
            $data = $data->where('sa.userId', '=', $request->user()->id);
        }

        if ($request->dateFrom && $request->dateTo) {

            $data = $data->whereBetween('sa.presentTime', [$request->dateFrom, $request->dateTo]);
        }

        if ($request->locationId) {

            $data = $data->whereIn('l.id', $request->locationId);
        }

        if ($request->staff) {
            $data = $data->whereIn('sa.userId', $request->staff);
        }

        if ($request->statusPresent) {
            $data = $data->whereIn('sa.statusPresent', $request->statusPresent);
        }

        if ($request->staffJob) {
            $data = $data->whereIn('jt.id', $request->staffJob);
        }

        if ($request->orderValue) {

            if ($request->orderColumn == "name") {
                $data = $data->orderBy('u.firstName', $request->orderValue);
            } elseif ($request->orderColumn == "day") {
                $data = $data->orderBy('sa.presentTime', $request->orderValue);
            } elseif ($request->orderColumn == "presentTime") {
                $data = $data->orderBy('sa.presentTime', $request->orderValue);
            } elseif ($request->orderColumn == "homeTime") {
                $data = $data->orderBy('sa.homeTime', $request->orderValue);
            } elseif ($request->orderColumn == "presentStatus") {
                $data = $data->orderBy('ps.statusName', $request->orderValue);
            } elseif ($request->orderColumn == "homeStatus") {
                $data = $data->orderBy('ps1.statusName', $request->orderValue);
            } elseif ($request->orderColumn == "presentLocation") {
                $data = $data->orderBy('sa.cityPresent', $request->orderValue);
            } elseif ($request->orderColumn == "homeLocation") {
                $data = $data->orderBy('sa.cityHome', $request->orderValue);
            } else {
                $data = $data->orderBy($request->orderColumn, $request->orderValue);
            }
        }

        $data = $data->groupBy(
            'sa.id',
            'u.firstName',
            // 'u.middleName',
            // 'u.lastName',
            'j.jobName',
            'sa.shift',
            'sa.status',
            'sa.presentTime',
            'sa.homeTime',
            'sa.duration',
            'ps.statusName',
            'ps1.statusName',
            'sa.cityPresent',
            'sa.cityHome'
        );

        $data = $data->orderBy('sa.updated_at', 'desc');

        $offset = ($page - 1) * $itemPerPage;

        $dataTemp = $data->get();

        $count_data = $dataTemp->count();

        $count_result = $count_data - $offset;

        if ($count_result < 0) {
            $data = $data->offset(0)->limit($itemPerPage)->get();
        } else {
            $data = $data->offset($offset)->limit($itemPerPage)->get();
        }

        $totalPaging = $count_data / $itemPerPage;

        return responseIndex(ceil($totalPaging), $data);
    }

    public function Detail(Request $request)
    {
        $data = DB::table('staffAbsents as sa')
            ->join('presentStatuses as ps', 'sa.statusPresent', 'ps.id')
            ->leftJoin('presentStatuses as ps1', 'sa.statusHome', 'ps1.id')
            ->join('users as u', 'sa.userId', 'u.id')
            ->join('jobTitle as j', 'u.jobTitleId', 'j.id')
            ->join('usersLocation as ul', 'ul.usersId', 'u.id')
            ->join('location as l', 'ul.locationId', 'l.id')
            ->select(
                'sa.id',
                'u.id as userId',
                'j.jobName',
                'u.firstName as name',
                // DB::raw("TRIM(CONCAT(CASE WHEN u.firstName = '' or u.firstName is null THEN '' ELSE CONCAT(u.firstName,' ') END
                // ,CASE WHEN u.middleName = '' or u.middleName is null THEN '' ELSE CONCAT(u.middleName,' ') END,
                // case when u.lastName = '' or u.lastName is null then '' else u.lastName end)) as name"),
                DB::raw("
            CONCAT(
                CASE DAYOFWEEK(sa.presentTime)
                    WHEN 1 THEN 'Minggu'
                    WHEN 2 THEN 'Senin'
                    WHEN 3 THEN 'Selasa'
                    WHEN 4 THEN 'Rabu'
                    WHEN 5 THEN 'Kamis'
                    WHEN 6 THEN 'Jumat'
                    WHEN 7 THEN 'Sabtu'
                END,
                ', ',
                DATE_FORMAT(sa.presentTime, '%e %M %Y')
            ) AS day
            "),
                DB::raw("TIME_FORMAT(sa.presentTime, '%H:%i') AS attendanceTime"),
                DB::raw("CASE WHEN sa.homeTime is null THEN '' ELSE TIME_FORMAT(sa.homeTime, '%H:%i') END AS homecomingTime"),
                DB::raw("CASE WHEN sa.duration is null THEN '' ELSE CONCAT(
                HOUR(sa.duration), ' jam ',
                MINUTE(sa.duration), ' menit'
            ) END AS duration"),

                DB::raw("CASE WHEN ps.statusName is null THEN '' ELSE ps.statusName END as attendanceStatus"),
                DB::raw("CASE WHEN ps1.statusName is null THEN '' ELSE ps1.statusName END as homecomingStatus"),

                DB::raw("CASE WHEN sa.reasonPresent is null THEN '' ELSE sa.reasonPresent END as attendanceReason"),
                DB::raw("CASE WHEN sa.reasonHome is null THEN '' ELSE sa.reasonHome END as homecomingReason"),

                DB::raw("CASE WHEN sa.cityPresent is null THEN '' ELSE sa.cityPresent END as attendanceLocation"),
                DB::raw("CASE WHEN sa.cityHome is null THEN '' ELSE sa.cityHome END as homecomingLocation"),

                DB::raw("CASE WHEN sa.imagePathPresent is null THEN '' ELSE sa.imagePathPresent END as attendanceImagePath"),
                DB::raw("CASE WHEN sa.imagePathHome is null THEN '' ELSE sa.imagePathHome END as homecomingImagePath"),
            )
            ->where('sa.id', '=', $request->id)
            ->first();

        $dataLoc = DB::table('usersLocation as ul')
            ->join('location as l', 'ul.locationId', 'l.id')
            ->select(DB::raw("GROUP_CONCAT(l.locationName SEPARATOR ', ') as location"))
            ->where('ul.usersId', '=', $data->userId)
            ->groupBy('usersId')
            ->pluck('location')
            ->first();

        $data->location = $dataLoc;

        return response()->json($data, 200);
    }

    public function Export(Request $request)
    {
        // ── 1. Build file name ────────────────────────────────────────────────
        $location = $jobName = $date = '';

        if (!empty($request->locationId)) {
            $location = ' ' . DB::table('location')
                ->whereIn('id', $request->locationId)
                ->value(DB::raw("GROUP_CONCAT(locationName SEPARATOR ', ')"));
        }

        if (!empty($request->staffJob)) {
            $jobName = ' ' . DB::table('jobTitle')
                ->whereIn('id', $request->staffJob)
                ->value(DB::raw("GROUP_CONCAT(jobName SEPARATOR ', ')"));
        }

        $dateFrom = Carbon::parse($request->dateFrom);
        $dateTo   = Carbon::parse($request->dateTo);

        if ($request->dateFrom && $request->dateTo) {
            $date = ' ' . $dateFrom->format('dMY') . ' - ' . $dateTo->format('dMY');
        }

        $fileName = "Rekap Absensi{$jobName}{$location}{$date}.xlsx";

        // ── 2. Pre-build date range once ──────────────────────────────────────
        $allDates = [];
        $cursor   = $dateFrom->copy();
        while ($cursor->lessThanOrEqualTo($dateTo)) {
            $allDates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // ── 3. Bulk-load locations ────────────────────────────────────────────
        $locationQuery = DB::table('location')->where('isDeleted', 0);
        if (!empty($request->locationId) && $request->locationId[0] !== null) {
            $locationQuery->whereIn('id', $request->locationId);
        }
        $locations   = $locationQuery->get();
        $locationIds = $locations->pluck('id')->all();

        // ── 4. Bulk-load ALL users for these locations in ONE query ───────────
        $userQuery = DB::table('users as u')
            ->join('usersLocation as ul', 'ul.usersId', 'u.id')
            ->join('location as l', 'ul.locationId', 'l.id')
            ->join('jobTitle as j', 'u.jobTitleId', 'j.id')
            ->select('u.id', 'u.firstName as name', 'l.locationName', 'j.jobName', 'ul.locationId')
            ->where('ul.isMainLocation', 1)
            ->where('u.isDeleted', 0)
            ->whereIn('ul.locationId', $locationIds);

        if (!empty($request->staff) && $request->staff[0] !== null) {
            $userQuery->whereIn('u.id', $request->staff);
        }

        $allUsers        = $userQuery->get();
        $usersByLocation = $allUsers->groupBy('locationId');
        $allUserIds      = $allUsers->pluck('id')->all();

        // ── 5. Bulk-load ALL absences in ONE query ────────────────────────────
        $absences = DB::table('staffAbsents as sa')
            ->select(
                'sa.userId',
                DB::raw("DATE(sa.presentTime) as absentDate"),
                DB::raw("DATE_FORMAT(sa.presentTime, '%H:%i') as presentTime"),
                DB::raw("DATE_FORMAT(sa.homeTime, '%H:%i') as homeTime"),
                'sa.duration',
                'sa.status'
            )
            ->whereIn('sa.userId', $allUserIds)
            ->where('sa.statusPresent', 1)
            ->where('sa.isDeleted', 0)
            ->whereBetween(DB::raw('DATE(sa.presentTime)'), [$request->dateFrom, $request->dateTo])
            ->get();

        // Index: [ userId => [ date => absence ] ]
        $absenceMap = [];
        foreach ($absences as $abs) {
            $absenceMap[$abs->userId][$abs->absentDate] = $abs;
        }

        // ── 6. Bulk-load ALL shift counts in ONE query each ──────────────────
        $longShifts = DB::table('long_shifts')
            ->select('userId', DB::raw('COUNT(*) as total'))
            ->whereIn('userId', $allUserIds)
            ->where('isDeleted', 0)
            ->where('status', 1)
            ->whereBetween('longShiftDate', [$request->dateFrom, $request->dateTo])
            ->groupBy('userId')
            ->pluck('total', 'userId');

        $fullShifts = DB::table('full_shifts')
            ->select('userId', DB::raw('COUNT(*) as total'))
            ->whereIn('userId', $allUserIds)
            ->where('isDeleted', 0)
            ->where('status', 1)
            ->whereBetween('fullShiftDate', [$request->dateFrom, $request->dateTo])
            ->groupBy('userId')
            ->pluck('total', 'userId');

        // ── 7. Build spreadsheet ──────────────────────────────────────────────
        $spreadsheet = IOFactory::load(public_path('/template/absen/Template_Export_Absen2.xlsx'));
        $sheet       = $spreadsheet->getSheet(0);

        // Static header columns A–C
        $sheet->setCellValue('A2', 'Nama Cabang');
        $sheet->setCellValue('B2', 'Jabatan');
        $sheet->setCellValue('C2', 'Nama');
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(30);

        // Dynamic date columns
        $colIndex = 4;
        foreach ($allDates as $dateString) {
            $day          = Carbon::parse($dateString)->day;
            $startColChar = Coordinate::stringFromColumnIndex($colIndex);
            $endColChar   = Coordinate::stringFromColumnIndex($colIndex + 2);

            $sheet->mergeCells("{$startColChar}1:{$endColChar}1");
            $sheet->setCellValue("{$startColChar}1", $day);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex)     . '2', 'Masuk');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 1) . '2', 'Pulang');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 2) . '2', 'Jam Kerja');
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setWidth(12);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex + 1))->setWidth(12);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex + 2))->setWidth(12);

            $colIndex += 3;
        }

        // ── 8. Summary header columns ─────────────────────────────────────────
        $summaryHeaders = [
            'Total Masuk'                          => 11,
            'Total Libur'                          => 11,
            'Total Tidak Masuk/Izin'               => 20,
            'Sakit'                                => 11,
            'Cuti'                                 => 11,
            'Total Telat/Tidak Absen Masuk/Pulang' => 33,
            'Long Shift'                           => 11,
            'Full Shift'                           => 11,
            'Hard Shift'                           => 11,
            'Atribut Tidak Lengkap'                => 19,
        ];

        $yellowFill = [
            'fill' => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFF00'],
            ],
        ];

        $redFill = [
            'fill' => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FF0000'],
            ],
        ];

        foreach ($summaryHeaders as $header => $width) {
            $colChar = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($colChar)->setWidth($width);
            $sheet->setCellValue("{$colChar}2", $header);
            if ($header !== 'Total Masuk') {
                $sheet->getStyle("{$colChar}2")->applyFromArray($yellowFill);
            }
            $colIndex++;
        }

        // ── 9. Write data rows (zero DB queries inside loops) ─────────────────
        $currentRow = 3;

        foreach ($locations as $loc) {
            $users = $usersByLocation->get($loc->id, collect());

            foreach ($users as $usr) {
                $sheet->setCellValue('A' . $currentRow, $loc->locationName);
                $sheet->setCellValue('B' . $currentRow, $usr->jobName);
                $sheet->setCellValue('C' . $currentRow, $usr->name);

                $colIndex   = 4;
                $totalMasuk = 0;
                $totalTelat = 0;
                $tidakAbsen = 0;

                foreach ($allDates as $dateString) {
                    $absent = $absenceMap[$usr->id][$dateString] ?? null;

                    if ($absent) {
                        if ($absent->status === 'Terlambat') {
                            $totalTelat++;
                            $sheet->getStyle(Coordinate::stringFromColumnIndex($colIndex) . $currentRow)
                                ->applyFromArray($yellowFill);
                        }

                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex)     . $currentRow, $absent->presentTime);
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 1) . $currentRow, $absent->homeTime);

                        $duration  = $absent->duration ?? '00:00:00';
                        $formatted = Carbon::createFromFormat('H:i:s', $duration)->format('G.i');
                        $sheet->setCellValueExplicit(
                            Coordinate::stringFromColumnIndex($colIndex + 2) . $currentRow,
                            $formatted,
                            DataType::TYPE_STRING
                        );

                        $totalMasuk++;
                    } else {
                        $tidakAbsen++;
                        $rangeStart = Coordinate::stringFromColumnIndex($colIndex);
                        $rangeEnd   = Coordinate::stringFromColumnIndex($colIndex + 2);
                        $sheet->getStyle("{$rangeStart}{$currentRow}:{$rangeEnd}{$currentRow}")
                            ->applyFromArray($redFill);
                    }

                    $colIndex += 3;
                }

                // Summary columns
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex)     . $currentRow, $totalMasuk);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 1) . $currentRow, 0); // Total Libur
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 2) . $currentRow, 0); // Tidak Masuk/Izin
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 3) . $currentRow, 0); // Sakit
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 4) . $currentRow, 0); // Cuti
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 5) . $currentRow, $tidakAbsen);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 6) . $currentRow, $longShifts[$usr->id] ?? 0);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 7) . $currentRow, $fullShifts[$usr->id] ?? 0);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 8) . $currentRow, 0); // Hard Shift
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex + 9) . $currentRow, 0); // Atribut Tidak Lengkap

                $currentRow++;
            }
        }

        // ── 10. Apply global border + alignment in ONE call ───────────────────
        $lastCol = Coordinate::stringFromColumnIndex($colIndex + 9);
        $sheet->getStyle("A1:{$lastCol}" . ($currentRow - 1))->applyFromArray([
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
            ],
        ]);

        // ── 11. Stream response ───────────────────────────────────────────────
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    public function presentStatusList()
    {
        $data = DB::table('presentStatuses')
            ->select('id', 'statusName')
            ->get();

        return response()->json($data, 200);
    }

    public function staffListAbsent(Request $request)
    {
        if (adminAccess($request->user()->id)) {

            $data = DB::table('users')
                ->select(
                    'id',
                    'firstName as fullName',
                    //     DB::raw("TRIM(CONCAT(CASE WHEN firstName = '' or firstName is null THEN '' ELSE CONCAT(firstName,' ') END
                    // ,CASE WHEN middleName = '' or middleName is null THEN '' ELSE CONCAT(middleName,' ') END,
                    // case when lastName = '' or lastName is null then '' else lastName end)) as fullName")
                )
                ->where('isDeleted', '=', 0)
                ->get();

            return response()->json($data, 200);
        } else {
            return responseUnauthorize();
        }
    }

    public function createAbsent(Request $request)
    {

        if ($request->status == 1 || $request->status == 4) {
            $validate = Validator::make($request->all(), [
                'presentTime' => 'required|date_format:d/m/Y H:i',
                'longitude' => 'nullable|string',
                'latitude' => 'nullable|string',
                'status' => 'required|integer|in:1,2,3,4',
                'reason' => 'nullable|string',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'province' => 'nullable|string',
                'image' => 'required|image|mimes:jpg,png,jpeg,gif,svg|max:5000',
            ]);
        } else {
            $validate = Validator::make($request->all(), [
                'presentTime' => 'required|date_format:d/m/Y H:i',
                'longitude' => 'nullable|string',
                'latitude' => 'nullable|string',
                'status' => 'required|integer|in:1,2,3,4',
                'reason' => 'nullable|string',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'province' => 'nullable|string',
                'image' => 'nullable',
            ]);
        }

        $users = DB::table('users')
            ->leftjoin('usersRoles', 'usersRoles.id', '=', 'users.roleId')
            ->leftjoin('jobTitle', 'jobTitle.id', '=', 'users.jobTitleId')
            ->select(
                'users.id',
                'users.imagePath',
                'users.roleId',
                DB::raw("IF(usersRoles.roleName IS NULL, '', usersRoles.roleName) as roleName"),
                'jobTitle.id as jobTitleId',
                DB::raw("IF(jobTitle.jobName IS NULL,'', jobTitle.jobName) as jobName"),
                DB::raw("CONCAT(IFNULL(users.firstName,'') ,' ', IFNULL(users.lastName,'')) as name"),
            )
            ->where([
                ['users.id', '=', $request->user()->id]
            ])
            ->first();

        $keeper = Timekeeper::where('jobtitleId', '=', $users->jobTitleId)
            ->where('isDeleted', '=', 0)
            ->get();

        if (count($keeper) > 1) {
            $validate = Validator::make($request->all(), [
                'shift' => 'required|integer|in:1,2',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid([$errors]);
            }
        }

        $currentDate = Carbon::now();
        $presentTime = $currentDate->format('d/m/Y H:i');
        $presentTime2 = $currentDate->format('Y-m-d H:i');

        $present = DB::table('staffAbsents')
            ->where('userId', '=', $request->user()->id)
            ->whereDate('created_at', Carbon::today())
            ->first();

        if ($present) {
            if ($present->statusPresent == 1 && $request->status == 1) {
                return responseInvalid(['You have already absent today!']);
            }
        }

        if ($request->status == 4) {

            if (!$present) {
                return responseInvalid(['You can not absent home today! Cause you have not been absent present today!']);
            }
        }

        if (($request->status == 2 || $request->status == 3) && $request->reason == '') {
            return responseInvalid(['Reason should be filled!']);
        }

        $oldname = '';
        $realName = '';
        $path = '';

        if ($request->hasfile('image')) {

            $files[] = $request->file('image');

            foreach ($files as $file) {

                $realName = $file->hashName();

                $file_size = $file->getSize();

                $file_size = $file_size / 1024;

                $oldname = $file->getClientOriginalName();

                $file->move(public_path() . '/AbsentImages/', $realName);

                $path = "/AbsentImages/" . $realName;
            }
        }

        $city = '';
        $province = '';
        $reason = '';

        if ($request->city != '') {
            $city = $request->city;
        }

        if ($request->province != '') {
            $province = $request->province;
        }

        if ($request->reason != '') {
            $reason = $request->reason;
        }

        $status = "";
        $shift = "";

        $keeperRes = Timekeeper::where('jobtitleId', '=', $users->jobTitleId)->where('isDeleted', '=', 0);

        if (count($keeper) > 1) {
            $keeperRes = $keeperRes->where('shiftId', '=', $request->shift);
            $shift = 'Shift ' . $request->shift;
        }

        $keeperRes = $keeperRes->first();

        $time2 = $keeperRes->time;
        $time1 = Carbon::now();

        if ($time1->greaterThan($time2)) {
            $status = "Terlambat";
        } elseif ($time1->lessThan($time2)) {
            $status = "Tepat Waktu";
        } else {
            $status = "Tepat Waktu";
        }

        // if ($users->jobName == 'Dokter Hewan') {
        //     $shift = 'Shift ' . $request->shift;
        //     if ($request->shift == 1) {
        //         $time2 = Carbon::createFromFormat('H:i', '15:45', 'Asia/Jakarta')->setTimezone('UTC');
        //         //$time2 = Carbon::createFromFormat('H:i', '08:45');
        //     } elseif ($request->shift == 2) {
        //         $time2 = Carbon::createFromFormat('H:i', '21:00', 'Asia/Jakarta')->setTimezone('UTC');
        //         //$time2 = Carbon::createFromFormat('H:i', '14:00');
        //     }
        // } else if ($users->jobName == 'Paramedis') {
        //     $time2 = Carbon::createFromFormat('H:i', '15:45', 'Asia/Jakarta')->setTimezone('UTC');
        //     // $time2 = Carbon::parse('08:45');
        // } else if ($users->jobName == 'Kasir') {
        //     $time2 = Carbon::createFromFormat('H:i', '15:30', 'Asia/Jakarta')->setTimezone('UTC');
        //     // $time2 = Carbon::parse('08:30');
        // } else if ($users->jobName == 'Vetnurse') {
        //     $time2 = Carbon::createFromFormat('H:i', '15:30', 'Asia/Jakarta')->setTimezone('UTC');
        //     // $time2 = Carbon::parse('08:30');
        // } else {
        //     //if ($request->user()->jobName == 6) {
        //     $time2 = Carbon::createFromFormat('H:i', '19:30', 'Asia/Jakarta')->setTimezone('UTC');
        //     // $time2 = Carbon::parse('12:30');
        // }


        // $time1 = Carbon::now()->addHour(7); // Jam dan menit pertama

        // if ($time1->greaterThan($time2)) {
        //     $status = "Terlambat";
        // } elseif ($time1->lessThan($time2)) {
        //     $status = "Tepat Waktu";
        // } else {
        //     $status = "Tepat Waktu";
        // }

        if (!$present) {

            StaffAbsents::create([
                'presentTime' => $presentTime2,
                'presentLongitude' => $request->longitude,
                'presentLatitude' => $request->latitude,
                'statusPresent' => $request->status,
                'reasonPresent' => $reason,
                'realImageNamePresent' => $oldname,
                'imagePathPresent' =>  $path,
                'cityPresent' => $city,
                'provincePresent' => $province,
                'shift' => $shift,
                'status' => $status,
                'userId' => $request->user()->id,
            ]);
        } else {

            $currentDate = Carbon::now();

            $presentTime = $present->presentTime;
            $homeTime = $currentDate->format('Y-m-d H:i:s');

            // Convert strings to Carbon instances
            $carbonDatePresent = Carbon::parse($presentTime);
            $carbonDateHome = Carbon::parse($homeTime);

            $totalDuration =  $carbonDateHome->diffInSeconds($carbonDatePresent);

            $duration = gmdate('H:i:s', $totalDuration);

            if ($totalDuration <= 28800) {
                $status = 'Terlambat';
            } else {
                $status = 'Tepat Waktu';
            }

            StaffAbsents::where('id', '=', $present->id)
                ->update(
                    [
                        'status' => $status,
                        'homeTime' => $homeTime,
                        'duration' => $duration,
                        'homeLongitude' => $request->longitude,
                        'homeLatitude' => $request->latitude,
                        'statusHome' => $request->status,
                        'reasonHome' => $reason,
                        'realImageNameHome' => $oldname,
                        'imagePathHome' =>  $path,
                        'cityHome' => $city,
                        'provinceHome' => $province,
                    ]
                );
        }

        return responseCreate();
    }
}
