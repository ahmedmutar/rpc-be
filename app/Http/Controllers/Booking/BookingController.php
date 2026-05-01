<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\bookings;
use Illuminate\Http\Request;
use Validator;
use App\Models\bookingsPetHotel;
use App\Models\bookingsPetSalon;
use App\Models\bookingsBreeding;
use App\Models\bookingsPetClinic;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $data = DB::table('bookings as e')
            ->join('users as u', 'e.userId', 'u.id')
            ->join('customer as c', 'e.customerId', 'c.id')
            ->join('customerPets as p', 'e.petId', 'p.id')
            ->join('location as l', 'e.locationId', 'l.id')
            ->select([
                'e.id',
                // Perbaikan: Menggunakan DB::raw dengan CONCAT agar title tergabung dengan benar
                DB::raw("CONCAT(e.serviceType, ' - ', CASE WHEN c.firstName IS NOT NULL AND c.lastName IS NOT NULL THEN CONCAT(c.firstName, ' ', c.lastName) WHEN c.firstName IS NOT NULL THEN c.firstName WHEN c.lastName IS NOT NULL THEN c.lastName ELSE '' END, ' (', COALESCE(p.petName, ''), ')') as title"),
                'e.bookingTime as start',
                DB::raw("'' as `end`"), // Gunakan DB::raw untuk string kosong agar konsisten
                DB::raw("0 as allDay"),
                DB::raw("CASE e.serviceType
                WHEN 'Pet Hotel' THEN '#FF0000'
                WHEN 'Pet Salon' THEN '#FFFF00'
                WHEN 'Breeding' THEN '#008000'
                WHEN 'Pet Clinic' THEN '#0000FF'
                ELSE '#CCCCCC' END as color"),
                DB::raw("CASE e.serviceType
                WHEN 'Pet Hotel' THEN '#000000'
                WHEN 'Pet Salon' THEN '#000000'
                WHEN 'Breeding' THEN '#FFFFFF'
                WHEN 'Pet Clinic' THEN '#FFFFFF'
                ELSE '#000000' END as textColor"),

                DB::raw("'' as description")
            ])
            ->where('e.isDeleted', '=', 0);

        if ($request->filled('monthBooking') && $request->filled('yearBooking')) {
            $data = $data->whereMonth('e.bookingTime', $request->monthBooking)
                ->whereYear('e.bookingTime', $request->yearBooking);
        }
        // Filter Berdasarkan Lokasi
        if ($request->filled('locationId')) {
            $data = $data->where('e.locationId', $request->locationId);
        }

        if ($request->filled('doctorId')) {
            $data = $data->where('e.doctorId', $request->doctorId);
        }

        return response()->json([
            'data' => $data->get(),
        ]);
    }

    public function create(Request $request)
    {
        $baseRules = [
            'locationId'  => 'required|integer',
            'doctorId'  => 'required|integer',
            'customerId'  => 'required|integer',
            'petId'       => 'required|integer',
            'services'    => 'required|in:Pet Hotel,Pet Salon,Breeding,Pet Clinic',
            'bookingTime' => 'required|date',
        ];

        $service = $request->input('services');

        $extraRules = match ($service) {
            'Pet Hotel' => [
                'socializationType'   => 'required|string',
                'emergencyContactName'  => 'required|string',
                'inventoryProducts'      => 'required|string',
                'additionalInfo'   => 'nullable|string',
            ],
            'Pet Salon' => [
                'furCondition'  => 'required|string',
                'skinSensitivity'      => 'required|string',
                'additionalInfo'   => 'nullable|string',
            ],
            'Breeding' => [
                'stambum'  => 'required|string',
                'healthClearance'     => 'required|string',
                'additionalInfo'   => 'nullable|string',
            ],
            'Pet Clinic' => [
                'consultationType'     => 'required|string',
                'drugAllergy'         => 'nullable|string',
                'additionalInfo'   => 'nullable|string',
            ],
            default => [],
        };

        $validate = Validator::make($request->all(), array_merge($baseRules, $extraRules));

        if ($validate->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validate->errors()->all(),
            ], 422);
        }

        $booking = bookings::create([
            'locationId'  => $request->locationId,
            'doctorId'  => $request->doctorId,
            'customerId'  => $request->customerId,
            'petId'       => $request->petId,
            'serviceType'    => $request->services,
            'bookingTime' => $request->bookingTime,
            'userId' => $request->user()->id,
        ]);

        if ($request->services === 'Pet Hotel') {
            bookingsPetHotel::create([
                'bookingId' => $booking->id,
                'socializationType' => $request->socializationType,
                'emergencyContactName' => $request->emergencyContactName,
                'inventoryProducts' => $request->inventoryProducts,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        } else if ($request->services === 'Pet Salon') {
            bookingsPetSalon::create([
                'bookingId' => $booking->id,
                'furCondition' => $request->furCondition,
                'skinSensitivity' => $request->skinSensitivity,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        } else if ($request->services === 'Breeding') {
            bookingsBreeding::create([
                'bookingId' => $booking->id,
                'stambum' => $request->stambum,
                'healthClearance' => $request->healthClearance,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        } else if ($request->services === 'Pet Clinic') {
            bookingsPetClinic::create([
                'bookingId' => $booking->id,
                'consultationType' => $request->consultationType,
                'drugAllergy' => $request->drugAllergy,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        }

        return responseCreate();
    }

    public function detail(Request $request)
    {
        $booking = DB::table('bookings as e')
            ->join('users as u', 'e.userId', '=', 'u.id')
            ->join('customer as c', 'e.customerId', '=', 'c.id')
            ->join('customerPets as p', 'e.petId', '=', 'p.id')
            ->join('location as l', 'e.locationId', '=', 'l.id')
            ->leftJoin('users as d', 'e.doctorId', '=', 'd.id')
            ->select([
                'e.id',
                'e.serviceType',
                'e.bookingTime',
                'e.isCancelled',
                'e.cancellationReason',
                'e.canceledByName',
                'e.cancellationDate',
                'c.id as customerId',
                'c.firstName as customerFirstName',
                'c.lastName as customerLastName',
                DB::raw("CONCAT(c.firstName, ' ', c.lastName) as customerFullName"),
                'p.id as petId',
                'p.petName',
                'l.id as locationId',
                'l.locationName',
                'd.id as doctorId',
                DB::raw("CONCAT(d.firstName, ' ', d.lastName) as doctorName"),
                'u.id as createdByUserId',
                DB::raw("CONCAT(u.firstName, ' ', u.lastName) as createdByUserName"),
            ])
            ->where('e.id', $request->id)
            ->where('e.isDeleted', 0)
            ->first();

        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        $detail = match ($booking->serviceType) {
            'Pet Hotel'  => bookingsPetHotel::where('bookingId', $booking->id)->first(),
            'Pet Salon'  => bookingsPetSalon::where('bookingId', $booking->id)->first(),
            'Breeding'   => bookingsBreeding::where('bookingId', $booking->id)->first(),
            'Pet Clinic' => bookingsPetClinic::where('bookingId', $booking->id)->first(),
            default      => null,
        };

        return response()->json([
            'data' => [
                'booking' => $booking,
                'detail'  => $detail,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $baseRules = [
            'id'          => 'required|integer',
            'locationId'  => 'required|integer',
            'doctorId'  => 'required|integer',
            'customerId'  => 'required|integer',
            'petId'       => 'required|integer',
            'services'    => 'required|in:Pet Hotel,Pet Salon,Breeding,Pet Clinic',
            'bookingTime' => 'required|date',
        ];

        // Use $request->json() consistently everywhere
        $data    = $request->json()->all();
        $service = $data['services'] ?? null;

        $extraRules = match ($service) {
            'Pet Hotel' => [
                'socializationType'    => 'required|string',
                'emergencyContactName' => 'required|string',
                'inventoryProducts'    => 'required|string',
                'additionalInfo'       => 'nullable|string',
            ],
            'Pet Salon' => [
                'furCondition'    => 'required|string',
                'skinSensitivity' => 'required|string',
                'additionalInfo'  => 'nullable|string',
            ],
            'Breeding' => [
                'stambum'        => 'required|string',
                'healthClearance' => 'required|string',
                'additionalInfo'  => 'nullable|string',
            ],
            'Pet Clinic' => [
                'consultationType' => 'required|string',
                'drugAllergy'      => 'nullable|string',
                'additionalInfo'   => 'nullable|string',
            ],
            default => [],
        };

        $validate = Validator::make($data, array_merge($baseRules, $extraRules));

        if ($validate->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validate->errors()->all(),
            ], 422);
        }

        $booking = bookings::find($data['id']);
        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        $booking->update([
            'locationId'   => $data['locationId'],
            'doctorId'   => $data['doctorId'],
            'customerId'   => $data['customerId'],
            'petId'        => $data['petId'],
            'serviceType'  => $data['services'],
            'bookingTime'  => $data['bookingTime'],
            'userUpdateId' => $request->user()->id,
        ]);

        if ($service === 'Pet Hotel') {
            $bookingDetail = bookingsPetHotel::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'socializationType'    => $data['socializationType'],
                    'emergencyContactName' => $data['emergencyContactName'],
                    'inventoryProducts'    => $data['inventoryProducts'],
                    'additionalInfo'       => $data['additionalInfo'] ?? null,
                    'userUpdateId'         => $request->user()->id,
                ]);
            }
        } elseif ($service === 'Pet Salon') {
            $bookingDetail = bookingsPetSalon::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'furCondition'    => $data['furCondition'],
                    'skinSensitivity' => $data['skinSensitivity'],
                    'additionalInfo'  => $data['additionalInfo'] ?? null,
                    'userUpdateId'    => $request->user()->id,
                ]);
            }
        } elseif ($service === 'Breeding') {
            $bookingDetail = bookingsBreeding::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'stambum'         => $data['stambum'],
                    'healthClearance' => $data['healthClearance'],
                    'additionalInfo'  => $data['additionalInfo'] ?? null,
                    'userUpdateId'    => $request->user()->id,
                ]);
            }
        } elseif ($service === 'Pet Clinic') {
            $bookingDetail = bookingsPetClinic::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'consultationType' => $data['consultationType'],
                    'drugAllergy'      => $data['drugAllergy'] ?? null,
                    'additionalInfo'   => $data['additionalInfo'] ?? null,
                    'userUpdateId'     => $request->user()->id,
                ]);
            }
        }

        return responseUpdate();
    }

    public function cancelBooking(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
            'cancellationReason' => 'required|string',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validate->errors()->all(),
            ], 422);
        }

        $booking = bookings::where('id', $request->id)->where('isDeleted', false)->first();
        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        $booking->update([
            'isCancelled' => true,
            'cancellationReason' => $request->cancellationReason,
            'canceledByName' => $request->user()->name,
            'cancellationDate' => now(),
            'userUpdateId' => $request->user()->id,
        ]);

        return responseUpdate();
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
            $res = bookings::find($va);

            if (!$res) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Booking not found!'],
                ], 422);
            }
        }

        foreach ($request->id as $va) {
            $booking = bookings::find($va);
            $booking->isDeleted = true;
            $booking->deletedBy = $request->user()->name;
            $booking->deletedAt = now();
            $booking->save();
        }

        return responseDelete();
    }
}
