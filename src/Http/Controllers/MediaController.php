<?php

declare(strict_types=1);

namespace Waypost\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Waypost\Auth\Caller;
use Waypost\Enums\MediaOwnerType;
use Waypost\Http\Entities;
use Waypost\Http\Exceptions\ApiException;
use Waypost\Http\SignedUrls;
use Waypost\Models\Checkpoint;
use Waypost\Models\Media;
use Waypost\Support\Time;
use Waypost\Support\Uuid;
use Waypost\Validation\SchemaValidator;

/**
 * PUT /media/{id} (spec: Szinkron protokoll / Push és média): a photo of a chain entry, whose row the accepted entry
 * created, or of a checkpoint, whose row the upload creates (the checkpoint request comes later and refers to it). The
 * file must have the SHA-256 the request tells and the row knows. The same file again changes nothing.
 */
final class MediaController extends Controller
{
    private const string JPEG = 'image/jpeg';

    private const string DIRECTORY = 'waypost/media';

    /** @return array{media: array<string, mixed>} */
    public function upload(Request $request, string $media, Caller $caller, SchemaValidator $validator): array
    {
        if (!Uuid::isV7($media)) {
            throw ApiException::notFound();
        }
        $fields = $request->request->all();
        $validator->assertValid((object) $fields, 'api/media-upload.request');
        $file = self::jpeg($request);
        $sha256 = (string) hash_file('sha256', $file->getPathname());
        if (!hash_equals($fields['sha256'], $sha256)) {
            self::invalid('sha256', 'A fájl lenyomata nem egyezik a megadottal.');
        }

        $owner = MediaOwnerType::from($fields['owner_type']);
        $photo = $owner === MediaOwnerType::Checkpoint
            ? $this->checkpointPhoto($media, $fields['owner_id'], $sha256, $caller)
            : $this->entryPhoto($media, $owner, $fields['owner_id'], $caller);
        if (!hash_equals($photo->sha256, $sha256)) {
            self::invalid('sha256', 'A fájl lenyomata nem egyezik a bejegyzésben rögzítettel.');
        }

        if ($photo->uploaded_at === null) {
            $now = Time::now();
            $takenAt = Time::fromIso($fields['taken_at']);
            $photo->fill([
                'mime' => self::JPEG,
                'size_bytes' => $file->getSize(),
                'path' => self::store($file, $media),
                // Not after the upload: the photo's link lives and its file is kept by taken_at (SignedUrls::photo(),
                // PruneExpiredData), and the retention is the server's rule, not the phone clock's.
                'taken_at' => $takenAt->isAfter($now) ? $now : $takenAt,
                'uploaded_at' => $now,
            ])->save();
        }

        return ['media' => Entities::media($photo)];
    }

    /** An uploaded photo, by a signed URL of a report (SignedUrls::photo()). */
    public function file(Request $request, string $media): StreamedResponse
    {
        SignedUrls::assertValid($request);
        $photo = Media::query()->find($media);
        $disk = Storage::disk(config('waypost.storage.disk'));
        if ($photo?->path === null || !$disk->exists($photo->path)) {
            throw ApiException::notFound();
        }

        return $disk->response($photo->path, basename($photo->path), [
            'Content-Type' => $photo->mime ?? self::JPEG,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** The file part: only JPEG, which is what the app saves. Over PHP's upload limit: 413. */
    private static function jpeg(Request $request): UploadedFile
    {
        $file = $request->file('file');
        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            self::invalid('file', 'Kötelező mező.');
        }
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new HttpException(413);
        }
        if ($file->getError() !== UPLOAD_ERR_OK || $file->getMimeType() !== self::JPEG) {
            self::invalid('file', 'Csak JPEG fájl tölthető fel.');
        }

        return $file;
    }

    private function entryPhoto(string $id, MediaOwnerType $owner, string $ownerId, Caller $caller): Media
    {
        $photo = Media::query()->find($id) ?? throw ApiException::notFound();
        if ($caller->device === null || $photo->device_id !== $caller->device->id) {
            throw ApiException::forbidden('Ezt a fotót egy másik telefon készítette.');
        }
        self::assertOwner($photo, $owner, $ownerId);

        return $photo;
    }

    /** A supervisor's, on a checkpoint they manage if it exists already. */
    private function checkpointPhoto(string $id, string $checkpointId, string $sha256, Caller $caller): Media
    {
        if (!$caller->isSupervisor()) {
            throw ApiException::forbidden('A pont fotóját csak supervisor töltheti fel.');
        }
        $checkpoint = Checkpoint::query()->find($checkpointId);
        if ($checkpoint !== null) {
            $this->gate->authorize('update', $checkpoint);
        }

        $photo = Media::query()->find($id);
        if ($photo !== null) {
            self::assertOwner($photo, MediaOwnerType::Checkpoint, $checkpointId);

            return $photo;
        }

        return new Media([
            'id' => $id,
            'device_id' => $caller->device?->id,
            'owner_type' => Checkpoint::class,
            'owner_id' => $checkpointId,
            'sha256' => $sha256,
        ]);
    }

    private static function assertOwner(Media $photo, MediaOwnerType $owner, string $ownerId): void
    {
        if ($photo->owner_type !== $owner->model() || $photo->owner_id !== $ownerId) {
            self::invalid('owner_id', 'A fotó nem ehhez tartozik.');
        }
    }

    private static function store(UploadedFile $file, string $id): string
    {
        $disk = Storage::disk(config('waypost.storage.disk'));

        // Explicitly private: a host's disk (S3) may default to public.
        return $disk->putFileAs(self::DIRECTORY, $file, "{$id}.jpg", 'private')
            ?: throw new RuntimeException("The photo {$id} could not be stored.");
    }
}
