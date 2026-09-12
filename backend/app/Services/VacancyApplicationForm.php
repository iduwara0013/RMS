<?php
namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationFormSubmission;
use App\Models\Document;
use App\Models\VacancyFormVersion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VacancyApplicationForm
{
    public function current(int $vacancyId): ?VacancyFormVersion
    {
        return VacancyFormVersion::where('vacancy_id', $vacancyId)->orderByDesc('version')->first();
    }

    // Caller holds the vacancy lock until submission commits, so publication
    // cannot switch the form between validation and saving answers.
    public function validate(Request $request, int $vacancyId): ?VacancyFormVersion
    {
        $form = $this->current($vacancyId);
        $request->validate(['form_version_id' => ['nullable', 'integer', 'min:0'], 'answers' => ['sometimes', 'array'], 'answer_files' => ['sometimes', 'array']]);
        abort_unless((int) $request->input('form_version_id', 0) === (int) ($form?->id ?? 0), 409, 'The application form has changed. Reload the vacancy before submitting.');
        if (!$form) return null;
        $rules = []; $attributes = [];
        foreach ($form->questions as $question) {
            $key = ($question['type'] === 'file' ? 'answer_files.' : 'answers.').$question['id'];
            $rules[$key] = [$question['required'] ? 'required' : 'nullable'];
            $rules[$key] = array_merge($rules[$key], match ($question['type']) {
                'number' => ['numeric', 'between:-1000000000,1000000000'],
                'date' => ['date_format:Y-m-d'],
                'select' => ['string', Rule::in($question['options'])],
                'yesno' => ['string', Rule::in(['Yes', 'No'])],
                'file' => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:5120'],
                default => ['string', 'max:4000'],
            });
            $attributes[$key] = $question['label'];
        }
        $request->validate($rules, [], $attributes);
        return $form;
    }

    public function store(Request $request, Application $application, ?VacancyFormVersion $form, array &$storedPaths): void
    {
        if (!$form) return;
        $answers = [];
        foreach ($form->questions as $question) {
            $id = $question['id'];
            if ($question['type'] === 'file') {
                $file = $request->file('answer_files.'.$id);
                if (!$file) { $answers[$id] = null; continue; }
                $path = $file->store('candidate-documents', 'local');
                $storedPaths[] = $path;
                $document = Document::create(['application_id' => $application->application_id, 'document_type' => 'Supporting Document', 'file_name' => $file->getClientOriginalName(), 'file_path' => $path, 'uploaded_at' => now()]);
                $answers[$id] = ['document_id' => $document->document_id, 'file_name' => $document->file_name];
            } else {
                $answers[$id] = $request->input('answers.'.$id);
            }
        }
        ApplicationFormSubmission::create(['application_id' => $application->application_id, 'form_version_id' => $form->id, 'answers' => $answers]);
    }
}
