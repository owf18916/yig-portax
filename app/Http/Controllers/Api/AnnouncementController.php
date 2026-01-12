<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    /**
     * Get active announcements
     * GET /api/announcements
     */
    public function index()
    {
        try {
            $announcements = Announcement::active()
                ->orderBy('published_at', 'desc')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'message' => 'Announcements retrieved successfully',
                'data' => $announcements
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve announcements: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create announcement
     * POST /api/announcements
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'type' => ['required', Rule::in(['info', 'success', 'warning', 'error'])],
                'is_active' => 'boolean',
                'published_at' => 'nullable|date',
                'expires_at' => 'nullable|date|after:published_at'
            ]);

            $validated['created_by'] = auth()->id();
            $validated['updated_by'] = auth()->id();
            $validated['is_active'] = $validated['is_active'] ?? true;

            $announcement = Announcement::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Announcement created successfully',
                'data' => $announcement
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create announcement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single announcement
     * GET /api/announcements/{id}
     */
    public function show($id)
    {
        try {
            $announcement = Announcement::findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Announcement retrieved successfully',
                'data' => $announcement
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Announcement not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve announcement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update announcement
     * PUT /api/announcements/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $announcement = Announcement::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'content' => 'sometimes|required|string',
                'type' => ['sometimes', 'required', Rule::in(['info', 'success', 'warning', 'error'])],
                'is_active' => 'sometimes|boolean',
                'published_at' => 'nullable|date',
                'expires_at' => 'nullable|date|after:published_at'
            ]);

            $validated['updated_by'] = auth()->id();

            $announcement->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Announcement updated successfully',
                'data' => $announcement
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Announcement not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update announcement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete announcement (soft delete)
     * DELETE /api/announcements/{id}
     */
    public function destroy($id)
    {
        try {
            $announcement = Announcement::findOrFail($id);
            $announcement->delete();

            return response()->json([
                'success' => true,
                'message' => 'Announcement deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Announcement not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete announcement: ' . $e->getMessage()
            ], 500);
        }
    }
}
