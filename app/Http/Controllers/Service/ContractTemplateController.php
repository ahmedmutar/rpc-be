<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\contract_template;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractTemplateController extends Controller
{
    public function index(Request $request)
    {

        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $data = DB::table('contract_templates as ct')
            ->join('users as u', 'ct.userId', 'u.id')
            ->join('serviceCategory as sc', 'ct.category_id', 'sc.id')
            ->select(
                'ct.id',
                'ct.title as title',
                'ct.category_id as categoryId',
                'sc.categoryName',
                'ct.status',
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(ct.updated_at, '%d/%m/%Y') as createdAt")
            )
            ->where('ct.isDeleted', '=', 0);

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
                $data = $data->orderBy('ct.updated_at', $request->orderValue);
            } else {
                $data = $data->orderBy($request->orderColumn, $request->orderValue);
            }
        } else {
            $data = $data->orderBy('ct.updated_at', 'desc');
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

    public function create(Request $request)
    {
        $data = new contract_template();

        $data->title = $request->title;
        $data->raw_content = $request->raw_content;
        $data->category_id = $request->category_id;
        $data->status = $request->status;
        $data->version = $request->version;

        $data->userId = auth()->user()->id;

        if ($data->save()) {
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
        $data->category_id = $request->category_id;
        $data->status = $request->status;
        $data->version = $request->version;

        $data->userUpdateId = auth()->user()->id;

        if ($data->save()) {
            return responseUpdate();
        } else {
            return response()->json([
                'message' => 'Failed to update Contract Template'
            ], 500);
        }
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
