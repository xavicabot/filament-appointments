<?php

namespace XaviCabot\FilamentAppointments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use XaviCabot\FilamentAppointments\Support\SlotOwner;

class SlotsController extends Controller
{
    public function index(Request $request)
    {
        $date = (string) $request->query('date', '');
        $ownerType = (string) $request->query('owner_type', '');
        $ownerId = (int) $request->query('owner_id', 0);

        if (! $date || ! $ownerType || ! $ownerId) {
            return response()->json(['slots' => []]);
        }

        $owner = new SlotOwner($ownerType, $ownerId);

        $resolver = app('filament-appointments.resolver');
        $slots = $resolver->forDate($date, $owner);

        return response()->json(['slots' => $slots]);
    }
}
