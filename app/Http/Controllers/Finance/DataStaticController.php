<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CategoryFinance;
use App\Models\DepartmentFinance;
use App\Models\ExpenseTypeFinance;
use App\Models\paymentMethodFinance;
use App\Models\paymentStatusFinance;
use App\Models\VendorFinance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class DataStaticController extends Controller
{
    function index(Request $request)
    {
        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $dataVendor = DB::table('vendorFinances as tp')
            ->join('users as u', 'tp.userId', 'u.id')
            ->select(
                'tp.id',
                'tp.vendorName as typeName',
                DB::raw("'vendor' as type"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tp.created_at, '%d/%m/%Y') as createdAt")
            )
            ->where('tp.isDeleted', '=', 0);

        $dataCategory = DB::table('categoryFinances as tp')
            ->join('users as u', 'tp.userId', 'u.id')
            ->select(
                'tp.id',
                'tp.categoryName as typeName',
                DB::raw("'category' as type"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tp.created_at, '%d/%m/%Y') as createdAt")
            )
            ->where('tp.isDeleted', '=', 0);

        $dataExpenseType = DB::table('expenseTypeFinances as tp')
            ->join('users as u', 'tp.userId', 'u.id')
            ->select(
                'tp.id',
                'tp.expenseType as typeName',
                DB::raw("'expense' as type"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tp.created_at, '%d/%m/%Y') as createdAt")
            )
            ->where('tp.isDeleted', '=', 0);

        $dataDepartment = DB::table('departmentFinances as tp')
            ->join('users as u', 'tp.userId', 'u.id')
            ->select(
                'tp.id',
                'tp.departmentName as typeName',
                DB::raw("'department' as type"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tp.created_at, '%d/%m/%Y') as createdAt")
            )
            ->where('tp.isDeleted', '=', 0);

        $dataPaymentStatus = DB::table('paymentStatusFinances as tp')
            ->join('users as u', 'tp.userId', 'u.id')
            ->select(
                'tp.id',
                'tp.paymentStatus as typeName',
                DB::raw("'payment_status' as type"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tp.created_at, '%d/%m/%Y') as createdAt")
            )
            ->where('tp.isDeleted', '=', 0);

        $dataPaymentMethod = DB::table('paymentMethodFinances as tp')
            ->join('users as u', 'tp.userId', 'u.id')
            ->select(
                'tp.id',
                'tp.paymentMethod as typeName',
                DB::raw("'payment_method' as type"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tp.created_at, '%d/%m/%Y') as createdAt")
            )
            ->where('tp.isDeleted', '=', 0);

        $dataVendor = $dataVendor
            ->union($dataCategory)
            ->union($dataExpenseType)
            ->union($dataDepartment)
            ->union($dataPaymentStatus)
            ->union($dataPaymentMethod);

        $data = DB::query()->fromSub($dataVendor, 'p_pn')
            ->select('id', 'typeName', 'type', 'createdBy', 'createdAt');

        $dataTemp = DB::query()->fromSub($dataVendor, 'p_pn')
            ->select('id', 'typeName', 'type', 'createdBy', 'createdAt');

        $temp_column = null;

        if ($request->search) {

            $data1 = $dataTemp->where('typeName', 'like', '%' . $request->search . '%')->get();

            if (count($data1)) {
                $temp_column[] = 'typeName';
            }

            $dataTemp = DB::query()->fromSub($dataVendor, 'p_pn')
                ->select('id', 'typeName', 'type', 'createdBy', 'createdAt');

            $data2 = $dataTemp->where('type', 'like', '%' . $request->search . '%')->get();

            if (count($data2)) {
                $temp_column[] = 'type';
            }

            $dataTemp = DB::query()->fromSub($dataVendor, 'p_pn')
                ->select('id', 'typeName', 'type', 'createdBy', 'createdAt');

            $data3 = $dataTemp->where('createdBy', 'like', '%' . $request->search . '%')->get();

            if (count($data3)) {
                $temp_column[] = 'createdBy';
            }

            $res = $temp_column;

            if ($res) {
                $data = $data->where($res[0], 'like', '%' . $request->search . '%');

                for ($i = 1; $i < count($res); $i++) {
                    $data = $data->orWhere($res[$i], 'like', '%' . $request->search . '%');
                }
            } else {
                $data = [];
                return responseIndex(0, $data);
            }
        }

        if ($request->orderValue) {
            $data = $data->orderBy($request->orderColumn, $request->orderValue);
        }

        $data = $data->orderBy('createdAt', 'desc');

        $offset = ($page - 1) * $itemPerPage;

        $count_data = $data->count();
        $count_result = $count_data - $offset;

        if ($count_result < 0) {
            $data = $data->offset(0)->limit($itemPerPage)->get();
        } else {
            $data = $data->offset($offset)->limit($itemPerPage)->get();
        }

        $totalPaging = $count_data / $itemPerPage;

        return responseIndex(ceil($totalPaging), $data);
    }

    function delete(Request $request)
    {

        $validate = Validator::make(
            $request->datas,
            [
                '*.id' => 'required|integer',
                '*.type' => 'required|string|in:vendor,category,expense,department,payment_status,payment_method',
            ],
            [
                '*.id.required' => 'Id Should be Required!',
                '*.id.integer' => 'Id Should be Integer!',

                '*.type.required' => 'Type Should be Required!',
                '*.type.string' => 'Type Should be String!',
                '*.type.in' => 'Type Should be Vendor, Category, Expense, Department, Payment Status or Payment Method!',
            ]
        );

        if ($validate->fails()) {
            $errors = $validate->errors()->first();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [$errors],
            ], 422);
        }

        foreach ($request->datas as $value) {

            if ($value['type'] == 'vendor') {
                VendorFinance::where('id', '=', $value['id'])
                    ->update(
                        [
                            'deletedBy' => $request->user()->id,
                            'isDeleted' => 1,
                            'deletedAt' => Carbon::now()
                        ]
                    );
            } elseif ($value['type'] == 'category') {

                CategoryFinance::where('id', '=', $value['id'])
                    ->update(
                        [
                            'deletedBy' => $request->user()->id,
                            'isDeleted' => 1,
                            'deletedAt' => Carbon::now()
                        ]
                    );
            } elseif ($value['type'] == 'expense') {
                ExpenseTypeFinance::where('id', '=', $value['id'])
                    ->update(
                        [
                            'deletedBy' => $request->user()->id,
                            'isDeleted' => 1,
                            'deletedAt' => Carbon::now()
                        ]
                    );
            } elseif ($value['type'] == 'department') {
                DepartmentFinance::where('id', '=', $value['id'])
                    ->update(
                        [
                            'deletedBy' => $request->user()->id,
                            'isDeleted' => 1,
                            'deletedAt' => Carbon::now()
                        ]
                    );
            } elseif ($value['type'] == 'payment_status') {
                paymentStatusFinance::where('id', '=', $value['id'])
                    ->update(
                        [
                            'deletedBy' => $request->user()->id,
                            'isDeleted' => 1,
                            'deletedAt' => Carbon::now()
                        ]
                    );
            } else if ($value['type'] == 'payment_method') {
                paymentMethodFinance::where('id', '=', $value['id'])
                    ->update(
                        [
                            'deletedBy' => $request->user()->id,
                            'isDeleted' => 1,
                            'deletedAt' => Carbon::now()
                        ]
                    );
            }
        }

        return responseDelete();
    }
}
