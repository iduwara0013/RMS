<?php
namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Models\VacancyFormVersion;
use App\Services\VacancyApplicationForm;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VacancyFormController extends Controller
{
    public function show(Vacancy $vacancy, VacancyApplicationForm $forms)
    {
        return response()->json(['form' => $forms->current($vacancy->vacancy_id), 'versions' => VacancyFormVersion::where('vacancy_id', $vacancy->vacancy_id)->orderByDesc('version')->get(['id', 'version', 'title', 'created_at'])]);
    }

    public function store(Request $request, Vacancy $vacancy, VacancyApplicationForm $forms)
    {
        abort_if(in_array($vacancy->status, ['Closed', 'Cancelled']), 422, 'Forms cannot be changed for a closed or cancelled vacancy.');
        $data = $request->validate([
            'base_version_id' => ['required', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:255'],
            'questions' => ['present', 'array', 'max:30'],
            'questions.*.id' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/', 'distinct'],
            'questions.*.label' => ['required', 'string', 'max:255'],
            'questions.*.type' => ['required', Rule::in(['text', 'number', 'date', 'select', 'yesno', 'file'])],
            'questions.*.required' => ['required', 'boolean'],
            'questions.*.options' => ['sometimes', 'array', 'max:30'],
            'questions.*.options.*' => ['required', 'string', 'max:150'],
        ]);
        $current = $forms->current($vacancy->vacancy_id);
        abort_unless((int) $data['base_version_id'] === (int) ($current?->id ?? 0), 409, 'Another form version was saved. Reload before editing.');
        $questions = collect($data['questions'])->map(function ($q) {
            $options = array_values(array_unique(array_map('trim', $q['options'] ?? [])));
            abort_if($q['type'] === 'select' && (count($options) < 2 || in_array('', $options, true)), 422, 'Dropdown questions need at least two different non-empty options.');
            return ['id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'required' => (bool) $q['required'], 'options' => $q['type'] === 'select' ? $options : []];
        })->all();
        $form = VacancyFormVersion::create(['vacancy_id' => $vacancy->vacancy_id, 'version' => ($current?->version ?? 0) + 1, 'title' => $data['title'], 'questions' => $questions, 'created_by' => $request->user()->id]);
        return response()->json(['form' => $form, 'message' => 'New form version saved and linked to this vacancy. Earlier applications keep their original version.'], 201);
    }
}
