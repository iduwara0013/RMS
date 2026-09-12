export type FormQuestion = { id: string; label: string; type: 'text' | 'number' | 'date' | 'select' | 'yesno' | 'file'; required: boolean; options: string[] };
export type VacancyForm = { id: number; version: number; title: string; questions: FormQuestion[] };

export default function VacancyFormFields({ form, preview = false }: { form?: VacancyForm | null; preview?: boolean }) {
  return <section className="vacancy-custom-form">
    <input type="hidden" name="form_version_id" value={form?.id ?? 0} />
    {form && <><h3>{form.title}</h3><p>Form version {form.version} · Questions marked * are required.</p>
      <fieldset disabled={preview}>{form.questions.map(question => <label className="application-field" key={question.id}>
        <span>{question.label}{question.required ? ' *' : ' (optional)'}</span>
        {question.type === 'select' || question.type === 'yesno' ? <select name={`answers[${question.id}]`} required={question.required} defaultValue=""><option value="">Select an answer</option>{(question.type === 'yesno' ? ['Yes', 'No'] : question.options).map(option => <option key={option} value={option}>{option}</option>)}</select>
          : question.type === 'file' ? <><small>PDF, Word, JPG or PNG · Maximum 5 MB</small><input type="file" name={`answer_files[${question.id}]`} required={question.required} accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" /></>
          : question.type === 'text' ? <textarea name={`answers[${question.id}]`} required={question.required} maxLength={4000} rows={3} />
          : <input type={question.type} name={`answers[${question.id}]`} required={question.required} step={question.type === 'number' ? 'any' : undefined} min={question.type === 'number' ? -1000000000 : undefined} max={question.type === 'number' ? 1000000000 : undefined} />}
      </label>)}</fieldset>
    </>}
  </section>;
}
