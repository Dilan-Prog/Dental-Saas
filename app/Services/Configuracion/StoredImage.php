<?php

namespace App\Services\Configuracion;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Almacena y sirve imágenes de configuración (logo, avatar, firma) en el disco
 * privado: nunca quedan accesibles por URL pública; se sirven por rutas con
 * autenticación y cabeceras seguras.
 */
class StoredImage
{
    public const DISK = 'local';

    /** Extensión => mime permitidos. */
    private const MIMES = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
    ];

    /**
     * Devuelve un mensaje de error en español o null si el archivo es válido.
     *
     * @param  array<int, string>  $allowed  extensiones permitidas
     */
    public static function validate(UploadedFile $file, array $allowed, int $maxKb = 2048, ?int $maxSide = 2000): ?string
    {
        if (! $file->isValid()) {
            return 'No se pudo subir el archivo.';
        }

        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, $allowed, true)) {
            return 'El archivo debe ser de tipo: '.implode(', ', array_map('strtoupper', $allowed)).'.';
        }

        if ($file->getSize() > $maxKb * 1024) {
            return 'El archivo no debe pesar más de '.round($maxKb / 1024, 1).' MB.';
        }

        $mime = (string) $file->getMimeType();

        if (! in_array($mime, self::MIMES[$ext] ?? [], true)) {
            return 'El contenido del archivo no corresponde a una imagen '.strtoupper($ext).' válida.';
        }

        if ($ext === 'svg') {
            $content = (string) @file_get_contents($file->getRealPath());

            if (! preg_match('/<svg[\s>]/i', $content)) {
                return 'El archivo SVG no es válido.';
            }

            if (preg_match('/<script|javascript:|on[a-z]+\s*=|<foreignObject|<iframe|<!ENTITY/i', $content)) {
                return 'El SVG contiene elementos no permitidos (scripts o contenido externo).';
            }

            return null;
        }

        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            return 'El archivo no es una imagen válida.';
        }

        if ($maxSide && ($info[0] > $maxSide || $info[1] > $maxSide)) {
            return "La imagen no debe superar {$maxSide} px por lado.";
        }

        return null;
    }

    public static function store(UploadedFile $file, string $dir): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return $file->storeAs($dir, Str::uuid().'.'.$ext, self::DISK);
    }

    public static function delete(?string $key): void
    {
        if ($key && Storage::disk(self::DISK)->exists($key)) {
            Storage::disk(self::DISK)->delete($key);
        }
    }

    public static function exists(?string $key): bool
    {
        return $key && Storage::disk(self::DISK)->exists($key);
    }

    /** Respuesta inline con cabeceras que impiden ejecutar contenido. */
    public static function response(?string $key): StreamedResponse
    {
        abort_unless(self::exists($key), 404);

        $disk = Storage::disk(self::DISK);
        $mime = $disk->mimeType($key) ?: 'application/octet-stream';

        if (str_ends_with(strtolower($key), '.svg')) {
            $mime = 'image/svg+xml';
        }

        return $disk->response($key, null, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
