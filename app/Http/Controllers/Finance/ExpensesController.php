<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExpensesController extends Controller
{
    function index(Request $request)
    {
        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $data = DB::table('expenses as e')
            ->join('users as u', 'e.userId', 'u.id')
            ->join('categoryFinances as cf', 'e.categoryId', 'cf.id')
            ->join('vendorFinances as vf', 'e.vendorId', 'vf.id')
            ->join('location as l', 'e.locationId', 'l.id')
            ->join('paymentStatusFinances as ps', 'e.paymentStatusId', 'ps.id')
            ->leftJoin('users as ua', 'e.userApprovalId', 'ua.id')
            ->select(
                'e.id',
                'e.referenceNo',
                'e.transactionDate',
                'cf.categoryName',
                'vf.vendorName',
                'l.locationName',
                'e.paymentStatusId',
                'ps.paymentStatus as paymentStatusName',
                DB::raw("TRIM(e.grandTotal)+0 as totalAmount"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(e.updated_at, '%d/%m/%Y') as createdAt"),
                'ua.firstName as approvedBy',
                DB::raw("DATE_FORMAT(e.approvalAt, '%d/%m/%Y') as approvalDate"),
            )
            ->where('e.isDeleted', '=', 0)
            ->where('e.statusApproval', '=', $request->statusApproval ? $request->statusApproval : 'Pending');

        if ($request->locationId) {
            $data = $data->where('e.locationId', $request->locationId);
        }

        if ($request->dateFrom && $request->dateTo) {
            $data = $data->whereBetween('e.transactionDate', [$request->dateFrom, $request->dateTo]);
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
            if ($request->orderColumn == 'createdAt') {
                $data = $data->orderBy('e.updated_at', $request->orderValue);
            } else {
                $data = $data->orderBy($request->orderColumn, $request->orderValue);
            }
        } else {
            $data = $data->orderBy('e.updated_at', 'desc');
        }

        if ($itemPerPage) {

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
        } else {
            $data = $data->get();
            return response()->json($data);
        }
    }

    function create(Request $request)
    {
        $message = [
            'transactionDate.required' => 'Transaction Date harus diisi',
            'transactionDate.date' => 'Transaction Date harus berupa tanggal',
            'referenceNo.required' => 'Reference No harus diisi',
            'vendorId.required' => 'Vendor harus diisi',
            'vendorId.integer' => 'Vendor harus angka',
            'locationId.required' => 'Branch Id harus diisi',
            'locationId.integer' => 'Branch Id harus angka',
            'subTotal.required' => 'Sub Total harus diisi',
            'subTotal.numeric' => 'Sub Total harus angka',
            'tax.required' => 'Pajak harus diisi',
            'tax.numeric' => 'Pajak harus angka',
            'pph.required' => 'Pph harus diisi',
            'pph.numeric' => 'Pph harus angka',
            'grandTotal.required' => 'Grand Total harus diisi',
            'grandTotal.numeric' => 'Grand Total harus angka',
            'categoryId.required' => 'Category Id harus diisi',
            'categoryId.integer' => 'Category Id harus angka',
            'expenseTypeId.required' => 'Expense Type Id harus diisi',
            'expenseTypeId.integer' => 'Expense Type Id harus angka',
            'departmentId.required' => 'Department Id harus diisi',
            'departmentId.integer' => 'Department Id harus angka',
            'paymentStatus.required' => 'Payment Status harus diisi',
            'paymentStatus.integer' => 'Payment Status harus angka',
        ];

        $validate = Validator::make($request->all(), [
            'transactionDate' => 'required|date',
            'referenceNo' => 'required|string',
            'vendorId' => 'required|integer',
            'locationId' => 'required|integer',
            'subTotal' => 'required|numeric',
            'tax' => 'required|numeric',
            'pph' => 'required|numeric',
            'grandTotal' => 'required|numeric',
            'categoryId' => 'required|integer',
            'expenseTypeId' => 'required|integer',
            'departmentId' => 'required|integer',
            'paymentStatus' => 'required|integer',
            'dueDate' => 'nullable|date',
            'paymentMethodId' => 'required|integer',
            'description' => 'required|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ], $message);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $hashedName = "";
        $realName = "";

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            $realName = $file->getClientOriginalName();
            $hashedName = $file->hashName();
            // Simpan ke public/uploads
            $file->move(public_path('ExpenseImages'), $hashedName);
        }

        expense::create([
            'transactionDate' => $request->transactionDate,
            'referenceNo' => $request->referenceNo,
            'vendorId' => $request->vendorId,
            'locationId' => $request->locationId,
            'subTotal' => $request->subTotal,
            'tax' => $request->tax,
            'pph' => $request->pph,
            'grandTotal' => $request->grandTotal,
            'categoryId' => $request->categoryId,
            'expenseTypeId' => $request->expenseTypeId,
            'departmentId' => $request->departmentId,
            'paymentStatusId' => $request->paymentStatusId,
            'dueDate' => $request->dueDate,
            'paymentMethodId' => $request->paymentMethodId,
            'description' => $request->description,
            'realImageName' => $realName,
            'imagePath' => '/ExpenseImages/' . $hashedName,
            'statusApproval' => 'Pending',
            'userApprovalId' => null,
            'userId' => $request->user()->id,
            'created_at' => now(),
        ]);

        return responseCreate();
    }

    function detail(Request $request)
    {
        $expense = DB::table('expenses')
            ->select(
                'expenses.transactionDate',
                'expenses.referenceNo',
                'expenses.vendorId',
                'vendorFinances.vendorName',
                'expenses.locationId',
                'location.locationName as branchName',
                DB::raw("TRIM(expenses.subTotal)+0 as subTotal"),
                DB::raw("TRIM(expenses.tax)+0 as tax"),
                DB::raw("TRIM(expenses.pph)+0 as pph"),
                DB::raw("TRIM(expenses.grandTotal)+0 as grandTotal"),
                'expenses.categoryId',
                'categoryFinances.categoryName',
                'expenses.expenseTypeId',
                'expenseTypeFinances.expenseType as expenseTypeName',
                'expenses.departmentId',
                'departmentFinances.departmentName',
                'expenses.paymentStatusId',
                'paymentStatusFinances.paymentStatus as paymentStatusName',
                'expenses.dueDate',
                'expenses.paymentMethodId',
                'paymentMethodFinances.paymentMethod as paymentMethodName',
                'expenses.description',
                'expenses.realImageName',
                'expenses.imagePath',
                'expenses.created_at',
            )
            ->join('vendorFinances', 'expenses.vendorId', '=', 'vendorFinances.id')
            ->join('location', 'expenses.locationId', '=', 'location.id')
            ->join('categoryFinances', 'expenses.categoryId', '=', 'categoryFinances.id')
            ->join('expenseTypeFinances', 'expenses.expenseTypeId', '=', 'expenseTypeFinances.id')
            ->join('departmentFinances', 'expenses.departmentId', '=', 'departmentFinances.id')
            ->join('paymentMethodFinances', 'expenses.paymentMethodId', '=', 'paymentMethodFinances.id')
            ->join('paymentStatusFinances', 'expenses.paymentStatusId', '=', 'paymentStatusFinances.id')
            ->where('expenses.id', $request->id)
            ->where('expenses.isDeleted', false)
            ->first();

        if (!$expense) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);
        }

        return response()->json([
            'message' => 'Expense Detail',
            'data' => $expense
        ]);
    }

    function approval(Request $request)
    {
        $expense = expense::find($request->id);

        if (!$expense) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);
        }

        $validate = Validator::make($request->all(), [
            'statusApproval' => 'required|in:Approved,Rejected',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $expense->statusApproval = $request->statusApproval;
        $expense->userApprovalId = $request->user()->id;
        $expense->approvalAt = now();
        $expense->save();

        return responseUpdate();
    }

    function export(Request $request)
    {
        $data = DB::table('expenses as e')
            ->join('users as u', 'e.userId', 'u.id')
            ->join('categoryFinances as cf', 'e.categoryId', 'cf.id')
            ->join('vendorFinances as vf', 'e.vendorId', 'vf.id')
            ->join('location as l', 'e.locationId', 'l.id')
            ->join('paymentStatusFinances as ps', 'e.paymentStatusId', 'ps.id')
            ->leftJoin('users as ua', 'e.userApprovalId', 'ua.id')
            ->select(
                'e.id',
                'e.referenceNo',
                'e.transactionDate',
                'cf.categoryName',
                'vf.vendorName',
                'l.locationName',
                'e.paymentStatusId',
                'ps.paymentStatus as paymentStatusName',
                DB::raw("TRIM(e.grandTotal)+0 as totalAmount"),
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(e.updated_at, '%d/%m/%Y') as createdAt"),
                'ua.firstName as approvedBy',
                DB::raw("DATE_FORMAT(e.approvalAt, '%d/%m/%Y') as approvalDate"),
            )
            ->where('e.isDeleted', '=', 0)
            ->where('e.statusApproval', '=', $request->statusApproval ? $request->statusApproval : 'Pending')
            ->get();

        $spreadsheet = IOFactory::load(public_path() . '/template/' . 'Template_Export_Expenses.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $row - 1);
            $sheet->setCellValue("B{$row}", $item->referenceNo);
            $sheet->setCellValue("C{$row}", $item->transactionDate);
            $sheet->setCellValue("D{$row}", $item->categoryName);
            $sheet->setCellValue("E{$row}", $item->vendorName);
            $sheet->setCellValue("F{$row}", $item->locationName);
            $sheet->setCellValue("G{$row}", $item->paymentStatusName);
            $sheet->setCellValue("H{$row}", $item->totalAmount);
            $sheet->setCellValue("I{$row}", $item->createdBy);
            $sheet->setCellValue("J{$row}", $item->createdAt);
            $sheet->setCellValue("K{$row}", $item->approvedBy);
            $sheet->setCellValue("L{$row}", $item->approvalDate);

            $row++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Expenses.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Expenses.xlsx"',
        ]);
    }

    function delete(Request $request)
    {
        if (!$request->id) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['There is no any Data to delete!'],
            ], 422);
        }

        foreach ($request->id as $va) {
            $res = expense::find($va);

            if (!$res) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Expense not found!'],
                ], 422);
            }
        }

        foreach ($request->id as $va) {
            $expense = expense::find($va);
            $expense->isDeleted = true;
            $expense->deletedBy = $request->user()->name;
            $expense->deletedAt = now();
            $expense->save();
        }

        return responseDelete();
    }
}
