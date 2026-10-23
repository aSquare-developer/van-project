<?php

namespace App\Services;

use App\Models\InspectionPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InspectionPhotoStore
{
    public static function rules(): array
    {
        return ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240', 'dimensions:min_width=64,min_height=64,max_width=8192,max_height=8192'];
    }

    public function store(User $actor, UploadedFile $file, string $purpose, string $field = 'photo'): InspectionPhoto
    {
        $validator = Validator::make(['photo' => $file], ['photo' => ['required', ...self::rules()]]);
        if ($validator->fails()) {
            throw ValidationException::withMessages([$field => $validator->errors()->get('photo')]);
        }
        $dimensions = @getimagesize($file->getRealPath());
        $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (! $dimensions || ! isset($extensions[$dimensions[2]]) || $dimensions[0] * $dimensions[1] > 32000000) {
            throw ValidationException::withMessages([$field => 'Use a JPEG, PNG or WebP photo of at most 32 megapixels.']);
        }
        $path = Storage::disk('inspection-photos')->putFileAs('images', $file, Str::uuid().'.'.$extensions[$dimensions[2]]);
        try {
            $photo = new InspectionPhoto;
            $photo->forceFill([
                'uploaded_by' => $actor->id, 'purpose' => $purpose, 'path' => $path,
                'mime' => $dimensions['mime'], 'width' => $dimensions[0], 'height' => $dimensions[1],
                'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            ])->save();

            return $photo;
        } catch (Throwable $exception) {
            Storage::disk('inspection-photos')->delete($path);
            throw $exception;
        }
    }

    public function discard(InspectionPhoto $photo): void
    {
        try {
            if (! Storage::disk('inspection-photos')->delete($photo->path)) {
                Log::warning('Could not clean up an uncommitted inspection photo.', ['photo_id' => $photo->id]);
            }
        } catch (Throwable) {
            Log::warning('Could not clean up an uncommitted inspection photo.', ['photo_id' => $photo->id]);
        }
    }
}
