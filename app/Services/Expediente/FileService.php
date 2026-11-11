<?php

namespace App\Services\Expediente;

use App\Models\ClinicalFile;
use App\Models\Organization;
use App\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Archivos clínicos en disco PRIVADO (storage/app/clinical/...). Nunca se exponen por URL pública:
 * la descarga pasa por una ruta firmada de corta vida.
 */
class FileService
{
    public const DISK = 'local';

    public const MAX_KB = 20480; // 20 MB

    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function store(Patient $patient, UploadedFile $file, string $type, ?string $displayName, string $memberId, ?string $encounterId = null): ClinicalFile
    {
        $orgId = Organization::currentId();
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $key = "clinical/{$orgId}/{$patient->id}/".Str::uuid().'.'.$ext;
        $name = $this->safeName($displayName ?: $file->getClientOriginalName());

        $sha = hash_file('sha256', $file->getRealPath());
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

        try {
            return ClinicalFile::query()->create([
                'patient_id' => $patient->id,
                'file_type' => $type,
                'display_name' => $name,
                'mime_type' => $mime,
                'size_bytes' => max(1, $size),
                'storage_key' => $key,
                'sha256' => $sha,
                'uploaded_by_member_id' => $memberId,
                'encounter_id' => $encounterId,
            ]);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($key);
            throw $e;
        }
    }

    /** Guarda contenido generado por el sistema (p. ej. documentos JSON) como archivo clínico. */
    public function storeContents(Patient $patient, string $contents, string $ext, string $mime, string $type, string $displayName, string $memberId): ClinicalFile
    {
        $orgId = Organization::currentId();
        $key = "clinical/{$orgId}/{$patient->id}/docs/".Str::uuid().'.'.$ext;
        Storage::disk(self::DISK)->put($key, $contents);

        try {
            return ClinicalFile::query()->create([
                'patient_id' => $patient->id,
                'file_type' => $type,
                'display_name' => $this->safeName($displayName),
                'mime_type' => $mime,
                'size_bytes' => max(1, strlen($contents)),
                'storage_key' => $key,
                'sha256' => hash('sha256', $contents),
                'uploaded_by_member_id' => $memberId,
            ]);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($key);
            throw $e;
        }
    }

    public function exists(ClinicalFile $file): bool
    {
        return Storage::disk(self::DISK)->exists($file->storage_key);
    }

    public function contents(ClinicalFile $file): ?string
    {
        return $this->exists($file) ? Storage::disk(self::DISK)->get($file->storage_key) : null;
    }

    /** Verifica el hash guardado contra el contenido real del archivo. */
    public function verifyIntegrity(ClinicalFile $file): bool
    {
        if (! $this->exists($file) || ! $file->sha256) {
            return false;
        }
        $path = Storage::disk(self::DISK)->path($file->storage_key);

        return hash_equals($file->sha256, hash_file('sha256', $path));
    }

    public function response(ClinicalFile $file, bool $inline = false): StreamedResponse
    {
        abort_unless($this->exists($file), 404, 'El archivo no se encuentra en el almacenamiento.');

        $headers = ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff'];
        $disk = Storage::disk(self::DISK);

        if ($inline && in_array($file->mime_type, self::ALLOWED_MIMES, true)) {
            return $disk->response($file->storage_key, $this->downloadName($file), $headers, 'inline');
        }

        return $disk->download($file->storage_key, $this->downloadName($file), $headers);
    }

    /** URL firmada válida 5 minutos. */
    public function signedUrl(ClinicalFile $file, bool $inline = false): string
    {
        return URL::temporarySignedRoute(
            'expediente.archivos.download',
            now()->addMinutes(5),
            array_filter(['file' => $file->id, 'inline' => $inline ? 1 : null])
        );
    }

    public function isImage(ClinicalFile $file): bool
    {
        return str_starts_with((string) $file->mime_type, 'image/');
    }

    /** Documento generado por el sistema (JSON): se ve en el visor de documentos, no se descarga crudo. */
    public function isGeneratedDocument(ClinicalFile $file): bool
    {
        return $file->mime_type === DocumentService::MIME;
    }

    public function extensionLabel(ClinicalFile $file): string
    {
        return match ($file->mime_type) {
            'image/jpeg' => 'JPG',
            'image/png' => 'PNG',
            'application/pdf' => 'PDF',
            DocumentService::MIME => 'DOC',
            default => strtoupper(pathinfo($file->storage_key, PATHINFO_EXTENSION) ?: 'ARCH'),
        };
    }

    private function downloadName(ClinicalFile $file): string
    {
        $ext = pathinfo($file->storage_key, PATHINFO_EXTENSION);
        $name = $file->display_name;

        return $ext && ! str_ends_with(strtolower($name), '.'.strtolower($ext)) ? $name.'.'.$ext : $name;
    }

    private function safeName(string $name): string
    {
        $name = trim(strip_tags($name));
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name) ?? $name;

        return Str::limit($name !== '' ? $name : 'Archivo', 250, '');
    }
}
