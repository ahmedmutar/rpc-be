<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    function listVendor()
    {
        $Data = DB::table('vendorFinances')
            ->select('id', 'vendorName')
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($Data, 200);
    }

    function addVendor(Request $request)
    {
        $request->validate([
            'vendorName' => 'required',
        ]);

        DB::table('vendorFinances')
            ->insert([
                'vendorName' => $request->vendorName,
                'userId' => $request->user()->id,
                'created_at' => now(),
            ]);

        recentActivity(
            $request->user()->id,
            'Vendor Finance',
            'Add Vendor',
            'Added vendor "' . $request->vendorName . '"'
        );

        return response()->json(
            [
                'message' => 'Insert Data Successful!',
            ],
            200
        );
    }

    function listCategory()
    {
        $Data = DB::table('categoryFinances')
            ->select('id', 'categoryName')
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($Data, 200);
    }

    function addCategory(Request $request)
    {
        $request->validate([
            'categoryName' => 'required',
        ]);

        DB::table('categoryFinances')
            ->insert([
                'categoryName' => $request->categoryName,
                'userId' => $request->user()->id,
                'created_at' => now(),
            ]);

        recentActivity(
            $request->user()->id,
            'Category Finance',
            'Add Category',
            'Added category "' . $request->categoryName . '"'
        );

        return response()->json(
            [
                'message' => 'Insert Data Successful!',
            ],
            200
        );
    }

    function listExpenseType()
    {
        $Data = DB::table('expenseTypeFinances')
            ->select('id', 'expenseType')
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($Data, 200);
    }

    function addExpenseType(Request $request)
    {
        $request->validate([
            'expenseType' => 'required',
        ]);

        DB::table('expenseTypeFinances')
            ->insert([
                'expenseType' => $request->expenseType,
                'userId' => $request->user()->id,
                'created_at' => now(),
            ]);

        recentActivity(
            $request->user()->id,
            'Expense Type Finance',
            'Add Expense Type',
            'Added expense type "' . $request->expenseType . '"'
        );

        return response()->json(
            [
                'message' => 'Insert Data Successful!',
            ],
            200
        );
    }

    function listDepartment()
    {
        $Data = DB::table('departmentFinances')
            ->select('id', 'departmentName')
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($Data, 200);
    }

    function addDepartment(Request $request)
    {
        $request->validate([
            'departmentName' => 'required',
        ]);

        DB::table('departmentFinances')
            ->insert([
                'departmentName' => $request->departmentName,
                'userId' => $request->user()->id,
                'created_at' => now(),
            ]);

        recentActivity(
            $request->user()->id,
            'Department Finance',
            'Add Department',
            'Added department "' . $request->departmentName . '"'
        );

        return response()->json(
            [
                'message' => 'Insert Data Successful!',
            ],
            200
        );
    }

    function listPaymentStatus()
    {
        $Data = DB::table('paymentStatusFinances')
            ->select('id', 'paymentStatus')
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($Data, 200);
    }

    function addPaymentStatus(Request $request)
    {
        $request->validate([
            'paymentStatus' => 'required',
        ]);

        DB::table('paymentStatusFinances')
            ->insert([
                'paymentStatus' => $request->paymentStatus,
                'userId' => $request->user()->id,
                'created_at' => now(),
            ]);

        recentActivity(
            $request->user()->id,
            'Payment Status Finance',
            'Add Payment Status',
            'Added payment status "' . $request->paymentStatus . '"'
        );

        return response()->json(
            [
                'message' => 'Insert Data Successful!',
            ],
            200
        );
    }

    function listPaymentMethod()
    {
        $Data = DB::table('paymentMethodFinances')
            ->select('id', 'paymentMethod')
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($Data, 200);
    }

    function addPaymentMethod(Request $request)
    {
        $request->validate([
            'paymentMethod' => 'required',
        ]);

        DB::table('paymentMethodFinances')
            ->insert([
                'paymentMethod' => $request->paymentMethod,
                'userId' => $request->user()->id,
                'created_at' => now(),
            ]);

        recentActivity(
            $request->user()->id,
            'Payment Method Finance',
            'Add Payment Method',
            'Added payment method "' . $request->paymentMethod . '"'
        );

        return response()->json(
            [
                'message' => 'Insert Data Successful!',
            ],
            200
        );
    }
}
