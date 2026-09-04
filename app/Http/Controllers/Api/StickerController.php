<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sticker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StickerController extends Controller
{
    /**
     * List all stickers for the authenticated tenant.
     */
    public function index(Request $request)
    {
        $stickers = Sticker::orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json($stickers);
    }

    /**
     * Upload and store a new sticker for the authenticated tenant.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:webp|max:2048', // Allow up to 2 MB (WhatsApp stickers are ideally <= 500 KB, but animated stickers can reach 1-2 MB)
            'name' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $tenantId = $request->user()->tenant_id;
        
        $filename = uniqid() . '.webp';
        $relativePath = "stickers/{$tenantId}";
        
        $disk = config('filesystems.media_disk', 'public');
        $path = Storage::disk($disk)->putFileAs($relativePath, $file, $filename);
        
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $sticker = Sticker::create([
            'tenant_id' => $tenantId,
            'name' => $request->input('name') ?: $originalName,
            'category' => strtolower($request->input('category') ?: 'general'),
            'file_path' => 'storage/' . $path,
            'mime_type' => 'image/webp',
        ]);

        return response()->json([
            'message' => 'Sticker uploaded successfully.',
            'sticker' => $sticker
        ], 201);
    }

    /**
     * Download/Stream a sticker's WebP file via authenticated API to prevent CORS issues.
     */
    public function getFile($id)
    {
        $sticker = Sticker::findOrFail($id);

        $disk = config('filesystems.media_disk', 'public');
        $relativePath = str_replace('storage/', '', $sticker->file_path);

        if (!Storage::disk($disk)->exists($relativePath)) {
            abort(404, 'Sticker file not found');
        }

        $fullPath = Storage::disk($disk)->path($relativePath);

        if (file_exists($fullPath)) {
            return response()->file($fullPath, [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'max-age=86400, public'
            ]);
        }

        return Storage::disk($disk)->response($relativePath, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'max-age=86400, public'
        ]);
    }

    /**
     * Delete a sticker from the library and remove its physical file.
     */
    public function destroy($id)
    {
        $sticker = Sticker::findOrFail($id);
        
        // Remove physical file from disk
        $disk = config('filesystems.media_disk', 'public');
        $relativePath = str_replace('storage/', '', $sticker->file_path);
        
        if (Storage::disk($disk)->exists($relativePath)) {
            Storage::disk($disk)->delete($relativePath);
        }
        
        $sticker->delete();
        
        return response()->json([
            'message' => 'Sticker deleted successfully.'
        ]);
    }
}
