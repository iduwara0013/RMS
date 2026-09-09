<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Document;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\CvProfileExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class InternalEmployeeController extends Controller
{
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_epf' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:30'],
        ]);
        $rateKey = 'internal-otp:'.Str::lower($data['employee_epf']).':'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($rateKey, 5), 429, 'Too many OTP requests. Please wait one minute.');
        RateLimiter::hit($rateKey, 60);

        $user = User::with('roles')->where('employee_epf', $data['employee_epf'])->first();
        $phoneMatches = $user && $this->normalisePhone($user->phone) === $this->normalisePhone($data['phone']);
        $isEmployee = $user?->roles->contains('role_name', 'Internal Employee') ?? false;
        abort_unless($phoneMatches && $isEmployee, 422, 'Employee EPF and phone number do not match an active internal employee account.');

        $otpDriver = (string) config('internal_otp.driver', 'mock');
        abort_unless($otpDriver === 'mock', 503, 'The real SMS OTP provider has not been configured yet.');
        $otp = (string) config('internal_otp.mock_code', '123456');
        abort_unless((bool) preg_match('/^\d{6}$/', $otp), 500, 'The configured mock OTP must contain exactly six digits.');
        DB::table('internal_otp_challenges')->where('user_id', $user->id)->delete();
        DB::table('internal_otp_challenges')->insert([
            'user_id' => $user->id,
            'code_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes((int) config('internal_otp.expires_minutes', 5)),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Mock internal employee OTP generated.', ['employee_id' => $user->id]);

        return response()->json(array_filter([
            'message' => 'Mock OTP generated. Use the displayed code to continue.',
            'debug_otp' => $otpDriver === 'mock' ? $otp : null,
        ]));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_epf' => ['required', 'string', 'max:50'],
            'otp' => ['required', 'digits:6'],
        ]);
        $user = User::with('roles')->where('employee_epf', $data['employee_epf'])->firstOrFail();
        abort_unless($user->roles->contains('role_name', 'Internal Employee'), 403, 'This account is not an internal employee account.');

        $challenge = DB::table('internal_otp_challenges')
            ->where('user_id', $user->id)->whereNull('verified_at')->latest('id')->first();
        abort_unless($challenge && now()->lessThan($challenge->expires_at), 422, 'The OTP has expired. Request a new code.');
        abort_if($challenge->attempts >= 5, 429, 'Too many incorrect OTP attempts. Request a new code.');

        if (! Hash::check($data['otp'], $challenge->code_hash)) {
            DB::table('internal_otp_challenges')->where('id', $challenge->id)->increment('attempts');
            abort(422, 'Invalid OTP code.');
        }

        DB::table('internal_otp_challenges')->where('id', $challenge->id)->update(['verified_at' => now(), 'updated_at' => now()]);
        DB::table('internal_access_tokens')->where('user_id', $user->id)->delete();
        $plainToken = Str::random(80);
        DB::table('internal_access_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours((int) config('internal_otp.session_hours', 8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Employee verified successfully.',
            'token' => $plainToken,
            'employee' => ['name' => $user->name, 'employee_epf' => $user->employee_epf, 'phone' => $user->phone],
        ]);
    }

    public function vacancies(Request $request): JsonResponse
    {
        $this->employeeFromToken($request);
        return response()->json(['vacancies' => Vacancy::with('department')
            ->where('status', 'Published')->whereIn('audience', ['Internal', 'Both'])
            ->whereDate('opening_date', '<=', now())->whereDate('closing_date', '>=', now())
            ->orderBy('closing_date')->get()]);
    }

    public function apply(Request $request, Vacancy $vacancy, CvProfileExtractor $extractor): JsonResponse
    {
        $employee = $this->employeeFromToken($request);
        abort_unless($vacancy->status === 'Published' && in_array($vacancy->audience, ['Internal', 'Both'], true) && $vacancy->closing_date->isFuture(), 422, 'This internal vacancy is no longer accepting applications.');
        abort_if(Application::where('vacancy_id', $vacancy->vacancy_id)->where('employee_id', $employee->id)->exists(), 422, 'You have already applied for this vacancy.');
        $data = $request->validate([
            'nic' => ['required', 'string', 'max:30'], 'email' => ['required', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:500'], 'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
        ]);

        [$application, $document] = DB::transaction(function () use ($data, $vacancy, $request, $employee) {
            $candidate = Candidate::updateOrCreate(['nic' => $data['nic']], [
                'name' => $employee->name, 'email' => $data['email'], 'phone' => $employee->phone, 'address' => $data['address'],
            ]);
            abort_if(Application::where('vacancy_id', $vacancy->vacancy_id)->where('candidate_id', $candidate->candidate_id)->exists(), 422, 'An application already exists for this employee and vacancy.');
            $application = Application::create([
                'candidate_id' => $candidate->candidate_id, 'vacancy_id' => $vacancy->vacancy_id,
                'employee_id' => $employee->id, 'applicant_type' => 'Internal', 'submitted_at' => now(), 'status' => 'Submitted',
            ]);
            $path = $request->file('cv')->store('candidate-documents', 'local');
            $document = Document::create(['application_id' => $application->application_id, 'document_type' => 'CV', 'file_name' => $request->file('cv')->getClientOriginalName(), 'file_path' => $path, 'uploaded_at' => now()]);
            return [$application, $document];
        });

        $extractor->extractAndStore($application, $document);

        return response()->json(['message' => 'Internal application submitted successfully.', 'application_id' => $application->application_id], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if ($token) DB::table('internal_access_tokens')->where('token_hash', hash('sha256', $token))->delete();
        return response()->json(['message' => 'Signed out.']);
    }

    private function employeeFromToken(Request $request): User
    {
        $plainToken = $request->bearerToken();
        abort_unless($plainToken, 401, 'Employee verification is required.');
        $token = DB::table('internal_access_tokens')->where('token_hash', hash('sha256', $plainToken))->where('expires_at', '>', now())->first();
        abort_unless($token, 401, 'Your employee session has expired.');
        DB::table('internal_access_tokens')->where('id', $token->id)->update(['last_used_at' => now(), 'updated_at' => now()]);
        return User::findOrFail($token->user_id);
    }

    private function normalisePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', $phone ?? '') ?? '';
    }
}
