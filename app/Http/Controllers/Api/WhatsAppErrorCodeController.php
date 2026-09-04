<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppErrorCode;
use App\Services\WhatsAppErrorService;
use Illuminate\Http\Request;

class WhatsAppErrorCodeController extends Controller
{
    /**
     * List all known WhatsApp error codes with optional category or search filters.
     */
    public function index(Request $request)
    {
        $query = WhatsAppErrorCode::query();

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('details', 'like', "%{$search}%")
                  ->orWhere('possible_reasons', 'like', "%{$search}%")
                  ->orWhere('possible_solutions', 'like', "%{$search}%");
            });
        }

        $codes = $query->orderBy('category')->orderBy('code')->get();

        return response()->json([
            'success' => true,
            'count' => $codes->count(),
            'error_codes' => $codes,
        ]);
    }

    /**
     * Inspect and explain a specific error code or payload.
     */
    public function lookup(Request $request, $code)
    {
        $subcode = $request->query('subcode');
        $details = $request->query('details');

        $resolved = WhatsAppErrorService::lookup($code, $subcode, $details);

        return response()->json([
            'success' => true,
            'resolved' => $resolved,
        ]);
    }
}
