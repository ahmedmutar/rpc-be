<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Customer\Customer;
use App\Models\Customer\CustomerPets;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Validator;
use DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TransactionController extends Controller
{
    public function index(Request $request)
    {

        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $data = DB::table('transactions as t')
            ->join('location as l', 'l.id', 't.locationId')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('customerPets as cp', 'cp.id', 't.PetId')
            ->leftjoin('customerGroups as cg', 'cg.id', 'c.customerGroupId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->join('users as uc', 'uc.id', 't.userId')
            ->select(
                't.id',
                't.registrationNo',
                'l.locationName',
                'c.firstName',
                DB::raw("IFNULL(cg.customerGroup,'') as customerGroup"),
                't.serviceCategory',
                DB::raw("IFNULL(t.startDate,'') as startDate"),
                DB::raw("IFNULL(t.endDate,'') as endDate"),
                't.status',
                'u.firstName as picDoctor',
                'uc.firstName as createdBy',
                DB::raw("DATE_FORMAT(t.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->status == 'ongoing') {
            $data = $data->whereNotIn('t.status', ['Selesai', 'Batal']);
        } elseif ($request->status == 'finished') {
            $data = $data->whereIn('t.status', ['Selesai', 'Batal']);
        }

        if (!$request->user()->roleId == 1 || !$request->user()->roleId == 2) {
            $locations = UsersLocation::select('id')->where('usersId', $request->user()->id)->get()->pluck('id')->toArray();
            $data = $data->whereIn('l.id', $locations);
        } else {

            if ($request->locationId) {

                $data = $data->whereIn('l.id', $request->locationId);
            }
        }

        if ($request->customerGroupId) {

            $data = $data->whereIn('cg.id', $request->customerGroupId);
        }

        if ($request->serviceCategories) {

            $data = $data->whereIn('t.serviceCategory', $request->serviceCategories);
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

        $data = $data->orderBy('t.updated_at', 'desc');

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

    private function Search($request)
    {
        $temp_column = null;

        $data = DB::table('transactions as t')
            ->select(
                't.registrationNo'
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('t.registrationNo', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 't.registrationNo';
        }
        //------------------------

        $data = DB::table('transactions as t')
            ->join('customer as c', 'c.id', 't.customerId')
            ->select(
                'c.firstName'
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('c.firstName', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'c.firstName';
        }
        //------------------------

        $data = DB::table('transactions as t')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->select(
                'u.firstName',
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('u.firstName', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'u.firstName';
        }
        //------------------------

        return $temp_column;
    }

    public function create(Request $request)
    {

        if ($request->isNewCustomer == true) {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                //'customerId' => 'nullable|integer',
                'customerName' => 'nullable|string',
                //'registrant' => 'nullable|string',
                //'petId' => 'nullable|integer',
                'petName' => 'required|string',
                'petCategory' => 'required|integer',
                'condition' => 'required|string',
                'petGender' => 'required|string|in:J,B',
                'isSterile' => 'required|bool',
                'serviceCategory' => 'required|string|in:Pet Clinic,Pet Hotel,Pet Salon,Pacak',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        } else {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                'customerId' => 'nullable|integer',
                //'customerName' => 'nullable|string',
                'registrant' => 'nullable|string',
                'petId' => 'nullable|integer',
                //'petName' => 'required|string',
                //'petCategory' => 'required|integer',
                //'condition' => 'required|string',
                //'petGender' => 'required|string|in:J,B',
                //'isSterile' => 'required|bool',
                'serviceCategory' => 'required|string|in:Pet Clinic,Pet Hotel,Pet Salon,Pacak',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        }

        if ($request->serviceCategory == 'Pet Hotel') {
            $validate = Validator::make($request->all(), [
                'startDate' => 'required|date',
                'endDate' => 'required|date',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }

            if ($request->startDate > $request->endDate || $request->startDate == $request->endDate) {
                return responseInvalid(['Start Date must be less than End Date']);
            }
        }

        DB::beginTransaction();
        try {

            if ($request->isNewCustomer == true) {
                $cust = Customer::create(
                    [
                        'firstName' => $request->customerName,
                        'locationId' => $request->locationId,
                        'typeId' => 0,

                        'memberNo' => '',
                        'gender' => '',
                        'joinDate' => Carbon::now(),
                        'createdBy' => $request->user()->id,
                        'userUpdateId' => $request->user()->id
                    ]
                );

                if ($request->isNewPet == true) {
                    $pet = CustomerPets::create(
                        [
                            'customerId' => $cust->id,
                            'petName' => $request->petName,
                            'petCategoryId' => $request->petCategory,
                            'races' => '',
                            'condition' => $request->condition,
                            'color' => '',
                            'petMonth' => $request->month,
                            'petYear' => $request->year,
                            'dateOfBirth' => $request->birthDate,
                            'petGender' => $request->petGender,
                            'isSteril' => $request->isSterile,
                            'createdBy' => $request->user()->id,
                            'userUpdateId' => $request->user()->id
                        ]
                    );
                }
            } else {
                //$locations = UsersLocation::select('id')->where('usersId', $request->user()->id)->get()->pluck('id')->toArray();
                $cust = Customer::select('id', 'isDeleted')->where('id', $request->customerId)->where('isDeleted', 0)->first();

                if (!$cust) {
                    responseInvalid(['Customer is Not Found']);
                }

                if ($request->isNewPet == true) {
                    $pet = CustomerPets::create(
                        [
                            'customerId' => $cust->id,
                            'petName' => $request->petName,
                            'petCategoryId' => $request->petCategory,
                            'races' => '',
                            'condition' => $request->condition,
                            'color' => '',
                            'petMonth' => $request->month,
                            'petYear' => $request->year,
                            'dateOfBirth' => $request->birthDate,
                            'petGender' => $request->petGender,
                            'isSteril' => $request->isSterile,
                            'createdBy' => $request->user()->id,
                            'userUpdateId' => $request->user()->id
                        ]
                    );
                } else {

                    $pet = CustomerPets::select('id', 'isDeleted')->where('id', $request->petId)->where('customerId', $request->customerId)->where('isDeleted', 0)->first();

                    if (!$pet) {
                        return responseInvalid(['Pet is Not Found']);
                    }
                }
            }

            $doctorId = DB::table('users as u')
                ->join('usersLocation as ul', 'u.id', 'ul.usersId')
                ->join('jobTitle as j', 'j.id', 'u.jobTitleId')
                ->select(
                    'u.id',
                )->where('j.id', '=', 17)   //id job title dokter hewan
                ->where('u.isDeleted', '=', 0)
                ->where('u.id', '=', $request->doctorId)
                ->first();

            if (!$doctorId) {
                return responseInvalid(['Doctor is Not Found']);
            }

            $trx = Transaction::where('locationId', $request->locationId)->count();

            $regisNo = 'RPC.TRX.' . $request->locationId . '.' . str_pad($trx + 1, 8, 0, STR_PAD_LEFT);

            $tran = Transaction::create([
                'registrationNo' => $regisNo,
                'status' => 'Menunggu Dokter',
                'isNewCustomer' => $request->isNewCustomer,
                'isNewPet' => $request->isNewPet,
                'locationId' => $request->locationId,
                'customerId' => $cust->id,
                'petId' => $pet->id,
                'registrant' => $request->registrant,
                'serviceCategory' => $request->serviceCategory,
                'startDate' => $request->startDate,
                'endDate' => $request->endDate,
                'doctorId' => $request->doctorId,
                'note' => $request->note,
                'userId' => $request->user()->id,
            ]);

            transactionLog($tran->id, 'New Transaction', '', $request->user()->id);

            DB::commit();
            return responseCreate();
        } catch (Exception $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function detail(Request $request)
    {
        $detail = DB::table('transactions as t')
            ->join('location as l', 'l.id', 't.locationId')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('customerPets as cp', 'cp.id', 't.PetId')
            ->join('petCategory as pc', 'pc.id', 'cp.petCategoryId')
            ->leftjoin('customerGroups as cg', 'cg.id', 'c.customerGroupId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->join('users as uc', 'uc.id', 't.userId')
            ->select(
                't.id',
                't.registrationNo',
                't.isNewCustomer',
                't.registrant',
                'l.id as locationId',
                'l.locationName',
                'c.id as customerId',
                'c.firstName as customerName',
                DB::raw("IFNULL(cg.customerGroup,'') as customerGroup"),
                't.serviceCategory',
                DB::raw("IFNULL(t.startDate,'') as startDate"),
                DB::raw("IFNULL(t.endDate,'') as endDate"),
                't.status',
                'u.id as doctorId',
                'u.firstName as picDoctor',
                't.note',

                'cp.id as petId',
                'cp.petName',
                'cp.petCategoryId',
                'pc.petCategoryName',
                'cp.condition',
                'cp.petGender',
                'cp.isSteril as petSterile',
                'cp.petMonth',
                'cp.petYear',
                'cp.dateOfBirth',

                'uc.firstName as createdBy',
                DB::raw("DATE_FORMAT(t.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('t.id', '=', $request->id)
            ->first();

        $log = DB::table('transactionLogs as tl')
            ->join('transactions as t', 't.id', 'tl.transactionId')
            ->join('users as u', 'u.id', 'tl.userId')
            ->select(
                'tl.id',
                'tl.activity',
                'tl.remark',
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tl.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('tl.transactionId', '=', $request->id)
            ->get();

        $data = ['detail' => $detail, 'transactionLogs' => $log];

        return response()->json($data, 200);
    }

    public function update(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        if ($request->isNewCustomer == true) {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                //'customerId' => 'nullable|integer',
                'customerName' => 'nullable|string',
                //'registrant' => 'nullable|string',
                //'petId' => 'nullable|integer',
                'petName' => 'required|string',
                'petCategory' => 'required|integer',
                'condition' => 'required|string',
                'petGender' => 'required|string|in:J,B',
                'isSterile' => 'required|bool',
                'serviceCategory' => 'required|string|in:Pet Clinic,Pet Hotel,Pet Salon,Pacak',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        } else {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                'customerId' => 'nullable|integer',
                //'customerName' => 'nullable|string',
                'registrant' => 'nullable|string',
                'petId' => 'nullable|integer',
                //'petName' => 'required|string',
                //'petCategory' => 'required|integer',
                //'condition' => 'required|string',
                //'petGender' => 'required|string|in:J,B',
                //'isSterile' => 'required|bool',
                'serviceCategory' => 'required|string|in:Pet Clinic,Pet Hotel,Pet Salon,Pacak',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        }

        if ($request->serviceCategory == 'Pet Hotel') {
            $validate = Validator::make($request->all(), [
                'startDate' => 'required|date',
                'endDate' => 'required|date',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }

            if ($request->startDate > $request->endDate || $request->startDate == $request->endDate) {
                return responseInvalid(['Start Date must be less than End Date']);
            }
        }

        DB::beginTransaction();

        try {
            Transaction::updateOrCreate(
                ['id' => $request->id],
                [
                    'registrationNo' => $request->registrationNo,
                    'isNewCustomer' => $request->isNewCustomer,
                    'isNewPet' => $request->isNewPet,
                    'locationId' => $request->locationId,
                    'customerId' => $request->customerId,
                    'petId' => $request->petId,
                    'registrant' => $request->registrant,
                    'serviceCategory' => $request->serviceCategory,
                    'startDate' => $request->startDate,
                    'endDate' => $request->endDate,
                    'doctorId' => $request->doctorId,
                    'note' => $request->note,
                    'userUpdatedId' => $request->user()->id,
                ]
            );

            transactionLog($request->id, 'Update Transaction', '', $request->user()->id);

            DB::commit();
            return responseUpdate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function delete(Request $request)
    {

        foreach ($request->id as $va) {
            $res = Transaction::find($va);

            if (!$res) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['There is any Data not found!'],
                ], 422);
            }
        }

        foreach ($request->id as $va) {

            $tran = Transaction::find($va);

            $tran->DeletedBy = $request->user()->id;
            $tran->isDeleted = true;
            $tran->DeletedAt = Carbon::now();
            $tran->save();

            transactionLog($va, 'Transaction Deleted', '', $request->user()->id);
        }

        return responseDelete();
    }

    public function export(Request $request)
    {

        $data = DB::table('transactions as t')
            ->join('location as l', 'l.id', 't.locationId')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('customerPets as cp', 'cp.id', 't.PetId')
            ->leftjoin('customerGroups as cg', 'cg.id', 'c.customerGroupId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->join('users as uc', 'uc.id', 't.userId')
            ->select(
                't.registrationNo',
                'l.locationName',
                'c.firstName',
                DB::raw("IFNULL(cg.customerGroup,'') as customerGroup"),
                't.serviceCategory',
                DB::raw("IFNULL(t.startDate,'') as startDate"),
                DB::raw("IFNULL(t.endDate,'') as endDate"),
                't.status',
                'u.firstName as picDoctor',
                'uc.firstName as createdBy',
                DB::raw("DATE_FORMAT(t.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->status == 'ongoing') {
            $data = $data->whereNotIn('t.status', ['Selesai', 'Batal']);
        } elseif ($request->status == 'finished') {
            $data = $data->whereIn('t.status', ['Selesai', 'Batal']);
        }

        $data = $data->orderBy('t.updated_at', 'desc')->get();

        $spreadsheet = IOFactory::load(public_path() . '/template/transaction/' . 'Template_Export_Transaction.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $row - 1);
            $sheet->setCellValue("B{$row}", $item->registrationNo);
            $sheet->setCellValue("C{$row}", $item->locationName);
            $sheet->setCellValue("D{$row}", $item->firstName);
            $sheet->setCellValue("E{$row}", $item->customerGroup);
            $sheet->setCellValue("F{$row}", $item->serviceCategory);
            $sheet->setCellValue("G{$row}", $item->startDate);
            $sheet->setCellValue("H{$row}", $item->endDate);
            $sheet->setCellValue("I{$row}", $item->status);
            $sheet->setCellValue("J{$row}", $item->picDoctor);
            $sheet->setCellValue("K{$row}", $item->createdBy);
            $sheet->setCellValue("L{$row}", $item->createdAt);

            $row++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Transaction.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Transaction.xlsx"',
        ]);
    }

    public function TransactionCategory()
    {
        $data = ['Pet Clinic', 'Pet Hotel', 'Pet Salon', 'Pacak'];

        return responseList($data);
    }
}
