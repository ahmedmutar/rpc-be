<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\category_contact_templates;
use App\Models\category_contract_templates;
use App\Models\contract_template;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractTemplateController extends Controller
{
    public function index(Request $request)
    {

        $itemPerPage = $request->rowPerPage;
        $orderColumn = $request->orderColumn ?: 'ct.updated_at';
        $orderValue = $request->orderValue ?: 'desc';
        $page = $request->goToPage ?: 1;

        $dataQuery = DB::table('contract_templates as ct')
            ->join('users as u', 'ct.userId', 'u.id')
            ->leftJoin('category_contract_templates as tcm', 'ct.id', 'tcm.contractTemplateId')
            ->leftJoin('serviceCategory as sc', 'tcm.categoryId', 'sc.id')
            ->select(
                'ct.id',
                'ct.title',
                'ct.status',
                'u.firstName as createdBy',
                // Gunakan COALESCE agar jika tidak ada kategori, hasilnya string kosong bukan NULL
                DB::raw("COALESCE(GROUP_CONCAT(DISTINCT sc.id), '') as categoryIds"),
                DB::raw("COALESCE(GROUP_CONCAT(DISTINCT sc.categoryName SEPARATOR '|'), '') as categoryNames"),
                DB::raw("DATE_FORMAT(ct.updated_at, '%d/%m/%Y') as createdAt")
            )
            ->where('ct.isDeleted', '=', 0)
            // Pastikan ct.updated_at masuk ke groupBy karena digunakan di select DATE_FORMAT
            ->groupBy('ct.id', 'u.firstName', 'ct.title', 'ct.status', 'ct.updated_at');

        // --- LOGIC SEARCH (Tetap Sama) ---
        if ($request->search) {
            $res = $this->Search($request);
            if ($res) {
                $dataQuery->where(function ($query) use ($res, $request) {
                    foreach ($res as $index => $column) {
                        if ($index === 0) $query->where($column, 'like', '%' . $request->search . '%');
                        else $query->orWhere($column, 'like', '%' . $request->search . '%');
                    }
                });
            } else {
                return response()->json(['totalPagination' => 0, 'data' => []], 200);
            }
        }

        // --- LOGIC SORTING ---
        $dataQuery->orderBy($orderColumn == 'createdAt' ? 'ct.updated_at' : $orderColumn, $orderValue);

        // --- EKSEKUSI DATA ---
        if ($itemPerPage) {
            $offset = ($page - 1) * $itemPerPage;

            // Gunakan get() dulu baru count() dari collection jika groupBy bermasalah pada count query builder
            $allResults = $dataQuery->get();
            $count_data = $allResults->count();

            $results = $allResults->slice($offset >= 0 ? $offset : 0, $itemPerPage);

            $transformedData = $results->map(function ($item) {
                return $this->formatToArray($item);
            })->values(); // Reset index array

            return response()->json([
                'totalPagination' => ceil($count_data / $itemPerPage),
                'data' => $transformedData
            ], 200);
        } else {
            $results = $dataQuery->get();
            $transformedData = $results->map(function ($item) {
                return $this->formatToArray($item);
            });
            return response()->json($transformedData);
        }
    }

    private function formatToArray($item)
    {
        // Ubah categoryIds menjadi array of integers
        $item->categoryIds = $item->categoryIds
            ? array_map('intval', explode(',', $item->categoryIds))
            : [];

        // Ubah categoryNames menjadi array of strings
        $item->categoryNames = $item->categoryNames
            ? explode('|', $item->categoryNames)
            : [];

        return $item;
    }

    public function create(Request $request)
    {
        $data = new contract_template();

        $data->title = $request->title;
        $data->raw_content = $request->raw_content;
        $data->status = $request->status;
        $data->version = $request->version;

        $data->userId = auth()->user()->id;

        if ($data->save()) {

            foreach ($request->categories as $value) {
                $data_detail = new category_contract_templates();
                $data_detail->categoryId = $value;
                $data_detail->contractTemplateId = $data->id;
                $data_detail->userId = auth()->user()->id;
                $data_detail->save();
            }

            return responseCreate();
        } else {
            return response()->json([
                'message' => 'Failed to create Contract Template'
            ], 500);
        }
    }

    public function Search($request)
    {
        $column = ['ct.title', 'sc.categoryName', 'ct.status', 'u.firstName'];

        $res = [];

        foreach ($column as $item) {
            if (str_contains(strtolower($item), strtolower($request->search))) {
                array_push($res, $item);
            }
        }

        return $res;
    }

    public function detail(Request $request)
    {
        $template = contract_template::where('id', $request->id)
            ->where('isDeleted', 0)
            ->select('id', 'title', 'raw_content', 'status', 'version')
            ->first();

        if (!$template) {
            return response()->json([
                'message' => 'Contract Template not found'
            ], 404);
        }

        $categories = DB::table('category_contract_templates as tcm')
            ->join('serviceCategory as sc', 'tcm.categoryId', 'sc.id')
            ->where('tcm.contractTemplateId', $template->id)
            ->where('tcm.isDeleted', 0)
            ->select('sc.id', 'sc.categoryName')
            ->get();

        $template->categories = $categories;

        return response()->json($template, 200);
    }

    public function update(Request $request)
    {
        $data = contract_template::find($request->id);

        if (!$data) {
            return response()->json([
                'message' => 'Contract Template not found'
            ], 404);
        }

        $data->title = $request->title;
        $data->raw_content = $request->raw_content;
        $data->status = $request->status;

        // version from request or auto increment from current version
        if ($request->filled('version')) {
            $data->version = $request->version;
        } else {
            $data->version = $this->createNextVersion($data->version);
        }

        $data->userUpdateId = auth()->user()->id;


        if ($data->save()) {
            // update category relations when categories sent
            if (is_array($request->categories)) {
                // soft delete old relations
                category_contract_templates::where('contractTemplateId', $data->id)
                    ->update(['isDeleted' => true, 'userUpdateId' => auth()->user()->id]);

                // create new relations
                foreach ($request->categories as $value) {
                    $data_detail = new category_contract_templates();
                    $data_detail->categoryId = $value;
                    $data_detail->contractTemplateId = $data->id;
                    $data_detail->userId = auth()->user()->id;
                    $data_detail->save();
                }
            }

            return responseUpdate();
        } else {
            return response()->json([
                'message' => 'Failed to update Contract Template'
            ], 500);
        }
    }

    private function createNextVersion($currentVersion)
    {
        if (!$currentVersion) {
            return '1.0';
        }

        // numeric version like 1, 2
        if (is_numeric($currentVersion)) {
            return (string) (((int) $currentVersion) + 1);
        }

        // dotted version like 1.0, 1.2.3
        if (preg_match('/^([0-9]+(\.[0-9]+)*)$/', $currentVersion)) {
            $parts = explode('.', $currentVersion);
            $last = (int) array_pop($parts);
            $last++;
            $parts[] = (string) $last;
            return implode('.', $parts);
        }

        return $currentVersion;
    }

    public function delete(Request $request)
    {
        foreach ($request->id as $va) {
            $res = contract_template::find($va);

            if (!$res) {
                return responseErrorValidation(['data with id ' . $va .  ' not found!']);
            }
        }

        foreach ($request->id as $va) {

            $cat = contract_template::find($va);
            $cat->DeletedBy = $request->user()->id;
            $cat->isDeleted = true;
            $cat->DeletedAt = Carbon::now();
            $cat->save();

            recentActivity(
                $request->user()->id,
                'Service Contract Template',
                'Delete Contract Template',
                'Deleted contract template "' . $cat->title . '"'
            );
        }

        return responseSuccess($request->id, 'Delete Data Successful!');
    }

    public function export(Request $request)
    {
        $data = contract_template::find($request->id);

        if (!$data) {
            return response()->json([
                'message' => 'Contract Template not found'
            ], 404);
        }

        $fileName = $data->title . '_' . $data->version . '.txt';
        $filePath = storage_path('app/contract_templates/' . $fileName);

        file_put_contents($filePath, $data->raw_content);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    public function getListContract()
    {
        $data = DB::table('contract_templates')
            ->select('id', 'title')
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($data);
    }
}
