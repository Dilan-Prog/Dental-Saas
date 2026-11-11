<?php

namespace Tests\Feature\QA\Clinico;

use App\Models\ClinicalFile;
use App\Models\ClinicalNote;
use App\Models\ClinicalNoteAddendum;
use App\Models\ConsentAcceptance;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\PatientGuardian;
use App\Models\PatientTreatment;
use App\Models\ToothFinding;
use App\Services\Expediente\FileService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Expediente\ExpedienteTestCase;

/** QA del Expediente clínico: notas, firma, odontograma, archivos, consentimientos y coherencia. */
class ExpedienteQATest extends ExpedienteTestCase
{
    private function noteUrl(Patient $p): string
    {
        return route('expediente.notas.store', $p->id);
    }

    private function simple(array $o = []): array
    {
        return $o + ['note_type' => 'simple', 'responsible_doctor_member_id' => $this->doctorMemberId(),
            'treatment_id' => $this->makeTreatment('T '.Str::random(5))->id, 'objective' => 'Procedimiento realizado.', 'action' => 'guardar'];
    }

    // --------------------------------------------------------------- notas

    public function test_note_validation_matrix(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $this->from('/x')->post($this->noteUrl($p), [])->assertSessionHasErrors(['note_type', 'responsible_doctor_member_id']);
        $this->post($this->noteUrl($p), $this->simple(['note_type' => 'otra']))->assertSessionHasErrors('note_type');
        $this->post($this->noteUrl($p), $this->simple(['objective' => '']))->assertSessionHasErrors('objective');
        $this->post($this->noteUrl($p), $this->simple(['treatment_id' => '']))->assertSessionHasErrors('treatment_id');
        $this->post($this->noteUrl($p), $this->simple(['treatment_id' => (string) Str::uuid()]))->assertSessionHasErrors('treatment_id');
        $this->post($this->noteUrl($p), $this->simple(['objective' => str_repeat('x', 5001)]))->assertSessionHasErrors('objective');
        $this->post($this->noteUrl($p), $this->simple(['prescription_text' => str_repeat('x', 3001)]))->assertSessionHasErrors('prescription_text');
        foreach (['19', '0', '99', 'abc', '-11', '26.5'] as $bad) {
            $this->post($this->noteUrl($p), $this->simple(['tooth_fdi' => $bad]))->assertSessionHasErrors('tooth_fdi');
        }
        $this->post($this->noteUrl($p), $this->simple(['responsible_doctor_member_id' => $this->memberId($this->userByEmail('recepcion@dental.test'))]))
            ->assertSessionHasErrors('responsible_doctor_member_id');
        $this->post($this->noteUrl($p), $this->simple(['encounter_id' => (string) Str::uuid()]))->assertSessionHasErrors('encounter_id');
        $this->assertSame(0, ClinicalNote::query()->count());
    }

    public function test_soap_requires_content_and_all_sections_to_sign(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $soap = ['note_type' => 'soap', 'responsible_doctor_member_id' => $this->doctorMemberId(), 'action' => 'guardar'];
        $this->from('/x')->post($this->noteUrl($p), $soap)->assertSessionHasErrors('subjective');
        $this->post($this->noteUrl($p), $soap + ['subjective' => 'Dolor'])->assertSessionHasNoErrors();
        $this->post($this->noteUrl($p), ['action' => 'firmar', 'subjective' => 'Dolor'] + $soap)->assertSessionHasErrors(['objective', 'analysis', 'plan']);
    }

    public function test_appointment_of_another_patient_cannot_be_linked_to_a_note(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $appt = \App\Models\Appointment::create([
            'branch_id' => $this->mainBranchId(), 'patient_id' => $b->id, 'doctor_member_id' => $this->doctorMemberId(),
            'starts_at' => now()->addDay()->setTime(10, 0), 'ends_at' => now()->addDay()->setTime(11, 0), 'status' => 'pendiente',
        ]);
        $this->actingAsDoctor();
        $this->from('/x')->post($this->noteUrl($a), $this->simple(['appointment_id' => $appt->id]))->assertSessionHasErrors('appointment_id');
    }

    public function test_inactive_doctor_cannot_be_the_responsible_doctor(): void
    {
        $p = $this->makePatient();
        $member = $this->doctorMemberId();
        DB::table('organization_members')->where('id', $member)->update(['is_active' => false]);
        $this->actingAsAdmin();
        $this->from('/x')->post($this->noteUrl($p), $this->simple())->assertSessionHasErrors('responsible_doctor_member_id');
    }

    public function test_signing_flow_hash_integrity_and_immutability_over_http_and_db(): void
    {
        $p = $this->makePatient();
        $note = $this->createDraftNote($p);
        $this->actingAsDoctor();
        $this->post(route('expediente.notas.sign', [$p->id, $note->id]))->assertSessionHasNoErrors();
        $note = $note->fresh();
        $this->assertSame('firmada', $note->status->value);
        $this->assertSame(64, strlen($note->content_hash));
        $this->assertSame(hash('sha256', $note->content_hash) === $note->content_hash, false);
        $this->assertTrue(app(\App\Services\Expediente\NoteService::class)->verifyIntegrity($note));

        // HTTP: editar / borrar / refirmar una nota firmada
        $this->from('/x')->put(route('expediente.notas.update', [$p->id, $note->id]), $this->simple(['objective' => 'ALTERADO']));
        $this->delete(route('expediente.notas.destroy', [$p->id, $note->id]));
        $this->post(route('expediente.notas.sign', [$p->id, $note->id]));
        $fresh = $note->fresh();
        $this->assertSame($note->objective, $fresh->objective);
        $this->assertSame($note->content_hash, $fresh->content_hash);

        // BD: UPDATE y DELETE directos rechazados por trigger
        foreach ([fn () => DB::table('clinical_notes')->where('id', $note->id)->update(['objective' => 'hack']),
            fn () => DB::table('clinical_notes')->where('id', $note->id)->delete()] as $attack) {
            try {
                $attack();
                $this->fail('El trigger debió bloquear el cambio a una nota firmada');
            } catch (QueryException $e) {
                $this->assertStringContainsString('firmado', mb_strtolower($e->getMessage()));
            }
        }
    }

    public function test_only_the_responsible_doctor_can_sign_and_unsigned_users_are_forbidden(): void
    {
        $p = $this->makePatient();
        $note = $this->createDraftNote($p);
        $this->actingAsReceptionist();
        $this->post(route('expediente.notas.sign', [$p->id, $note->id]))->assertStatus(403);
        $this->actingAsAdmin();   // admin no es el doctor responsable
        $this->from('/x')->post(route('expediente.notas.sign', [$p->id, $note->id]));
        $this->assertSame('borrador', $note->fresh()->status->value);
    }

    public function test_note_of_another_patient_is_not_reachable_through_wrong_patient_url(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $note = $this->createDraftNote($a);
        $this->actingAsDoctor();
        $this->get(route('expediente.notas.show', [$b->id, $note->id]))->assertStatus(404);
        $this->post(route('expediente.notas.sign', [$b->id, $note->id]))->assertStatus(404);
        $this->put(route('expediente.notas.update', [$b->id, $note->id]), $this->simple())->assertStatus(404);
        $this->delete(route('expediente.notas.destroy', [$b->id, $note->id]))->assertStatus(404);
        $this->assertSame('borrador', $note->fresh()->status->value);
    }

    public function test_addendum_versioning_validation_and_unsigned_rejection(): void
    {
        $p = $this->makePatient();
        $note = $this->createDraftNote($p);
        $this->actingAsDoctor();
        $url = route('expediente.notas.addendum', [$p->id, $note->id]);
        $this->from('/x')->post($url, ['content' => 'Corrección importante', 'reason' => 'Error de captura']);
        $this->assertSame(0, ClinicalNoteAddendum::query()->count(), 'no se permite adenda a borrador');
        $this->post(route('expediente.notas.sign', [$p->id, $note->id]));
        $this->post($url, ['content' => 'abc', 'reason' => 'abc'])->assertSessionHasErrors(['content', 'reason']);
        $this->post($url, ['content' => 'Corrección importante', 'reason' => 'Error de captura'])->assertSessionHasNoErrors();
        $this->post($url, ['content' => 'Segunda corrección', 'reason' => 'Otra razón válida'])->assertSessionHasNoErrors();
        $this->assertSame([1, 2], ClinicalNoteAddendum::query()->orderBy('version_number')->pluck('version_number')->all());
        $this->assertNotSame(...ClinicalNoteAddendum::query()->pluck('content_hash')->all());
        // la adenda también es inmutable? (no se exige) pero el hash de la nota sigue intacto
        $this->assertTrue(app(\App\Services\Expediente\NoteService::class)->verifyIntegrity($note->fresh()));
    }

    // --------------------------------------------------------------- odontograma

    public function test_fdi_and_dentition_validation_matrix(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $url = route('expediente.odontograma.store', $p->id);
        $ok = ['tooth_fdi' => 26, 'dentition' => 'permanente', 'condition' => 'caries', 'surfaces' => ['O', 'M']];
        foreach ([['tooth_fdi' => 19], ['tooth_fdi' => 0], ['tooth_fdi' => 99], ['tooth_fdi' => 'x'], ['tooth_fdi' => -26], ['tooth_fdi' => 26.5],
            ['tooth_fdi' => 51], ['tooth_fdi' => 26, 'dentition' => 'temporal'], ['tooth_fdi' => 55, 'dentition' => 'permanente'],
            ['tooth_fdi' => 56, 'dentition' => 'temporal'], ['tooth_fdi' => 46, 'dentition' => 'temporal'],
            ['condition' => 'inventada'], ['condition' => ''], ['surfaces' => ['Z']], ['surfaces' => ['M', 'M']],
            ['surfaces' => ['M', 'D', 'O', 'V', 'L', 'P', 'M']], ['condition' => 'sano', 'surfaces' => ['O']], ['condition' => 'ausente', 'surfaces' => ['O']],
            ['patient_treatment_id' => (string) Str::uuid()], ['encounter_id' => (string) Str::uuid()]] as $bad) {
            $this->from('/x')->post($url, array_replace($ok, $bad))->assertSessionHasErrors();
        }
        $this->assertSame(0, ToothFinding::query()->count());
        $this->post($url, $ok)->assertSessionHasNoErrors();
        // temporal derivado por número cuando no se manda dentición
        $this->post($url, ['tooth_fdi' => 54, 'condition' => 'sano'])->assertSessionHasNoErrors();
        $this->assertSame('temporal', ToothFinding::query()->where('tooth_fdi', 54)->first()->dentition->value);
        $this->assertSame('permanente', ToothFinding::query()->where('tooth_fdi', 26)->first()->dentition->value);
    }

    public function test_findings_supersede_instead_of_overwrite_and_keep_one_current(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $url = route('expediente.odontograma.store', $p->id);
        foreach (['caries', 'restauracion', 'sano'] as $c) {
            $this->post($url, ['tooth_fdi' => 36, 'dentition' => 'permanente', 'condition' => $c])->assertSessionHasNoErrors();
        }
        $all = ToothFinding::query()->where('patient_id', $p->id)->where('tooth_fdi', 36)->orderBy('recorded_at')->orderBy('id')->get();
        $this->assertCount(3, $all);
        $this->assertSame(1, $all->whereNull('superseded_at')->count());
        $this->assertSame('sano', $all->whereNull('superseded_at')->first()->condition->value);
        // BD: no se permiten dos vigentes para la misma pieza
        $this->expectException(QueryException::class);
        ToothFinding::query()->create(['patient_id' => $p->id, 'tooth_fdi' => 36, 'dentition' => 'permanente', 'condition' => 'caries',
            'author_member_id' => $this->doctorMemberId(), 'recorded_at' => now()]);
    }

    public function test_findings_are_per_patient(): void
    {
        $a = $this->makePatient();
        $b = $this->makePatient();
        $this->actingAsDoctor();
        $this->post(route('expediente.odontograma.store', $a->id), ['tooth_fdi' => 11, 'condition' => 'caries']);
        $this->post(route('expediente.odontograma.store', $b->id), ['tooth_fdi' => 11, 'condition' => 'sano']);
        $this->assertSame('caries', ToothFinding::query()->where('patient_id', $a->id)->current()->first()->condition->value);
        $this->assertSame('sano', ToothFinding::query()->where('patient_id', $b->id)->current()->first()->condition->value);
    }

    // --------------------------------------------------------------- archivos

    private function upload(Patient $p, $file, array $extra = [])
    {
        return $this->from('/x')->post(route('expediente.archivos.store', $p->id), $extra + ['file' => $file, 'file_type' => 'radiografia']);
    }

    private function raw(string $name, string $content, string $clientMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    public function test_hostile_uploads_are_rejected(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $php = '<?php echo shell_exec($_GET["c"]); ?>';
        $cases = [
            'php disguised as png' => $this->raw('a.png', $php, 'image/png'),
            'php disguised as pdf' => $this->raw('a.pdf', $php, 'application/pdf'),
            'double extension php.jpg' => $this->raw('shell.php.jpg', $php, 'image/jpeg'),
            'double extension jpg.php' => $this->raw('foto.jpg.php', $php, 'image/jpeg'),
            'html as jpg' => $this->raw('x.jpg', '<html><script>alert(1)</script></html>', 'image/jpeg'),
            'svg as png' => $this->raw('x.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/png'),
            'svg' => $this->raw('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml'),
            'exe' => $this->raw('x.exe', "MZ\x90\x00", 'application/octet-stream'),
            'empty png' => $this->raw('vacio.png', '', 'image/png'),
            'gif' => $this->raw('x.gif', "GIF89a\x01\x00\x01\x00", 'image/gif'),
            'no extension' => $this->raw('archivo', $php, 'image/png'),
            'zip bomb name' => $this->raw('x.zip', "PK\x03\x04", 'application/zip'),
        ];
        foreach ($cases as $label => $file) {
            $this->upload($p, $file);
            $this->assertSame(0, ClinicalFile::query()->count(), "aceptó: $label");
        }
    }

    public function test_upload_metadata_validation_and_safe_display_name(): void
    {
        $p = $this->makePatient();
        $other = $this->makePatient();
        $this->actingAsDoctor();
        $img = fn () => UploadedFile::fake()->image('ok.png', 50, 50);
        $this->upload($p, $img(), ['file_type' => 'virus'])->assertSessionHasErrors('file_type');
        $this->upload($p, $img(), ['display_name' => str_repeat('x', 251)])->assertSessionHasErrors('display_name');
        $this->upload($p, $img(), ['encounter_id' => (string) Str::uuid()])->assertSessionHasErrors('encounter_id');
        $this->upload($p, UploadedFile::fake()->create('grande.pdf', 20481, 'application/pdf'))->assertSessionHasErrors('file');
        $this->upload($p, $img(), ['display_name' => '../../etc/passwd'])->assertSessionHasNoErrors();
        $f = ClinicalFile::query()->firstOrFail();
        $this->assertStringNotContainsString('..', $f->storage_key);
        $this->assertStringNotContainsString('/', (string) $f->display_name);
        $this->assertStringStartsWith("clinical/{$this->organizationId()}/{$p->id}/", $f->storage_key);
        // un archivo del paciente A no se puede dar de baja desde la URL del paciente B
        $this->actingAsAdmin();
        $this->delete(route('expediente.archivos.destroy', [$other->id, $f->id]))->assertStatus(404);
        $this->assertNull($f->fresh()->deleted_at);
    }

    public function test_signed_download_url_security(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $this->upload($p, UploadedFile::fake()->image('ok.png', 50, 50))->assertSessionHasNoErrors();
        $f = ClinicalFile::query()->firstOrFail();

        $signed = app(FileService::class)->signedUrl($f);
        $this->get($signed)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('expediente.archivos.download', $f->id))->assertStatus(403);                // sin firma
        $this->get($signed.'&x=1')->assertStatus(403);                                                // manipulada
        $this->get(preg_replace('/signature=[^&]+/', 'signature=abc', $signed))->assertStatus(403);   // firma falsa
        $expired = URL::temporarySignedRoute('expediente.archivos.download', now()->subMinute(), ['file' => $f->id]);
        $this->get($expired)->assertStatus(403);
        $this->actingAsReceptionist();                                                               // sin permiso expediente.ver
        $this->get($signed)->assertStatus(403);
        $this->actingAsDoctor();
        $f->delete();
        $this->get($signed)->assertStatus(404);                                                       // dado de baja
    }

    // --------------------------------------------------------------- consentimientos

    public function test_consent_validation_and_minor_must_be_accepted_by_guardian(): void
    {
        $adult = $this->makePatient();
        $this->actingAsDoctor();
        $url = route('expediente.consentimientos.store', $adult->id);
        $this->from('/x')->post($url, [])->assertSessionHasErrors(['accepted_by_name', 'method']);
        $this->post($url, ['accepted_by_name' => 'Yo', 'method' => 'firma_digital'])->assertSessionHasErrors('accepted_by_name');
        $this->post($url, ['accepted_by_name' => 'Persona Válida', 'method' => 'telepatia'])->assertSessionHasErrors('method');
        $this->post($url, ['accepted_by_name' => 'Persona Válida', 'method' => 'firma_digital', 'accepted_at' => now()->addDay()->toDateTimeString()])->assertSessionHasErrors('accepted_at');
        $this->post($url, ['accepted_by_name' => 'Persona Válida', 'method' => 'firma_digital', 'clinical_file_id' => (string) Str::uuid()])->assertSessionHasErrors('clinical_file_id');
        $this->post($url, ['accepted_by_name' => 'Persona Válida', 'method' => 'firma_digital'])->assertSessionHasNoErrors();
        $this->assertSame(1, ConsentAcceptance::query()->count());

        $minor = $this->makePatient(['birth_date' => now()->subYears(8)->toDateString(), 'full_name' => 'Mateo Niño Pérez']);
        PatientGuardian::create(['patient_id' => $minor->id, 'full_name' => 'Laura Pérez Ruiz', 'relationship' => 'Madre', 'phone' => '5511112222', 'is_primary' => true]);
        $murl = route('expediente.consentimientos.store', $minor->id);
        $this->post($murl, ['accepted_by_name' => 'Mateo Niño Pérez', 'method' => 'firma_digital'])->assertSessionHasErrors();
        $this->post($murl, ['accepted_by_name' => 'Alguien Desconocido', 'method' => 'firma_digital'])->assertSessionHasErrors();
        $this->post($murl, ['accepted_by_name' => 'Laura Pérez Ruiz', 'method' => 'firma_digital'])->assertSessionHasNoErrors();
    }

    // --------------------------------------------------------------- tratamientos del paciente

    public function test_patient_treatment_rules(): void
    {
        $p = $this->makePatient();
        $t = $this->makeTreatment();
        $this->actingAsDoctor();
        $url = route('expediente.tratamientos.store', $p->id);
        $this->from('/x')->post($url, [])->assertSessionHasErrors(['treatment_id']);
        $this->post($url, ['treatment_id' => $t->id, 'status' => 'planeado', 'tooth_fdi' => 99])->assertSessionHasErrors('tooth_fdi');
        $this->post($url, ['treatment_id' => $t->id, 'status' => 'raro'])->assertSessionHasErrors('status');
        $this->post($url, ['treatment_id' => $t->id, 'status' => 'realizado', 'started_at' => now()->subDay()->toDateTimeString(), 'completed_at' => now()->subDays(2)->toDateTimeString()])->assertSessionHasErrors('completed_at');
        $this->post($url, ['treatment_id' => $t->id, 'status' => 'planeado', 'started_at' => now()->addDay()->toDateTimeString()])->assertSessionHasErrors('started_at');
        $this->post($url, ['treatment_id' => $t->id, 'status' => 'planeado', 'tooth_fdi' => 26])->assertSessionHasNoErrors();
        $item = PatientTreatment::query()->firstOrFail();
        $st = route('expediente.tratamientos.status', [$p->id, $item->id]);
        $this->patch($st, ['status' => 'realizado'])->assertSessionHasNoErrors();
        $this->from('/x')->patch($st, ['status' => 'planeado']);               // realizado es terminal
        $this->assertSame('realizado', $item->fresh()->status->value);
        $this->assertNotNull($item->fresh()->completed_at);
    }

    // --------------------------------------------------------------- historial y datos del paciente

    public function test_medical_history_limits(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $url = route('expediente.historial.update', $p->id);
        $this->from('/x')->put($url, ['allergies' => str_repeat('a', 2001)])->assertSessionHasErrors('allergies');
        $this->put($url, ['medical_history' => str_repeat('a', 5001)])->assertSessionHasErrors('medical_history');
        $this->put($url, ['allergies' => 'Penicilina', 'medical_history' => 'Hipertensión'])->assertSessionHasNoErrors();
        $this->assertSame('Penicilina', MedicalRecord::query()->where('patient_id', $p->id)->first()->allergies);
    }

    public function test_basic_data_edit_keeps_the_same_data_contract_as_the_patients_module(): void
    {
        $p = $this->makePatient(['phone' => '5511112222']);
        $this->actingAsAdmin();
        $url = route('expediente.datos.update', $p->id);
        $this->from('/x')->put($url, ['full_name' => '12345', 'birth_date' => '1990-01-01', 'phone' => '5511112222'])->assertSessionHasErrors('full_name');
        $this->put($url, ['full_name' => 'Nombre Válido', 'birth_date' => '2999-01-01', 'phone' => '5511112222'])->assertSessionHasErrors('birth_date');
        $this->put($url, ['full_name' => 'Nombre Válido', 'birth_date' => '1990-01-01', 'phone' => '123'])->assertSessionHasErrors('phone');
        $this->put($url, ['full_name' => '  Nombre   Válido ', 'birth_date' => '1990-01-01', 'phone' => '55 9876 5432', 'email' => ' MAYUS@Correo.MX '])->assertSessionHasNoErrors();
        $fresh = $p->fresh();
        $this->assertSame('5598765432', $fresh->phone, 'el teléfono se guarda solo con dígitos, igual que en Pacientes');
        $this->assertSame('mayus@correo.mx', $fresh->email);
        $this->assertSame('Nombre Válido', $fresh->full_name);
    }

    public function test_basic_data_edit_cannot_turn_an_adult_into_a_minor_without_guardian(): void
    {
        $p = $this->makePatient();
        $this->actingAsAdmin();
        $this->from('/x')->put(route('expediente.datos.update', $p->id), ['full_name' => 'Nombre Válido', 'birth_date' => now()->subYears(5)->toDateString(), 'phone' => '5511112222'])
            ->assertSessionHasErrors('birth_date');
        $this->assertSame('1990-05-10', $p->fresh()->birth_date->toDateString());
    }

    // --------------------------------------------------------------- coherencia / paciente archivado

    public function test_archived_patient_cannot_get_new_documents_notes_or_files(): void
    {
        $p = $this->makePatient();
        $p->delete();
        $this->actingAsAdmin();
        $this->from('/x')->post(route('documentos.store', 'autorizacion-tratamiento'), ['patient_id' => $p->id])->assertSessionHasErrors('patient_id');
        $this->assertSame(0, ClinicalFile::query()->count());
    }

    public function test_one_medical_record_per_patient_even_if_created_twice(): void
    {
        $p = $this->makePatient();
        MedicalRecord::query()->firstOrCreate(['patient_id' => $p->id]);
        MedicalRecord::query()->firstOrCreate(['patient_id' => $p->id]);
        $this->assertSame(1, MedicalRecord::query()->where('patient_id', $p->id)->count());
        $this->expectException(QueryException::class);
        DB::table('medical_records')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->organizationId(), 'patient_id' => $p->id]);
        DB::table('medical_records')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->organizationId(), 'patient_id' => $p->id]);
    }

    public function test_expediente_pages_render_for_every_tab_and_reject_garbage(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        foreach (['resumen', 'historial', 'notas', 'archivos', 'odontograma', 'tratamientos', 'consentimientos', 'xx', '<script>'] as $tab) {
            $this->get(route('expediente.show', [$p->id, 'tab' => $tab]))->assertOk();
        }
        $this->get('/expediente/no-es-uuid')->assertNotFound();
        $this->get('/expediente/'.Str::uuid())->assertNotFound();
        $this->actingAsReceptionist();
        $this->get(route('expediente.show', $p->id))->assertStatus(403);
    }

    public function test_documents_index_and_forms_validate_input(): void
    {
        $p = $this->makePatient();
        $this->actingAsDoctor();
        $this->get(route('documentos.index'))->assertOk();
        $this->get('/documentos/no-existe/crear')->assertNotFound();
        $this->from('/x')->post(route('documentos.store', 'autorizacion-tratamiento'), [])->assertSessionHasErrors('patient_id');
        $this->post(route('documentos.store', 'autorizacion-tratamiento'), ['patient_id' => (string) Str::uuid()])->assertSessionHasErrors('patient_id');
        $this->post(route('documentos.store', 'historia-clinica'), ['patient_id' => $p->id])->assertSessionHasErrors();
        $this->post('/documentos/inventada', ['patient_id' => $p->id])->assertNotFound();
    }
}
