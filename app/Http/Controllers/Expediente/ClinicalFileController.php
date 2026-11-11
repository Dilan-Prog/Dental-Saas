<?php

namespace App\Http\Controllers\Expediente;

use App\Http\Requests\Expediente\StoreClinicalFileRequest;
use App\Models\ClinicalFile;
use App\Models\Patient;
use App\Services\Expediente\FileService;
use Illuminate\Http\Request;

/** Archivos clínicos: carga, descarga por URL firmada y baja lógica. */
class ClinicalFileController extends BaseController
{
    public function __construct(private FileService $files)
    {
    }

    public function store(StoreClinicalFileRequest $request, Patient $patient)
    {
        $data = $request->validated();

        return $this->attempt(
            fn () => $this->files->store(
                $patient,
                $request->file('file'),
                $data['file_type'],
                $data['display_name'] ?? null,
                $this->memberId($request),
                $data['encounter_id'] ?? null
            ),
            'Archivo subido correctamente.',
            $this->toPatient($patient, 'archivos')
        );
    }

    /** Ruta firmada (expira en 5 min): expediente.archivos.download. */
    public function download(Request $request, ClinicalFile $file)
    {
        abort_if($this->files->isGeneratedDocument($file), 404);

        return $this->files->response($file, $request->boolean('inline'));
    }

    public function destroy(Patient $patient, ClinicalFile $file)
    {
        abort_unless($file->patient_id === $patient->id, 404);

        $file->delete(); // baja lógica: el archivo físico se conserva por trazabilidad clínica

        return redirect($this->toPatient($patient, 'archivos'))->with('success', 'Archivo dado de baja.');
    }
}
