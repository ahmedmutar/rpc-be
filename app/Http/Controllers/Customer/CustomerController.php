<?php

namespace App\Http\Controllers\Customer;

use DB;
use File;
use Validator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\CustomerGroups;
use App\Models\Customer\Customer;
use App\Models\Location\Location;
use App\Http\Controllers\Controller;
use App\Models\Customer\PetCategory;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Customer\CustomerPets;
use App\Models\Customer\TitleCustomer;
use App\Models\Customer\CustomerEmails;
use App\Models\Customer\CustomerImages;
use App\Models\Customer\SourceCustomer;
use App\Models\Customer\TypeIdCustomer;
use App\Exports\Customer\exportCustomer;
use App\Models\Customer\CustomerReminder;
use App\Models\Customer\CustomerAddresses;
use App\Models\Customer\ReferenceCustomer;
use App\Models\Customer\CustomerMessengers;
use App\Models\Customer\CustomerOccupation;
use App\Models\Customer\CustomerTelephones;

class CustomerController extends Controller
{
    public function index()
    {
        $months = collect(range(1, 12))->map(function ($month) {
            return Carbon::create()->month($month)->format('M');
        });

        $data = [
            'chartsCustomerGrowth' => [
                'series' => [
                    [
                        'name' => 'Previous',
                        'data' => [10, 15, 12, 18, 20, 25, 22, 30, 28, 26, 24, 29],
                    ],
                    [
                        'name' => 'Current',
                        'data' => [12, 18, 14, 20, 22, 30, 25, 35, 32, 30, 27, 33],
                    ],
                ],
                'categories' => $months,
            ],

            'chartsTotalCustomer' => [
                'series' => [
                    [
                        'name' => 'Previous',
                        'data' => [10, 15, 12, 18, 20, 25, 22, 30, 28, 26, 24, 29],
                    ],
                    [
                        'name' => 'Current',
                        'data' => [12, 18, 14, 20, 22, 30, 25, 35, 32, 30, 27, 33],
                    ],
                ],
                'categories' => $months,
            ],

            'newCustomer' => [
                'percentage' => '59.3',
                'total' => '42000',
                'isLoss' => 0
            ],
            'feedback' => [
                'percentage' => '59.3',
                'total' => '42000',
                'isLoss' => 0
            ],
            'supportRequested' => [
                'percentage' => '59.3',
                'total' => '42000',
                'isLoss' => 0
            ],


        ];

        return response()->json($data);
    }

    public function insertTypeIdCustomer(Request $request)
    {
        $request->validate([
            'typeName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = TypeIdCustomer::where([
                ['typeName', '=', $request->typeName],
                ['isActive', '=', 1]
            ])->first();

            if ($checkIfValueExits != null) {

                return responseInvalid(['Type name already exists, please choose another name']);
            } else {

                $TypeIdCustomer = new TypeIdCustomer();
                $TypeIdCustomer->typeName = $request->typeName;
                $TypeIdCustomer->isActive = 1;
                $TypeIdCustomer->created_at = now();
                $TypeIdCustomer->updated_at = now();
                $TypeIdCustomer->save();

                DB::commit();

                return responseCreate();
            }
        } catch (Exception $e) {

            DB::rollback();

            return responseInvalid([$e]);
        }
    }

    public function getTypeIdCustomer()
    {

        try {

            $getTypeId = TypeIdCustomer::select(
                'id as typeId',
                'typeName as typeName',
            )->where([
                ['isActive', '=', 1],
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return responseList($getTypeId);
        } catch (Exception $e) {

            return responseInvalid([$e]);
        }
    }

    public function indexCustomer(Request $request)
    {
        if (!checkAccessIndex('customer-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        try {

            $defaultRowPerPage = 5;
            $defaultOrderBy = "asc";

            $data = DB::table('customer as a')
                ->leftjoin(
                    DB::raw('(
					select count(id)as jumlah,customerId from `customerPets` where isDeleted=0
					GROUP by customerId
				) as b'),
                    function ($join) {
                        $join->on('b.customerId', '=', 'a.id');
                    }
                )
                //->leftjoin('customerAddresses as c', 'c.customerId', '=', 'a.id')
                ->leftjoin('location as d', 'd.id', '=', 'a.locationId')
                ->leftjoin('customerGroups as cg', 'cg.id', '=', 'a.customerGroupId')
                ->join('users as u', 'a.createdBy', 'u.id')
                //->leftjoin('customerTelephones as e', 'e.customerId', '=', 'a.id')
                //->leftjoin('customerEmails as f', 'f.customerId', '=', 'a.id')
                ->select(
                    'a.id as id',
                    'a.memberNo',
                    DB::raw("IFNULL ((cg.customerGroup),'') as customerGroup"),
                    DB::raw("CONCAT_WS(' ', a.firstName, a.middleName, a.lastName) as customerName"),
                    DB::raw("IFNULL ((b.jumlah),0) as totalPet"),
                    'd.locationName as location',
                    'a.locationId as locationId',
                    DB::raw("CASE WHEN (select count(*) from customerTelephones ct where ct.customerId=a.id and ct.usage='Utama' and ct.isDeleted=0) = 0 then '' else
                    (select ct.phoneNumber from customerTelephones ct where ct.customerId=a.id and ct.usage='Utama' and ct.isDeleted=0 limit 1) END as phoneNumber"),

                    DB::raw("CASE WHEN (select count(*) from customerTelephones ct where ct.customerId=a.id and ct.type='Whatsapp' and ct.usage='Utama' and ct.isDeleted=0) > 0 THEN true ELSE false END AS isWhatsapp"),

                    DB::raw("CASE WHEN (select count(*) from customerEmails ce where ce.customerId=a.id and ce.usage='Utama' and ce.isDeleted=0) = 0 THEN '' ELSE
                    (select ce.email from customerEmails ce where ce.customerId=a.id and ce.usage='Utama' and ce.isDeleted=0 LIMIT 1) END AS emailAddress"),

                    // DB::raw("CONCAT(e.phoneNumber) as phoneNumber"),
                    // DB::raw("CASE WHEN lower(e.type)='whatshapp' then true else false end as isWhatsapp"),
                    //'f.email as emailAddress',
                    'u.firstName as createdBy',
                    DB::raw('a.created_at as createdAt'),
                    'a.updated_at'
                )
                ->where([
                    ['a.isDeleted', '=', '0'],
                    //['c.isDeleted', '=', '0'],
                    //['c.isPrimary', '=', '1'],
                    //['d.isDeleted', '=', '0'],
                    // ['e.isDeleted', '=', '0'],
                    // ['e.usage', '=', 'Utama'],
                    // ['f.isDeleted', '=', '0'],
                    // ['f.usage', '=', 'Utama'],
                ]);

            if ($request->locationId) {
                $data = $data->whereIn('a.locationid', $request->locationId);
            }

            if ($request->customerGroupId) {
                $data = $data->whereIn('a.customerGroupId', $request->customerGroupId);
            }


            $data = DB::table($data)
                ->select(
                    'id',
                    'memberNo',
                    'customerGroup',
                    'customerName',
                    'totalPet',
                    'location',
                    'phoneNumber',
                    'isWhatsapp',
                    'emailAddress',
                    'createdBy',
                    'createdAt',
                    'updated_at'
                );

            if ($request->search) {

                $res = $this->Search($request);

                if ($res) {

                    if ($res == "memberNo") {

                        $data = $data->where('memberNo', 'like', '%' . $request->search . '%');
                    } else if ($res == "customerName") {

                        $data = $data->where('customerName', 'like', '%' . $request->search . '%');
                    } else if ($res == "totalPet") {

                        $data = $data->where('totalPet', 'like', '%' . $request->search . '%');
                    } else if ($res == "location") {

                        $data = $data->where('location', 'like', '%' . $request->search . '%');
                    } else if ($res == "phoneNumber") {

                        $data = $data->where('phoneNumber', 'like', '%' . $request->search . '%');
                    } else if ($res == "isWhatsapp") {

                        $data = $data->where('isWhatsapp', 'like', '%' . $request->search . '%');
                    } else if ($res == "emailAddress") {

                        $data = $data->where('emailAddress', 'like', '%' . $request->search . '%');
                    } else if ($res == "createdBy") {

                        $data = $data->where('createdBy', 'like', '%' . $request->search . '%');
                    } else if ($res == "createdAt") {

                        $data = $data->where('createdAt', 'like', '%' . $request->search . '%');
                    } else {

                        $data = [];
                        return response()->json([
                            'totalPagination' => 0,
                            'data' => $data
                        ], 200);
                    }
                }
            }


            if ($request->orderValue) {

                $defaultOrderBy = $request->orderValue;
            }


            $checkOrder = null;
            if ($request->orderColumn && $defaultOrderBy) {

                $listOrder = array(
                    'id',
                    'memberNo',
                    'customerGroup',
                    'customerName',
                    'totalPet',
                    'location',
                    'phoneNumber',
                    'isWhatsapp',
                    'emailAddress',
                    'createdBy',
                    'createdAt',
                    'updated_at'
                );

                if (!in_array($request->orderColumn, $listOrder)) {

                    return response()->json([
                        'message' => 'failed',
                        'errors' => 'Please try different order column',
                        'orderColumn' => $listOrder,
                    ]);
                }

                if (strtolower($defaultOrderBy) != "asc" && strtolower($defaultOrderBy) != "desc") {
                    return response()->json([
                        'message' => 'failed',
                        'errors' => 'order value must Ascending: ASC or Descending: DESC ',
                    ]);
                }


                $checkOrder = true;
            }


            $data = DB::table($data)
                ->select('id', 'memberNo', 'customerGroup', 'customerName', 'totalPet', 'location', 'phoneNumber', 'isWhatsapp', 'emailAddress', 'createdBy', 'createdAt');

            if ($checkOrder) {
                $data = $data->orderBy($request->orderColumn, $defaultOrderBy);
            }

            $data = $data->orderBy('updated_at', 'desc');


            if ($request->rowPerPage > 0) {
                $defaultRowPerPage = $request->rowPerPage;
            }

            $goToPage = $request->goToPage;

            $offset = ($goToPage - 1) * $defaultRowPerPage;

            $count_data = $data->count();
            $count_result = $count_data - $offset;

            if ($count_result < 0) {
                $data = $data->offset(0)->limit($defaultRowPerPage)->get();
            } else {
                $data = $data->offset($offset)->limit($defaultRowPerPage)->get();
            }

            $total_paging = $count_data / $defaultRowPerPage;

            return response()->json(['totalPagination' => ceil($total_paging), 'data' => $data], 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }



    public function exportCustomer(Request $request)
    {
        if (!checkAccessIndex('customer-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $tmp = "";
        $fileName = "";
        $date = Carbon::now()->format('d-m-Y');
        $rolesIndex = roleStaffLeave($request->user()->id);

        try {

            if ($rolesIndex == 1 || $rolesIndex == 6) {

                if ($request->locationId) {

                    $location = DB::table('location')
                        ->select('locationName')
                        ->whereIn('id', $request->locationId)
                        ->get();

                    if ($location) {

                        foreach ($location as $key) {
                            $tmp = $tmp . (string) $key->locationName . ",";
                        }
                    }
                    $tmp = rtrim($tmp, ", ");
                }

                if ($tmp == "") {
                    $fileName = "Customer " . ucfirst($request->status) . ' ' . $date . ".xlsx";
                } else {
                    $fileName = "Customer " .  ucfirst($request->status) . ' ' . $tmp . " " . $date . ".xlsx";
                }


                return Excel::download(
                    new exportCustomer(
                        $request->orderValue,
                        $request->orderColumn,
                        $request->locationId,
                    ),
                    $fileName
                );
            } else {

                return response()->json([
                    'message' => 'failed',
                    'errors' => 'Your role not autorize to access this feature',
                ], 422);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ]);
        }
    }


    private function buildCustomerBaseQuery($request)
    {
        $query = DB::table('customer as a')
            ->leftjoin(
                DB::raw('(select count(id) as jumlah, customerId from `customerPets` where isDeleted=0 GROUP by customerId) as b'),
                fn($join) => $join->on('b.customerId', '=', 'a.id')
            )
            ->leftjoin('location as d', 'd.id', '=', 'a.locationId')
            ->leftjoin('customerGroups as cg', 'cg.id', '=', 'a.customerGroupId')
            ->join('users as u', 'a.createdBy', 'u.id')
            ->select(
                'a.id as id',
                'a.memberNo',
                DB::raw("IFNULL(cg.customerGroup, '') as customerGroup"),
                DB::raw("CONCAT_WS(' ', a.firstName, a.middleName, a.lastName) as customerName"),
                DB::raw("IFNULL(b.jumlah, 0) as totalPet"),
                'd.locationName as location',
                'a.locationId as locationId',
                DB::raw("CASE WHEN (select count(*) from customerTelephones ct where ct.customerId=a.id and ct.usage='Utama' and ct.isDeleted=0) = 0 then '' else
                    (select ct.phoneNumber from customerTelephones ct where ct.customerId=a.id and ct.usage='Utama' and ct.isDeleted=0 limit 1) END as phoneNumber"),
                DB::raw("CASE WHEN (select count(*) from customerTelephones ct where ct.customerId=a.id and ct.type='Whatsapp' and ct.usage='Utama' and ct.isDeleted=0) > 0 THEN true ELSE false END AS isWhatsapp"),
                DB::raw("CASE WHEN (select count(*) from customerEmails ce where ce.customerId=a.id and ce.usage='Utama' and ce.isDeleted=0) = 0 THEN '' ELSE
                    (select ce.email from customerEmails ce where ce.customerId=a.id and ce.usage='Utama' and ce.isDeleted=0 LIMIT 1) END AS emailAddress"),
                'u.firstName as createdBy',
                DB::raw('a.created_at as createdAt'),
                'a.updated_at'
            )
            ->where('a.isDeleted', '=', '0');

        if ($request->locationId) {
            $query->whereIn('a.locationId', $request->locationId);
        }

        return $query;
    }

    private function Search($request)
    {
        $searchableColumns = [
            'customerName', 'memberNo', 'totalPet', 'location',
            'phoneNumber', 'isWhatsapp', 'emailAddress', 'createdBy', 'createdAt',
        ];

        foreach ($searchableColumns as $column) {
            $exists = DB::table($this->buildCustomerBaseQuery($request))
                ->select('id', 'memberNo', 'customerName', 'totalPet', 'location', 'locationId', 'phoneNumber', 'isWhatsapp', 'emailAddress', 'createdBy', 'createdAt', 'updated_at')
                ->where($column, 'like', '%' . $request->search . '%')
                ->exists();

            if ($exists) {
                return $column;
            }
        }

        return 'empty';
    }



    public function createCustomer(Request $request)
    {

        if (!checkAccessModify('customer-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        DB::beginTransaction();

        try {

            $validate = Validator::make(
                $request->all(),
                [
                    'memberNo' => 'nullable|string|max:100',
                    'firstName' => 'required|string|max:100',
                    'middleName' => 'nullable|string|max:100',
                    'lastName' => 'nullable|string|max:100',
                    'titleCustomerId' => 'nullable|integer',
                    'nickName' => 'nullable|string|max:100',
                    'customerGroupId' => 'nullable|integer',
                    'locationId' => 'required|integer',
                    'notes' => 'nullable|string',
                    'colorType' => 'nullable|string',
                    'joinDate' => 'required|date',
                    'typeId' => 'nullable|integer',
                    'numberId' => 'nullable|string|max:50',
                    'gender' => 'nullable|string',
                    'occupationId' => 'nullable|integer',
                    'birthDate' => 'nullable|date',
                    'referenceCustomerId' => 'nullable|integer',
                    'isReminderBooking' => 'integer|nullable',
                    'isReminderPayment' => 'integer|nullable',

                ]
            );


            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }


            $data_item_pet = [];
            $inputUnitReal = [];

            if ($request->customerPets) {

                $arrayCustomerPet = json_decode($request->customerPets, true);

                $messageCustomerPet = [
                    'petName.required' => 'Pet name on tab Customer Pet is required',
                    'petCategoryId.required' => 'Category Pet tab Customer Pet is required',
                    'races.required' => 'Pet Races in tab Customer Pet is required',
                    'condition.required' => 'Condition on tab Customer Pet is required',
                    'petGender.required' => 'Pet Gender on tab Cutomer Pet is required',
                    'isSteril.required' => 'Pet Steril  on tab Cutomer Pet is required',

                ];



                foreach ($arrayCustomerPet as $val) {

                    if ($val['command'] != "del") {
                        array_push($inputUnitReal, $val);
                    }
                }

                foreach ($inputUnitReal as $key) {

                    $validateDetail = Validator::make(
                        $key,
                        [
                            'petName' => 'required|string|max:100',
                            'petCategoryId' => 'required:integer',
                            'race' => 'nullable|string|max:100',
                            'condition' => 'required|string|max:100',
                            'petGender' => 'required|in:J,B',
                            'isSteril' => 'required|in:1,0',
                            'remark' => 'nullable|string',

                        ],
                        $messageCustomerPet
                    );



                    if ($validateDetail->fails()) {

                        $errors = $validateDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_item_pet))) {
                                array_push($data_item_pet, $checkisu);
                            }
                        }
                    }
                }

                if ($data_item_pet) {

                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_item_pet,
                    ], 422);
                }
            }


            $data_reminder_booking = [];

            if ($request->reminderBooking) {

                $arrayReminderBooking = json_decode($request->reminderBooking, true);

                $messageReminderBooking = [
                    'sourceId.required' => 'Source on tab Reminder and on Reminder Booking is required',
                    'unit.required' => 'Unit on tab Reminder and on Reminder Booking is required',
                    'timing.required' => 'Timing on tab Reminder and on Reminder Booking is required',
                    'status.required' => 'Status Reminder Booking is required',
                ];


                foreach ($arrayReminderBooking as $key) {

                    $validateReminderBooking = Validator::make(
                        $key,
                        [
                            'sourceId' => 'required|integer',
                            'unit' => 'required|integer',
                            'timing' => 'required',
                            'status' => 'required',
                        ],
                        $messageReminderBooking
                    );


                    if ($validateReminderBooking->fails()) {

                        $errors = $validateReminderBooking->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_reminder_booking))) {
                                array_push($data_reminder_booking, $checkisu);
                            }
                        }
                    }
                }

                if ($data_reminder_booking) {

                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_reminder_booking,
                    ], 422);
                }
            }



            $data_reminder_payment = [];

            if ($request->reminderPayment) {

                $arrayReminderPayment = json_decode($request->reminderPayment, true);

                $messageReminderPayment = [
                    'sourceId.required' => 'Source on tab Reminder and on Reminder Payment is required',
                    'unit.required' => 'Unit on tab Reminder and on Reminder Payment is required',
                    'timing.required' => 'Timing on tab Reminder and on Reminder Payment is required',
                    'status.required' => 'Status Reminder Payment is required',
                ];



                foreach ($arrayReminderPayment as $key) {

                    $validateReminderPayment = Validator::make(
                        $key,
                        [
                            'sourceId' => 'required|integer',
                            'unit' => 'required|integer',
                            'timing' => 'required',
                            'status' => 'required',
                        ],
                        $messageReminderPayment
                    );

                    if ($validateReminderPayment->fails()) {

                        $errors = $validateReminderPayment->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_reminder_payment))) {
                                array_push($data_reminder_payment, $checkisu);
                            }
                        }
                    }
                }

                if ($data_reminder_payment) {

                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_reminder_payment,
                    ], 422);
                }
            }


            $data_reminder_late_payment = [];

            if ($request->reminderLatePayment) {

                $reminderLatePayment = json_decode($request->reminderLatePayment, true);

                $messageReminderLatePayment = [
                    'sourceId.required' => 'Source on tab Reminder and on Reminder Late Payment is required',
                    'unit.required' => 'Unit on tab Reminder and on Reminder Late Payment is required',
                    'timing.required' => 'Time Date on tab Reminder and on Reminder Late Payment is required',
                    'status.required' => 'Status Reminder Late Payment is required',
                ];


                foreach ($reminderLatePayment as $key) {

                    $validateReminderLatePayment = Validator::make(
                        $key,
                        [
                            'sourceId' => 'required|integer',
                            'unit' => 'required|integer',
                            'timing' => 'required',
                            'status' => 'required',
                        ],
                        $messageReminderLatePayment
                    );

                    if ($validateReminderLatePayment->fails()) {

                        $errors = $validateReminderLatePayment->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_reminder_late_payment))) {
                                array_push($data_reminder_late_payment, $checkisu);
                            }
                        }
                    }
                }

                if ($data_reminder_late_payment) {

                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_reminder_late_payment,
                    ], 422);
                }
            }


            $data_item = [];

            $arrayDetailAddress = json_decode($request->detailAddresses, true);

            if (count($arrayDetailAddress) > 0) {

                $primaryCount = 0;
                foreach ($arrayDetailAddress as $item) {
                    if (isset($item['isPrimary']) && $item['isPrimary'] == 1) {
                        $primaryCount++;
                    }
                }
                if ($primaryCount == 0) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address must have at least 1 primary address',
                    ], 422);
                } elseif ($primaryCount > 1) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address have 2 primary address, please check again',
                    ], 422);
                }

                $messageAddress = [
                    'addressName.required' => 'Address name on tab Address is required',
                    'provinceCode.required' => 'Province code on tab Address is required',
                    'cityCode.required' => 'City code on tab Address is required',
                    'country.required' => 'Country on tab Address is required',
                ];

                foreach ($arrayDetailAddress as $key) {

                    $validateDetail = Validator::make(
                        $key,
                        [
                            'addressName' => 'required',
                            'provinceCode' => 'required',
                            'cityCode' => 'required',
                            'country' => 'required',
                        ],
                        $messageAddress
                    );

                    if ($validateDetail->fails()) {

                        $errors = $validateDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_item))) {
                                array_push($data_item, $checkisu);
                            }
                        }
                    }
                }

                if ($data_item) {

                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_item,
                    ], 422);
                }
            }

            $data_error_telephone = [];

            $arraytelephone = json_decode($request->telephones, true);

            if (count($arraytelephone) > 0) {

                $messagePhone = [
                    'phoneNumber.required' => 'Phone Number on tab telephone is required',
                    'type.required' => 'Type on tab telephone is required',
                    'usage.required' => 'Usage on tab telephone is required',
                ];


                $primaryTelephone = 0;


                if (!empty($arrayemail)) {
                    foreach ($arraytelephone as $item) {
                        if (strtolower($item['usage']) == "utama") {
                            $primaryTelephone++;
                        }
                    }

                    if ($primaryTelephone == 0) {

                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' =>  'Telephone must have at least 1 primary number',
                        ], 422);
                    } elseif ($primaryTelephone > 1) {

                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' =>  'Telephone have 2 primary number, please check again',
                        ], 422);
                    }

                    foreach ($arraytelephone as $key) {

                        $telephoneDetail = Validator::make(
                            $key,
                            [
                                'phoneNumber' => 'required',
                                'type' => 'required',
                                'usage' => 'required',
                            ],
                            $messagePhone
                        );

                        if ($telephoneDetail->fails()) {

                            $errors = $telephoneDetail->errors()->all();

                            foreach ($errors as $checkisu) {

                                if (!(in_array($checkisu, $data_error_telephone))) {
                                    array_push($data_error_telephone, $checkisu);
                                }
                            }
                        }

                        if (strtolower($key['type']) == "whatshapp") {

                            if (!(substr($key['phoneNumber'], 0, 2) === "62")) {

                                return response()->json([
                                    'message' => 'The given data was invalid.',
                                    'errors' =>  'Please check your phone number, for type whatshapp must start with 62',
                                ], 422);
                            }
                        }
                    }


                    if ($data_error_telephone) {

                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' =>   $data_error_telephone,
                        ], 422);
                    }
                }
            }

            $data_error_email = [];

            $arrayemail = json_decode($request->emails, true);

            if (count($arrayemail) > 0) {

                $messageEmail = [
                    'email.required' => 'Email on tab email is required',
                    'usage.required' => 'Usage on tab email is required',
                ];

                if (!empty($arrayemail)) {
                    $primaryEmail = 0;
                    foreach ($arrayemail as $item) {
                        if (strtolower($item['usage']) == "utama") {
                            $primaryEmail++;
                        }
                    }

                    if ($primaryEmail == 0) {

                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' =>  'Email must have at least 1 primary email',
                        ], 422);
                    } elseif ($primaryEmail > 1) {

                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' =>  'Email have 2 primary email, please check again',
                        ], 422);
                    }



                    foreach ($arrayemail as $key) {

                        $emailDetail = Validator::make(
                            $key,
                            [
                                'email' => 'required',
                                'usage' => 'required',
                            ],
                            $messageEmail
                        );


                        if ($emailDetail->fails()) {

                            $errors = $emailDetail->errors()->all();

                            foreach ($errors as $checkisu) {

                                if (!(in_array($checkisu, $data_error_email))) {
                                    array_push($data_error_email, $checkisu);
                                }
                            }
                        }
                    }

                    if ($data_error_email) {
                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' => $data_error_email,
                        ], 422);
                    }
                }
            }


            $data_error_messengers = [];

            $arraymessenger = json_decode($request->messengers, true);

            if (count($arraymessenger) > 0) {

                if (!empty($arraymessenger)) {
                    $primaryMessenger = 0;
                    foreach ($arraymessenger as $item) {
                        if (strtolower($item['usage']) == "utama") {
                            $primaryMessenger++;
                        }
                    }


                    if ($primaryMessenger == 0) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Messenger must have at least 1 primary number',
                        ], 422);
                    } elseif ($primaryMessenger > 1) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Messenger have 2 primary number, please check again',
                        ], 422);
                    }


                    $messageMessenger = [
                        'messengerNumber.required' => 'messenger number on tab messenger is required',
                        'type.required' => 'Type on tab messenger is required',
                        'usage.required' => 'Usage on tab messenger is required',
                    ];

                    foreach ($arraymessenger as $key) {

                        $messengerDetail = Validator::make(
                            $key,
                            [
                                'messengerNumber' => 'required',
                                'type' => 'required',
                                'usage' => 'required',
                            ],
                            $messageMessenger
                        );

                        if ($messengerDetail->fails()) {

                            $errors = $messengerDetail->errors()->all();

                            foreach ($errors as $checkisu) {

                                if (!(in_array($checkisu, $data_error_messengers))) {
                                    array_push($data_error_messengers, $checkisu);
                                }
                            }
                        }

                        if (strtolower($key['type']) == "whatshapp") {

                            if (!(substr($key['messageMessenger'], 0, 2) === "62")) {
                                return response()->json([
                                    'message' => 'Inputed data is not valid',
                                    'errors' => 'Please check your phone number, for type whatshapp must start with 62',
                                ], 422);
                            }
                        }
                    }

                    if ($data_error_messengers) {
                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' => $data_error_messengers,
                        ], 422);
                    }
                }
            }


            $flag = false;

            if ($request->hasfile('images')) {

                $flag = true;

                $data_item = [];

                $files[] = $request->file('images');

                foreach ($files as $file) {

                    foreach ($file as $fil) {

                        $file_size = $fil->getSize();

                        $file_size = $file_size / 1024;

                        $oldname = $fil->getClientOriginalName();

                        if ($file_size >= 5000) {

                            array_push($data_item, 'Photo ' . $oldname . ' size more than 5mb! Please upload less than 5mb!');
                        }
                    }
                }

                if ($data_item) {

                    return response()->json([
                        'message' => 'Inputed photo is not valid',
                        'errors' => $data_item,
                    ], 422);
                }
            }

            if ($flag == true) {
                if ($request->imagesName) {
                    $ResultImageDatas = json_decode($request->imagesName, true);

                    foreach ($ResultImageDatas as $value) {

                        if ($value['name'] == "") {

                            return response()->json([
                                'message' => 'The given data was invalid.',
                                'errors' => ['Image name can not be empty!'],
                            ], 422);
                        }
                    }
                } else {

                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => ['Image name can not be empty!'],
                    ], 422);
                }
            }

            // // INSERT

            if ($request->memberNo) {
                $memberNo = $request->memberNo;
            } else {
                $memberNo = "";
            }

            if ($request->gender) {
                $gender = $request->gender;
            } else {
                $gender = "";
            }


            $customer = new Customer();
            $customer->memberNo =  $memberNo;
            $customer->firstName =  $request->firstName;
            $customer->middleName = $request->middleName;
            $customer->lastName = $request->lastName;
            $customer->nickName = $request->nickName;
            $customer->gender = $gender;
            $customer->titleCustomerId =  $request->titleCustomerId;
            $customer->customerGroupId = $request->customerGroupId;
            $customer->locationId = $request->locationId;
            $customer->notes =  $request->notes;
            $customer->colorType =  $request->colorType;
            $customer->joinDate = $request->joinDate;
            $customer->typeId =  $request->typeId;
            $customer->numberId =  $request->numberId;
            $customer->occupationId =  $request->occupationId;
            $customer->birthDate =  $request->birthDate;
            $customer->referenceCustomerId =  $request->referenceCustomerId;
            $customer->isReminderBooking =  $request->isReminderBooking;
            $customer->isReminderPayment =  $request->isReminderPayment;
            $customer->isDeleted = 0;
            $customer->createdBy = $request->user()->id;
            $customer->userUpdateId = $request->user()->id;
            $customer->save();

            $lastInsertedID = $customer->id;

            // log
            $locName = Location::find($request->locationId);
            recentActivity(
                $request->user()->id,
                'Customer',
                'Add Customer',
                'Add Customer ' . $request->firstName . ' at branch ' . optional($locName)->locationName
            );

            if ($request->customerPets) {

                foreach ($inputUnitReal as $val) {

                    $valueDate = null;
                    $valueMonth = null;
                    $valueYear = null;

                    if ($val['dateOfBirth'] == "") {
                        $valueDate = null;
                    } else {
                        $valueDate = $val['dateOfBirth'];
                    }

                    if ($val['petMonth'] == "") {
                        $valueMonth = null;
                    } else {
                        $valueMonth = $val['petMonth'];
                    }

                    if ($val['petYear'] == "") {
                        $valueYear = null;
                    } else {
                        $valueYear = $val['petYear'];
                    }

                    $customerPets = new CustomerPets();
                    $customerPets->customerId =  $lastInsertedID;
                    $customerPets->petName = $val['petName'];
                    $customerPets->petCategoryId = $val['petCategoryId'];
                    $customerPets->races = $val['races'];
                    $customerPets->condition = $val['condition'];
                    $customerPets->color = $val['color'];
                    $customerPets->petMonth = $valueMonth;
                    $customerPets->petYear = $valueYear;
                    $customerPets->dateOfBirth = $valueDate;
                    $customerPets->petGender = $val['petGender'];
                    $customerPets->isSteril = $val['isSteril'];
                    $customerPets->remark = $val['remark'];
                    $customerPets->createdBy = $request->user()->id;
                    $customerPets->userUpdateId = $request->user()->id;
                    $customerPets->save();
                }
            }


            if ($request->reminderBooking) {

                foreach ($arrayReminderBooking as $val) {

                    $customerReminder = new CustomerReminder();
                    $customerReminder->customerId =  $lastInsertedID;
                    $customerReminder->sourceId = $val['sourceId'];
                    $customerReminder->unit = $val['unit'];
                    $customerReminder->timing = $val['timing'];
                    $customerReminder->status = $val['status'];
                    $customerReminder->type = 'B';
                    $customerReminder->isDeleted = 0;
                    $customerReminder->save();
                }
            }



            if ($request->reminderPayment) {

                foreach ($arrayReminderPayment as $val) {

                    $customerReminderPayment = new CustomerReminder();
                    $customerReminderPayment->customerId =  $lastInsertedID;
                    $customerReminderPayment->sourceId = $val['sourceId'];
                    $customerReminderPayment->unit = $val['unit'];
                    $customerReminderPayment->timing = $val['timing'];
                    $customerReminderPayment->status = $val['status'];
                    $customerReminderPayment->type = 'P';
                    $customerReminderPayment->isDeleted = 0;
                    $customerReminderPayment->save();
                }
            }


            if ($request->reminderLatePayment) {

                foreach ($reminderLatePayment as $val) {

                    $customerReminderLatePayment = new CustomerReminder();
                    $customerReminderLatePayment->customerId =  $lastInsertedID;
                    $customerReminderLatePayment->sourceId = $val['sourceId'];
                    $customerReminderLatePayment->unit = $val['unit'];
                    $customerReminderLatePayment->timing = $val['timing'];
                    $customerReminderLatePayment->status = $val['status'];
                    $customerReminderLatePayment->type = 'LP';
                    $customerReminderLatePayment->isDeleted = 0;
                    $customerReminderLatePayment->save();
                }
            }


            if ($request->detailAddresses) {

                foreach ($arrayDetailAddress as $val) {

                    $customerAddresses = new CustomerAddresses();
                    $customerAddresses->customerId =  $lastInsertedID;
                    $customerAddresses->addressName =  $val['addressName'];
                    $customerAddresses->additionalInfo =  $val['additionalInfo'];
                    $customerAddresses->provinceCode =  $val['provinceCode'];
                    $customerAddresses->cityCode =  $val['cityCode'];
                    $customerAddresses->postalCode =  $val['postalCode'];
                    $customerAddresses->country =  $val['country'];
                    $customerAddresses->isPrimary =   $val['isPrimary'];
                    $customerAddresses->isDeleted =  0;
                    $customerAddresses->save();
                }
            }

            if ($request->telephones) {

                foreach ($arraytelephone as $val) {

                    $customerTelephones = new CustomerTelephones();
                    $customerTelephones->customerId =  $lastInsertedID;
                    $customerTelephones->phoneNumber =  $val['phoneNumber'];
                    $customerTelephones->type =  $val['type'];
                    $customerTelephones->usage =  $val['usage'];
                    $customerTelephones->isDeleted =  0;
                    $customerTelephones->save();
                }
            }



            if ($request->emails) {

                foreach ($arrayemail as $val) {

                    $customerEmails = new CustomerEmails();
                    $customerEmails->customerId =  $lastInsertedID;
                    $customerEmails->email =  $val['email'];
                    $customerEmails->usage =  $val['usage'];
                    $customerEmails->isDeleted =  0;
                    $customerEmails->save();
                }
            }


            if ($request->messengers) {

                foreach ($arraymessenger as $val) {

                    $customerMessengers = new CustomerMessengers();
                    $customerMessengers->customerId =  $lastInsertedID;
                    $customerMessengers->messengerNumber =  $val['messengerNumber'];
                    $customerMessengers->type =  $val['type'];
                    $customerMessengers->usage =  $val['usage'];
                    $customerMessengers->isDeleted =  0;
                    $customerMessengers->save();
                }
            }

            if ($request->hasfile('images')) {

                $json_array = json_decode($request->imagesName, true);
                $int = 0;

                if (count($files) != 0) {

                    foreach ($files as $file) {

                        foreach ($file as $fil) {

                            $name = $fil->hashName();
                            $fil->move(public_path() . '/CustomerImages/', $name);

                            $fileName = "/CustomerImages/" . $name;

                            $customerImages = new CustomerImages();
                            $customerImages->customerId =  $lastInsertedID;
                            $customerImages->labelName =  $json_array[$int]['name'];
                            $customerImages->realImageName =  $fil->getClientOriginalName();
                            $customerImages->imageName =  $name;
                            $customerImages->imagePath =  $fileName;
                            $customerImages->isDeleted =  0;
                            $customerImages->save();

                            $int = $int + 1;
                        }
                    }
                }
            }

            DB::commit();

            return response()->json(
                [
                    'result' => 'success',
                    'message' => 'Insert Data Customer Successful!',
                ],
                200
            );
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function updatePetAge(Request $request)
    {

        try {

            if (date('n') !== date('n', strtotime('yesterday'))) {

                $customerPets = DB::table('customerPets')
                    ->where([
                        ['isDeleted', '=', '0']
                    ])
                    ->get();


                foreach ($customerPets as $key) {

                    $getpetsbyID = DB::table('customerPets')
                        ->where([
                            ['isDeleted', '=', '0'],
                            ['id', '=', $key->id]
                        ])->first();

                    if ($getpetsbyID->dateOfBirth == null) { //artinya menggunakan date of birth, jadi ga usah di rubah

                        if ($getpetsbyID->petMonth == 12) { /// kalau udah 12 bulan makan set jadi 1 dan year ditambah satu

                            CustomerPets::where('id', '=', $key->id)
                                ->update([
                                    'petMonth' => 1,
                                    'petYear' => $getpetsbyID->petYear + 1,
                                ]);
                        } else {

                            CustomerPets::where('id', '=', $key->id)
                                ->update([
                                    'petMonth' => $getpetsbyID->petMonth + 1,
                                ]);
                        }
                    }
                }
                // } else {
                // $customerPets = DB::table('customerPets')
                //     ->where([
                //         ['isDeleted', '=', '0']
                //     ])
                //     ->get();


                // foreach ($customerPets as $key) {

                //     $getpetsbyID = DB::table('customerPets')
                //         ->where([
                //             ['isDeleted', '=', '0'],
                //             ['id', '=', $key->id]
                //         ])->first();

                //     if ($getpetsbyID->dateOfBirth == null) { //artinya menggunakan date of birth, jadi ga usah di rubah

                //         if ($getpetsbyID->petMonth == 12) { /// kalau udah 12 bulan makan set jadi 1 dan year ditambah satu

                //             CustomerPets::where('id', '=', $key->id)
                //                 ->update([
                //                     'petMonth' => 1,
                //                     'petYear' => $getpetsbyID->petYear + 1,
                //                 ]);
                //         } else {

                //             CustomerPets::where('id', '=', $key->id)
                //                 ->update([
                //                     'petMonth' => $getpetsbyID->petMonth + 1,
                //                 ]);
                //         }
                //     }
                // }
            }

            DB::commit();

            return response()->json([
                'result' => 'Success',
                'message' => "Successfully update all pet Age",
            ], 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function updateCustomer(Request $request)
    {
        if (!checkAccessModify('customer-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        DB::beginTransaction();

        try {

            $validate = Validator::make(
                $request->all(),
                [
                    'customerId' => 'required|integer',
                    'memberNo' => 'nullable|string|max:100',
                    'firstName' => 'required|string|max:100',
                    'middleName' => 'nullable|string|max:100',
                    'lastName' => 'nullable|string|max:100',
                    'titleCustomerId' => 'nullable|integer',
                    'nickName' => 'nullable|string|max:100',
                    'customerGroupId' => 'nullable|integer',
                    'locationId' => 'required|integer',
                    'notes' => 'nullable|string',
                    'colorType' => 'nullable|string',
                    'joinDate' => 'required|date',
                    'typeId' => 'nullable|integer',
                    'numberId' => 'nullable|string|max:50',
                    'gender' => 'nullable|string',
                    'occupationId' => 'nullable|integer',
                    'birthDate' => 'nullable|date',
                    'referenceCustomerId' => 'nullable|integer',
                    'isReminderBooking' => 'integer|nullable',
                    'isReminderPayment' => 'integer|nullable',

                ]
            );


            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }



            $data_item_pet = [];
            $inputUnitReal = [];

            if ($request->customerPets) {

                $messageCustomerPet = [
                    'petName.required' => 'Pet name on tab Customer Pet is required',
                    'petCategoryId.required' => 'Category Pet tab Customer Pet is required',
                    'races.required' => 'Pet Races in tab Customer Pet is required',
                    'condition.required' => 'Condition on tab Customer Pet is required',
                    'petGender.required' => 'Pet Gender on tab Cutomer Pet is required',
                    'isSteril.required' => 'Pet Steril  on tab Cutomer Pet is required',

                ];


                foreach ($request->customerPets as $key) {

                    $validateDetail = Validator::make(
                        $key,
                        [
                            'petName' => 'required|string|max:100',
                            'petCategoryId' => 'required:integer',
                            'race' => 'nullable|string|max:100',
                            'condition' => 'required|string|max:100',
                            'petGender' => 'required|in:J,B',
                            'isSteril' => 'required|in:1,0',
                            'remark' => 'nullable|string',

                        ],
                        $messageCustomerPet
                    );



                    if ($validateDetail->fails()) {

                        $errors = $validateDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_item_pet))) {
                                array_push($data_item_pet, $checkisu);
                            }
                        }
                    }
                }

                if ($data_item_pet) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $data_item_pet,
                    ], 422);
                }
            }

            $data_reminder_booking = [];

            if ($request->reminderBooking) {


                $messageReminderBooking = [
                    'sourceId.required' => 'Source on tab Reminder and on Reminder Booking is required',
                    'unit.required' => 'Unit on tab Reminder and on Reminder Booking is required',
                    'timing.required' => 'Timing on tab Reminder and on Reminder Booking is required',
                    'status.required' => 'Status Reminder Booking is required',
                ];


                foreach ($request->reminderBooking as $key) {

                    $validateReminderBooking = Validator::make(
                        $key,
                        [
                            'sourceId' => 'required|integer',
                            'unit' => 'required|integer',
                            'timing' => 'required',
                            'status' => 'required',
                        ],
                        $messageReminderBooking
                    );


                    if ($validateReminderBooking->fails()) {

                        $errors = $validateReminderBooking->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_reminder_booking))) {
                                array_push($data_reminder_booking, $checkisu);
                            }
                        }
                    }
                }

                if ($data_reminder_booking) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $data_reminder_booking,
                    ], 422);
                }
            }


            $data_reminder_payment = [];

            if ($request->reminderPayment) {

                $messageReminderPayment = [
                    'sourceId.required' => 'Source on tab Reminder and on Reminder Payment is required',
                    'unit.required' => 'Unit on tab Reminder and on Reminder Payment is required',
                    'timing.required' => 'Timing on tab Reminder and on Reminder Payment is required',
                    'status.required' => 'Status Reminder Payment is required',
                ];


                foreach ($request->reminderPayment as $key) {

                    $validateReminderPayment = Validator::make(
                        $key,
                        [
                            'sourceId' => 'required|integer',
                            'unit' => 'required|integer',
                            'timing' => 'required',
                            'status' => 'required',
                        ],
                        $messageReminderPayment
                    );



                    if ($validateReminderPayment->fails()) {

                        $errors = $validateReminderPayment->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_reminder_payment))) {
                                array_push($data_reminder_payment, $checkisu);
                            }
                        }
                    }
                }

                if ($data_reminder_payment) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $data_reminder_payment,
                    ], 422);
                }
            }


            $data_reminder_late_payment = [];

            if ($request->reminderLatePayment) {

                $messageReminderLatePayment = [
                    'sourceId.required' => 'Source on tab Reminder and on Reminder Late Payment is required',
                    'unit.required' => 'Unit on tab Reminder and on Reminder Late Payment is required',
                    'timing.required' => 'Time Date on tab Reminder and on Reminder Late Payment is required',
                    'status.required' => 'Status Reminder Late Payment is required',
                ];


                foreach ($request->reminderLatePayment as $key) {

                    $validateReminderLatePayment = Validator::make(
                        $key,
                        [
                            'sourceId' => 'required|integer',
                            'unit' => 'required|integer',
                            'timing' => 'required',
                            'status' => 'required',
                        ],
                        $messageReminderLatePayment
                    );


                    if ($validateReminderLatePayment->fails()) {

                        $errors = $validateReminderLatePayment->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_reminder_late_payment))) {
                                array_push($data_reminder_late_payment, $checkisu);
                            }
                        }
                    }
                }

                if ($data_reminder_late_payment) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $data_reminder_late_payment,
                    ], 422);
                }
            }


            $data_item = [];

            if ($request->detailAddresses) {


                $messageAddress = [
                    'addressName.required' => 'Address name on tab Address is required',
                    'provinceCode.required' => 'Province code on tab Address is required',
                    'cityCode.required' => 'City code on tab Address is required',
                    'country.required' => 'Country on tab Address is required',
                ];


                $primaryCount = 0;
                foreach ($request->detailAddresses as $item) {
                    if (isset($item['isPrimary']) && $item['isPrimary'] == 1) {
                        $primaryCount++;
                    }
                }


                if ($primaryCount == 0) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address must have at least 1 primary address',
                    ], 422);
                } elseif ($primaryCount > 1) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address have 2 primary address, please check again',
                    ], 422);
                }

                foreach ($request->detailAddresses as $key) {

                    $validateDetail = Validator::make(
                        $key,
                        [
                            'addressName' => 'required',
                            'provinceCode' => 'required',
                            'cityCode' => 'required',
                            'country' => 'required',
                        ],
                        $messageAddress
                    );

                    if ($validateDetail->fails()) {

                        $errors = $validateDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_item))) {
                                array_push($data_item, $checkisu);
                            }
                        }
                    }
                }


                if ($data_item) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $data_item,
                    ], 422);
                }
            }

            $data_error_telephone = [];

            if ($request->telephones) {

                $messagePhone = [
                    'phoneNumber.required' => 'Phone Number on tab telephone is required',
                    'type.required' => 'Type on tab telephone is required',
                    'usage.required' => 'Usage on tab telephone is required',
                ];


                $primaryTelephone = 0;

                if (!empty($request->telephones)) {

                    foreach ($request->telephones as $item) {
                        if (strtolower($item['usage']) == "utama") {
                            $primaryTelephone++;
                        }
                    }

                    if ($primaryTelephone == 0) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Telephone must have at least 1 primary number',
                        ], 422);
                    } elseif ($primaryTelephone > 1) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Telephone have 2 primary number, please check again',
                        ], 422);
                    }


                    foreach ($request->telephones as $key) {

                        $telephoneDetail = Validator::make(
                            $key,
                            [
                                'phoneNumber' => 'required',
                                'type' => 'required',
                                'usage' => 'required',
                            ],
                            $messagePhone
                        );

                        if ($telephoneDetail->fails()) {

                            $errors = $telephoneDetail->errors()->all();

                            foreach ($errors as $checkisu) {

                                if (!(in_array($checkisu, $data_error_telephone))) {
                                    array_push($data_error_telephone, $checkisu);
                                }
                            }
                        }

                        if (strtolower($key['type']) == "whatsapp") {

                            if (!(substr($key['phoneNumber'], 0, 2) === "62")) {
                                return response()->json([
                                    'message' => 'Inputed data is not valid',
                                    'errors' => 'Please check your phone number, for type Whatsapp must start with 62',
                                ], 422);
                            }
                        }
                    }


                    if ($data_error_telephone) {
                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' => $data_error_telephone,
                        ], 422);
                    }
                }
            }



            $data_error_email = [];

            if ($request->emails) {

                $messageEmail = [
                    'email.required' => 'email on tab email is required',
                    'usage.required' => 'Usage on tab email is required',
                ];

                $primaryEmail = 0;

                if (!empty($request->emails)) {
                    foreach ($request->emails as $item) {
                        if (strtolower($item['usage']) == "utama") {
                            $primaryEmail++;
                        }
                    }

                    if ($primaryEmail == 0) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Email must have at least 1 primary email',
                        ], 422);
                    } elseif ($primaryEmail > 1) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Email have 2 primary email, please check again',
                        ], 422);
                    }

                    foreach ($request->emails as $key) {

                        $emailDetail = Validator::make(
                            $key,
                            [
                                'email' => 'required',
                                'usage' => 'required',
                            ],
                            $messageEmail
                        );


                        if ($emailDetail->fails()) {

                            $errors = $emailDetail->errors()->all();

                            foreach ($errors as $checkisu) {

                                if (!(in_array($checkisu, $data_error_email))) {
                                    array_push($data_error_email, $checkisu);
                                }
                            }
                        }
                    }

                    if ($data_error_email) {
                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' => $data_error_email,
                        ], 422);
                    }
                }
            }

            $data_error_messengers = [];
            if ($request->messengers) {

                $primaryMessenger = 0;

                if (!empty($request->messengers)) {
                    foreach ($request->messengers as $item) {
                        if (strtolower($item['usage']) == "utama") {
                            $primaryMessenger++;
                        }
                    }

                    if ($primaryMessenger == 0) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Messenger must have at least 1 primary number',
                        ], 422);
                    } elseif ($primaryMessenger > 1) {
                        return response()->json([
                            'message' => 'Inputed data is not valid',
                            'errors' => 'Messenger have 2 primary number, please check again',
                        ], 422);
                    }

                    $messageMessenger = [
                        'messengerNumber.required' => 'messenger number on tab messenger is required',
                        'type.required' => 'Type on tab messenger is required',
                        'usage.required' => 'Usage on tab messenger is required',
                    ];

                    foreach ($request->messengers as $key) {

                        $messengerDetail = Validator::make(
                            $key,
                            [
                                'messengerNumber' => 'required',
                                'type' => 'required',
                                'usage' => 'required',
                            ],
                            $messageMessenger
                        );

                        if ($messengerDetail->fails()) {

                            $errors = $messengerDetail->errors()->all();

                            foreach ($errors as $checkisu) {

                                if (!(in_array($checkisu, $data_error_messengers))) {
                                    array_push($data_error_messengers, $checkisu);
                                }
                            }
                        }

                        if (strtolower($key['type']) == "whatshapp") {

                            if (!(substr($key['messageMessenger'], 0, 2) === "62")) {

                                return response()->json([
                                    'message' => 'Inputed data is not valid',
                                    'errors' => 'Please check your phone number, for type whatshapp must start with 62',
                                ], 422);
                            }
                        }
                    }

                    if ($data_error_messengers) {
                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' => $data_error_messengers,
                        ], 422);
                    }
                }
            }

            // Update

            if ($request->memberNo) {
                $memberNo = $request->memberNo;
            } else {
                $memberNo = "";
            }

            if ($request->gender) {
                $gender = $request->gender;
            } else {
                $gender = "";
            }

            Customer::where('id', '=', $request->input('customerId'))
                ->update([
                    'firstName' => $request->firstName,
                    'memberNo' => $memberNo,
                    'middleName' => $request->middleName,
                    'lastName' => $request->lastName,
                    'nickName' => $request->nickName,
                    'gender' => $gender,
                    'titleCustomerId' => $request->titleCustomerId,
                    'customerGroupId' => $request->customerGroupId,
                    'locationId' => $request->locationId,
                    'notes' => $request->notes,
                    'colorType' => $request->colorType,
                    'joinDate' => $request->joinDate,
                    'typeId' => $request->typeId,
                    'numberId' => $request->numberId,
                    'occupationId' => $request->occupationId,
                    'birthDate' => $request->birthDate,
                    'referenceCustomerId' => $request->referenceCustomerId,
                    'isReminderBooking' => $request->isReminderBooking,
                    'isReminderPayment' => $request->isReminderPayment,
                    'isDeleted' => 0,
                    'updated_at' => now(),
                ]);

            $locName = Location::find($request->locationId);
            $customer = Customer::find($request->customerId);
            recentActivity(
                $request->user()->id,
                'Customer',
                'Update Customer',
                'Update data for customer ' . $customer->firstName . ' at branch ' . optional($locName)->locationName
            );

            foreach ($request->customerPets as $val) {

                if ($val['id'] == "") {

                    $valueDate = null;
                    $valueMonth = null;
                    $valueYear = null;

                    if ($val['dateOfBirth'] == "" || $val['petMonth'] == null) {
                        $valueDate = null;
                    } else {
                        $valueDate = $val['dateOfBirth'];
                    }

                    if ($val['petMonth'] == "" || $val['petMonth'] == null) {
                        $valueMonth = null;
                    } else {
                        $valueMonth = $val['petMonth'];
                    }

                    if ($val['petYear'] == "" || $val['petMonth'] == null) {
                        $valueYear = null;
                    } else {
                        $valueYear = $val['petYear'];
                    }


                    $customerPets = new CustomerPets();
                    $customerPets->customerId =  $request->input('customerId');
                    $customerPets->petName = $val['petName'];
                    $customerPets->petCategoryId = $val['petCategoryId'];
                    $customerPets->races = $val['races'];
                    $customerPets->condition = $val['condition'];
                    $customerPets->color = $val['color'];
                    $customerPets->petMonth = $valueMonth;
                    $customerPets->petYear = $valueYear;
                    $customerPets->dateOfBirth = $valueDate;
                    $customerPets->petGender = $val['petGender'];
                    $customerPets->isSteril = $val['isSteril'];
                    $customerPets->remark = $val['remark'] ?? null; // tambahan null
                    $customerPets->createdBy = $request->user()->id;
                    $customerPets->userUpdateId = $request->user()->id;
                    $customerPets->save();
                } else {

                    if ($val['command'] == "del") {

                        CustomerPets::where([
                            ['customerId', '=', $request->input('customerId')],
                            ['id', '=', $val['id']],
                            ['isDeleted', '=', '0']
                        ])->update([
                            'deletedBy' => $request->user()->id,
                            'isDeleted' => 1,
                            'deletedAt' => Carbon::now()
                        ]);
                    } else {

                        $valueDate = null;
                        $valueMonth = null;
                        $valueYear = null;

                        if ($val['dateOfBirth'] == "") {
                            $valueDate = null;
                        } else {
                            $valueDate = $val['dateOfBirth'];
                        }

                        if ($val['petMonth'] == "") {
                            $valueMonth = null;
                        } else {
                            $valueMonth = $val['petMonth'];
                        }

                        if ($val['petYear'] == "") {
                            $valueYear = null;
                        } else {
                            $valueYear = $val['petYear'];
                        }

                        CustomerPets::where([
                            ['customerId', '=', $request->input('customerId')],
                            ['id', '=', $val['id']],
                            ['isDeleted', '=', '0']
                        ])->update([
                            'petName' => $val['petName'],
                            'petCategoryId' => $val['petCategoryId'],
                            'races' => $val['races'],
                            'condition' => $val['condition'],
                            'color' => $val['color'],
                            'petMonth' => $valueMonth,
                            'petYear' => $valueYear,
                            'dateOfBirth' => $valueDate,
                            'petGender' => $val['petGender'],
                            'isSteril' => $val['isSteril'],
                            'remark' => $val['remark'] ?? null,
                            'updated_at' => now(),
                        ]);
                    }
                }
            }


            if ($request->reminderBooking) {

                CustomerReminder::where([
                    ['customerId', '=', $request->input('customerId')],
                    ['type', '=', 'B'],
                ])
                    ->delete();

                foreach ($request->reminderBooking as $val) {

                    $customerReminder = new CustomerReminder();
                    $customerReminder->customerId =  $request->input('customerId');
                    $customerReminder->sourceId = $val['sourceId'];
                    $customerReminder->unit = $val['unit'];
                    $customerReminder->timing = $val['timing'];
                    $customerReminder->status = $val['status'];
                    $customerReminder->type = 'B';
                    $customerReminder->isDeleted = 0;
                    $customerReminder->save();
                }
            }

            if ($request->reminderPayment) {

                CustomerReminder::where([
                    ['customerId', '=', $request->input('customerId')],
                    ['type', '=', 'P'],
                ])
                    ->delete();

                foreach ($request->reminderPayment as $val) {

                    $customerReminderPayment = new CustomerReminder();
                    $customerReminderPayment->customerId =  $request->input('customerId');
                    $customerReminderPayment->sourceId = $val['sourceId'];
                    $customerReminderPayment->unit = $val['unit'];
                    $customerReminderPayment->timing = $val['timing'];
                    $customerReminderPayment->status = $val['status'];
                    $customerReminderPayment->type = 'P';
                    $customerReminderPayment->isDeleted = 0;
                    $customerReminderPayment->save();
                }
            }


            if ($request->reminderLatePayment) {

                CustomerReminder::where([
                    ['customerId', '=', $request->input('customerId')],
                    ['type', '=', 'LP'],
                ])->delete();

                foreach ($request->reminderLatePayment as $val) {

                    $customerReminderLatePayment = new CustomerReminder();
                    $customerReminderLatePayment->customerId =  $request->input('customerId');
                    $customerReminderLatePayment->sourceId = $val['sourceId'];
                    $customerReminderLatePayment->unit = $val['unit'];
                    $customerReminderLatePayment->timing = $val['timing'];
                    $customerReminderLatePayment->status = $val['status'];
                    $customerReminderLatePayment->type = 'LP';
                    $customerReminderLatePayment->isDeleted = 0;
                    $customerReminderLatePayment->save();
                }
            }


            if ($request->detailAddresses) {

                CustomerAddresses::where('customerId', '=', $request->input('customerId'))->delete();

                foreach ($request->detailAddresses as $val) {

                    $customerAddresses = new CustomerAddresses();
                    $customerAddresses->customerId =  $request->input('customerId');
                    $customerAddresses->addressName =  $val['addressName'];
                    $customerAddresses->additionalInfo =  $val['additionalInfo'];
                    $customerAddresses->provinceCode =  $val['provinceCode'];
                    $customerAddresses->cityCode =  $val['cityCode'];
                    $customerAddresses->postalCode =  $val['postalCode'];
                    $customerAddresses->country =  $val['country'];
                    $customerAddresses->isPrimary =   $val['isPrimary'];
                    $customerAddresses->isDeleted =  0;
                    $customerAddresses->save();
                }
            }

            if ($request->telephones) {

                CustomerTelephones::where('customerId', '=', $request->input('customerId'))->delete();

                foreach ($request->telephones as $val) {


                    $CustomerTelephones = new CustomerTelephones();
                    $CustomerTelephones->customerId =  $request->input('customerId');
                    $CustomerTelephones->phoneNumber =  $val['phoneNumber'];
                    $CustomerTelephones->type =  $val['type'];
                    $CustomerTelephones->usage =  $val['usage'];
                    $CustomerTelephones->isDeleted =  0;
                    $CustomerTelephones->save();
                }
            }



            if ($request->emails) {

                CustomerEmails::where('customerId', '=', $request->input('customerId'))->delete();

                foreach ($request->emails as $val) {

                    $customerEmails = new CustomerEmails();
                    $customerEmails->customerId =  $request->input('customerId');
                    $customerEmails->email =  $val['email'];
                    $customerEmails->usage =  $val['usage'];
                    $customerEmails->isDeleted =  0;
                    $customerEmails->save();
                }
            }

            if ($request->messengers) {

                CustomerMessengers::where('customerId', '=', $request->input('customerId'))->delete();

                foreach ($request->messengers as $val) {

                    $customerMessengers = new CustomerMessengers();
                    $customerMessengers->customerId =  $request->input('customerId');
                    $customerMessengers->messengerNumber =  $val['messengerNumber'];
                    $customerMessengers->type =  $val['type'];
                    $customerMessengers->usage =  $val['usage'];
                    $customerMessengers->isDeleted =  0;
                    $customerMessengers->save();
                }
            }

            DB::commit();

            return response()->json(
                [
                    'result' => 'success',
                    'message' => 'Update Data Customer Successful!',
                ],
                200
            );
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function getDetailCustomer(Request $request)
    {


        $validate = Validator::make($request->all(), [
            'customerId' => 'required'
        ]);

        if ($validate->fails()) {

            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [$errors],
            ], 422);
        }

        $request->validate(['customerId' => 'required|max:10000']);
        $customerId = $request->input('customerId');


        if ($request->input('type') != "" &&  $request->input('type') != "edit") {

            return response()->json([
                'message' => 'Inputed data is not valid',
                'errors' => ['Type must "" or "edit" '],
            ], 422);
        }

        if ($request->input('type') == "") {
            $checkIfValueExits = Customer::where([
                ['id', '=', $request->input('customerId')],
                ['isDeleted', '=', '0']
            ])->first();

            if ($checkIfValueExits === null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => "Data not exists, please try another customer id",
                ]);
            } else {


                $param_customer = DB::table('customer')
                    ->select(
                        'id',
                        'memberNo',
                        'firstName',
                        'middleName',
                        'lastName',
                        'nickName',
                        'gender',
                        'titleCustomerId',
                        'customerGroupId',
                        'locationId',
                        'notes',
                        'colorType',
                        DB::raw("DATE_FORMAT(joinDate, '%Y-%m-%d') as joinDate"),
                        'typeId',
                        'numberId',
                        'occupationId',
                        DB::raw("IFNULL(DATE_FORMAT(birthDate, '%Y-%m-%d'),'') as birthDate"),
                        'referenceCustomerId',
                        'isReminderBooking',
                        'isReminderPayment'
                    )
                    ->where('id', '=', $customerId)
                    ->first();

                $customerPets = DB::table('customerPets')
                    ->select(
                        'id',
                        'petName',
                        'petCategoryId',
                        'races',
                        'condition',
                        'color',
                        DB::raw("CASE WHEN dateOfBirth is null then 'Month and Year' else 'Birth Date' end as isbirthDate"),
                        'petMonth',
                        'petYear',
                        DB::raw("IFNULL(DATE_FORMAT(dateOfBirth, '%Y-%m-%d'),'') as dateOfBirth"),
                        'petGender',
                        'isSteril',
                        'remark'
                    )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->customerPets = $customerPets;


                $reminderBooking = CustomerReminder::select(
                    'id',
                    'sourceId',
                    'unit',
                    'timing',
                    'status'
                )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['type', '=', 'B'],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->reminderBooking = $reminderBooking;


                $reminderPayment = CustomerReminder::select(
                    'sourceId',
                    'unit',
                    'timing',
                    'status'
                )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['type', '=', 'P'],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->reminderPayment = $reminderPayment;

                $reminderLatePayment = CustomerReminder::select(
                    'sourceId',
                    'unit',
                    'timing',
                    'status'
                )->where([
                    ['customerId', '=', $customerId],
                    ['type', '=', 'LP'],
                    ['isDeleted', '=', '0']
                ])
                    ->get();

                $param_customer->reminderLatePayment = $reminderLatePayment;

                $detailAddresses = CustomerAddresses::select(
                    'addressName as addressName',
                    'additionalInfo as additionalInfo',
                    'provinceCode as provinceCode',
                    'cityCode as cityCode',
                    'postalCode as postalCode',
                    'country as country',
                    'isPrimary as isPrimary',
                )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->detailAddresses = $detailAddresses;


                $telephones = CustomerTelephones::select(
                    'phoneNumber as phoneNumber',
                    'type as type',
                    'usage as usage',
                )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->telephones = $telephones;


                $emails = CustomerEmails::select(
                    'email as email',
                    'usage as usage',
                )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->emails = $emails;


                $messengers = CustomerMessengers::select(
                    'messengerNumber as messengerNumber',
                    'type as type',
                    'usage as usage',
                )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->messengers = $messengers;



                $customeImages = CustomerImages::select(
                    'id as id',
                    'labelName as labelName',
                    'imagePath as imagePath',
                )
                    ->where([
                        ['customerId', '=', $customerId],
                        ['isDeleted', '=', '0']
                    ])
                    ->get();

                $param_customer->images = $customeImages;


                return response()->json($param_customer, 200);
            }
        } else { // untuk view edit disini

        }
    }



    public function uploadImageCustomer(Request $request)
    {

        try {

            $json_array = json_decode($request->imagesName, true);
            $files[] = $request->file('images');
            $index = 0;

            foreach ($json_array as $val) {


                if (($val['id'] == "" || $val['id'] == 0)  && ($val['status'] == "")) {

                    $name = $files[0][$index]->hashName();

                    $files[0][$index]->move(public_path() . '/CustomerImages/', $name);

                    $fileName = "/CustomerImages/" . $name;

                    $customerImages = new CustomerImages();
                    $customerImages->customerId = $request->input('customerId');
                    $customerImages->labelName = $val['name'];
                    $customerImages->realImageName = $files[0][$index]->getClientOriginalName();
                    $customerImages->imageName = $name;
                    $customerImages->imagePath = $fileName;
                    $customerImages->isDeleted = 0;
                    $customerImages->save();
                    $index = $index + 1;
                    DB::commit();
                } elseif (($val['id'] != "" && $val['id'] != 0)  && ($val['status'] == "del")) {


                    $find_image = CustomerImages::select(
                        'imageName',
                        'imagePath'
                    )
                        ->where('id', '=', $val['id'])
                        ->where('customerId', '=', $request->input('customerId'))
                        ->first();

                    if ($find_image) {

                        if (file_exists(public_path() . $find_image->imagePath)) {

                            File::delete(public_path() . $find_image->imagePath);

                            CustomerImages::where([
                                ['customerId', '=', $request->input('customerId')],
                                ['id', '=', $val['id']]
                            ])->delete();
                        }
                    }
                } elseif (($val['id'] != "" || $val['id'] != 0)  && ($val['status'] == "")) {

                    $find_image = CustomerImages::select(
                        'imageName',
                        'imagePath'
                    )
                        ->where('id', '=', $val['id'])
                        ->where('customerId', '=', $request->input('customerId'))
                        ->first();

                    if ($find_image) {

                        CustomerImages::where([
                            ['customerId', '=', $request->input('customerId')],
                            ['id', '=', $val['id']]
                        ])
                            ->update([
                                'labelName' => $val['name'],
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'result' => 'success',
                'message' => 'successfuly update image customer',
            ]);
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ]);
        }
    }


    public function deleteCustomer(Request $request)
    {

        if (!checkAccessDelete('customer-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $validate = Validator::make($request->all(), [
            'customerId' => 'required',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        DB::beginTransaction();

        try {

            $data_item = [];
            foreach ($request->customerId as $val) {

                $checkIfDataExits = Customer::where([
                    ['id', '=', $val],
                    ['isDeleted', '=', '0']
                ])
                    ->first();

                if (!$checkIfDataExits) {
                    array_push($data_item, 'customer id : ' . $val . ' not found, please try different customer id');
                }
            }

            if ($data_item) {
                return response()->json([
                    'message' => 'Inputed data is not valid',
                    'errors' => $data_item,
                ], 422);
            }

            foreach ($request->customerId as $val) {


                Customer::where('id', '=', $val)
                    ->update([
                        'deletedBy' => $request->user()->firstName,
                        'isDeleted' => 1,
                        'deletedAt' => Carbon::now()
                    ]);

                CustomerReminder::where('customerId', '=', $val)
                    ->update([
                        'deletedBy' => $request->user()->firstName,
                        'isDeleted' => 1,
                        'deletedAt' => Carbon::now()
                    ]);

                CustomerAddresses::where('customerId', '=', $val)
                    ->update([
                        'deletedBy' => $request->user()->firstName,
                        'isDeleted' => 1,
                        'deletedAt' => Carbon::now()
                    ]);

                CustomerEmails::where('customerId', '=', $val)
                    ->update([
                        'isDeleted' => 1,
                    ]);

                CustomerMessengers::where('customerId', '=', $val)
                    ->update([
                        'isDeleted' => 1,
                    ]);

                CustomerTelephones::where('customerId', '=', $val)
                    ->update([
                        'isDeleted' => 1,
                    ]);

                CustomerImages::where('customerId', '=', $val)
                    ->update([
                        'isDeleted' => 1,
                    ]);

                $customer = Customer::with('location')->find($val);
                $locName = optional($customer->location)->locationName;

                recentActivity(
                    $request->user()->id,
                    'Customer',
                    'Delete Customer',
                    'Delete Customer ' . $customer->firstName . ' at branch ' . $locName
                );

                DB::commit();
            }

            return response()->json([
                'result' => 'success',
                'message' => 'Successfully deleted Customer',
            ]);
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ]);
        }
    }


    public function getSourceCustomer(Request $request)
    {

        try {

            $getSourceCustomer = SourceCustomer::select(
                'id as sourceId',
                'sourceName as sourceName',
            )
                ->where([
                    ['isActive', '=', 1],
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($getSourceCustomer, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function insertSourceCustomer(Request $request)
    {

        $request->validate([
            'sourceName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = SourceCustomer::where([
                ['sourceName', '=', $request->sourceName],
                ['isActive', '=', 1]
            ])->first();

            if ($checkIfValueExits != null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Source Name Customer title already exists, please choose another name',
                ]);
            } else {



                $sourceCustomer = new SourceCustomer();
                $sourceCustomer->sourceName = $request->sourceName;
                $sourceCustomer->isActive = 1;
                $sourceCustomer->save();
                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'Successfully inserted Source Customer',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function insertReferenceCustomer(Request $request)
    {

        $request->validate([
            'referenceName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = ReferenceCustomer::where([
                ['referenceName', '=', $request->referenceName],
                ['isActive', '=', 1]
            ])
                ->first();

            if ($checkIfValueExits != null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Reference Customer title already exists, please choose another name',
                ]);
            } else {



                $referenceCustomer = new ReferenceCustomer();
                $referenceCustomer->referenceName = $request->referenceName;
                $referenceCustomer->isActive = 1;
                $referenceCustomer->save();
                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'Successfully inserted Reference Customer',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function getReferenceCustomer(Request $request)
    {

        try {

            $getRefrenceCustomer = ReferenceCustomer::select(
                'id as referenceCustomerId',
                'referenceName as referenceCustomerName',
            )
                ->where([
                    ['isActive', '=', 1],
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($getRefrenceCustomer, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function getPetCategory(Request $request)
    {

        try {

            $getCategory = PetCategory::select(
                'id as petCategoryId',
                'petCategoryName as petCategoryName',
            )->where([
                ['isActive', '=', 1],
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($getCategory, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }





    public function insertPetCategory(Request $request)
    {

        $request->validate([
            'petCategoryName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = PetCategory::where([
                ['petCategoryName', '=', $request->petCategoryName],
                ['isActive', '=', 1]
            ])
                ->first();

            if ($checkIfValueExits != null) {


                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Pet category name already exists, please choose another name',
                ]);
            } else {

                $petCategory = new PetCategory();
                $petCategory->petCategoryName = $request->petCategoryName;
                $petCategory->isActive = 1;
                $petCategory->save();
                DB::commit();

                return response()->json([
                    'result' => 'Success',
                    'message' => 'Successfully inserted Pet Category',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function getCustomerOccupation(Request $request)
    {

        try {

            $getRefrenceCustomer = CustomerOccupation::select(
                'id as occupationId',
                'occupationName as occupationName',
            )->where([
                ['isActive', '=', 1],
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($getRefrenceCustomer, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function insertCustomerOccupation(Request $request)
    {

        $request->validate([
            'occupationName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = CustomerOccupation::where([
                ['occupationName', '=', $request->occupationName],
                ['isActive', '=', 1]
            ])
                ->first();

            if ($checkIfValueExits != null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Jobname already exists, please choose another name',
                ]);
            } else {


                $customerOccupation = new CustomerOccupation();
                $customerOccupation->occupationName = $request->occupationName;
                $customerOccupation->isActive = 1;
                $customerOccupation->save();
                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'Successfully inserted Job Customer',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function getTitleCustomer(Request $request)
    {

        try {

            $getRefrenceCustomer = TitleCustomer::select(
                'id as titleCustomerId',
                'titleName as titleCustomerName',
            )->where([
                ['isActive', '=', 1],
            ])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json($getRefrenceCustomer, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function insertTitleCustomer(Request $request)
    {

        $request->validate([
            'titleName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = TitleCustomer::where([
                ['titleName', '=', $request->titleName],
                ['isActive', '=', 1]
            ])->first();

            if ($checkIfValueExits != null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Title Customer title already exists, please choose another name',
                ]);
            } else {

                $titleCustomer = new TitleCustomer();
                $titleCustomer->titleName = $request->titleName;
                $titleCustomer->isActive = 1;
                $titleCustomer->save();
                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'Successfully inserted Title Customer',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function getCustomerGroup()
    {
        $data = CustomerGroups::select('id', 'customerGroup')
            ->where('isDeleted', '=', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($data, 200);
    }

    public function createCustomerGroup(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'customerGroup' => 'required',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $checkIfValueExits = CustomerGroups::where('customerGroup', '=', $request->customerGroup)
            ->first();

        if ($checkIfValueExits === null) {

            $customerGroups = new CustomerGroups();
            $customerGroups->customerGroup = $request->customerGroup;
            $customerGroups->userId =  $request->user()->id;
            $customerGroups->save();
            DB::commit();

            return response()->json(
                [
                    'message' => 'Insert Data Successful!',
                ],
                200
            );
        } else {

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['Customer Group name already exists!'],
            ], 422);
        }
    }

    public function customerListWithLocation(Request $request)
    {
        $data = Customer::select('id', 'memberNo', 'firstName');

        if ($request->locationId) {
            $data = $data->where('locationId', '=', $request->locationId);
        }

        $data = $data->where('isDeleted', '=', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($data, 200);
    }

    public function petListWithCustomer(Request $request)
    {
        $data = CustomerPets::select('id', 'petName')
            ->where('customerId', '=', $request->customerId)
            ->where('isDeleted', '=', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($data, 200);
    }
}
