<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'nullable|string',
            'body' => 'nullable|string',
            'target_type' => 'nullable|string',
            'target_id' => 'nullable',
        ]);

        $body = $request->message ?? $request->body ?? '';

        $announcement = Announcement::create([
            'author_user_id' => $request->user()?->id ?? 1,
            'scope' => Announcement::SCOPE_SCHOOL,
            'section_id' => $request->target_type === 'section' ? $request->target_id : null,
            'title' => $request->title,
            'body' => $body,
            'published_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال الإشعار بنجاح',
            'data' => $announcement,
        ], 201);
    }
}
