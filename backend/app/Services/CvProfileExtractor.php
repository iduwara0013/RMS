<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CvProfile;
use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

class CvProfileExtractor
{
    private const SECTION_HEADINGS = [
        'professional summary', 'career summary', 'personal profile', 'profile', 'summary', 'objective',
        'technical skills', 'core competencies', 'key skills', 'skills',
        'work experience', 'professional experience', 'employment history', 'career history', 'experience',
        'project experience', 'selected projects', 'academic projects',
        'academic qualifications', 'educational qualifications', 'education', 'qualifications',
        'professional certifications', 'certifications', 'certificates',
        'language proficiency', 'languages',
        'projects', 'publications', 'publications & research', 'research', 'references', 'referees', 'interests', 'achievements', 'personal details',
    ];

    public function extractAndStore(Application $application, Document $document): CvProfile
    {
        if (config('services.cv_parser.enabled')) {
            try {
                $profile = $this->extractWithLayoutService($document);

                return $this->store($application, [
                    'professional_summary' => $profile['summary'] ?? null,
                    'skills' => $profile['skills'] ?? [],
                    'education' => $profile['education'] ?? [],
                    'experience' => $profile['experience'] ?? [],
                    'projects' => $profile['projects'] ?? [],
                    'certifications' => $profile['certifications'] ?? [],
                    'languages' => $profile['languages'] ?? [],
                    'parse_status' => $profile['parse_status'] ?? 'Needs review',
                    'parse_message' => '[parser:layout-v1] '.($profile['parse_message'] ?? 'Review the extracted details against the original CV.'),
                    'confidence_score' => $profile['confidence_score'] ?? null,
                    'review_status' => $profile['review_status'] ?? 'Needs review',
                    'parser_version' => $profile['parser_version'] ?? 'layout-v1',
                    'parser_metadata' => $profile['parser_metadata'] ?? null,
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        try {
            $text = $this->extractText($document);
            $text = $this->normalize($text);

            if (mb_strlen($text) < 30) {
                return $this->store($application, [
                    'parse_status' => 'Needs review',
                    'parse_message' => '[parser:php-fallback-v3] No readable text was found. This may be a scanned or image-based CV.',
                ]);
            }

            $summary = $this->section($text, ['professional summary', 'career summary', 'personal profile', 'profile', 'summary', 'objective']);
            $lines = $this->lines($text);
            $skills = $this->items($this->section($text, ['technical skills', 'core competencies', 'key skills', 'skills']), true, 24);
            if (! $skills || $this->containsAny($skills, ['@', 'github link', 'application –', 'application -'])) {
                $skills = $this->skillsFromCategoryLines($lines);
            }
            $experience = $this->items($this->section($text, ['work experience', 'professional experience', 'employment history', 'career history', 'experience']), false, 14);
            $education = $this->items($this->section($text, ['academic qualifications', 'educational qualifications', 'education', 'qualifications']), false, 12);
            if (! $education || count($education) > 8 || $this->containsAny($education, ['@', 'github', 'firebase', 'application', 'platform'])) {
                $education = $this->educationFromDegreeLines($lines);
            }
            $projects = $this->projectBlocks($lines);
            $certifications = $this->items($this->section($text, ['professional certifications', 'certifications', 'certificates']), false, 12);
            if (! $certifications) {
                $certifications = $this->certificationsFromLines($lines);
            }
            $languages = $this->items($this->section($text, ['language proficiency', 'languages']), true, 12);

            $hasStructuredContent = $summary !== null || $skills || $experience || $education || $certifications || $languages;

            return $this->store($application, [
                'professional_summary' => $summary ? mb_substr($summary, 0, 1800) : null,
                'skills' => $skills,
                'education' => $education,
                'experience' => $experience,
                'projects' => $projects,
                'certifications' => $certifications,
                'languages' => $languages,
                'parse_status' => $hasStructuredContent ? 'Parsed' : 'Needs review',
                'parse_message' => $hasStructuredContent
                    ? '[parser:php-fallback-v3] Details were extracted automatically and should be reviewed against the original CV.'
                    : '[parser:php-fallback-v3] Text was readable, but standard CV section headings could not be identified.',
                'confidence_score' => $hasStructuredContent ? 55 : 25,
                'review_status' => 'Needs review',
                'parser_version' => 'php-fallback-v3',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return $this->store($application, [
                'parse_status' => 'Failed',
                'parse_message' => '[parser:php-fallback-v3] The CV could not be processed automatically. The original document is still available.',
                'confidence_score' => 0,
                'review_status' => 'Needs review',
                'parser_version' => 'php-fallback-v3',
            ]);
        }
    }

    private function extractWithLayoutService(Document $document): array
    {
        $path = $this->documentPath($document);
        $extension = mb_strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'docx'], true)) {
            throw new RuntimeException('The layout parser supports PDF and DOCX files only.');
        }

        $url = rtrim((string) config('services.cv_parser.url'), '/');
        if ($url === '') {
            throw new RuntimeException('The CV parser URL is not configured.');
        }

        $response = Http::acceptJson()
            ->connectTimeout(2)
            ->timeout((int) config('services.cv_parser.timeout', 20))
            ->attach('file', file_get_contents($path), $document->file_name)
            ->post($url.'/extract');

        $response->throw();
        $profile = $response->json();
        if (! is_array($profile)) {
            throw new RuntimeException('The CV parser returned an invalid response.');
        }

        return $profile;
    }

    private function documentPath(Document $document): string
    {
        $disk = Storage::disk('local')->exists($document->file_path) ? 'local' : 'public';
        if (! Storage::disk($disk)->exists($document->file_path)) {
            throw new RuntimeException('CV file does not exist.');
        }

        return Storage::disk($disk)->path($document->file_path);
    }

    private function extractText(Document $document): string
    {
        $path = $this->documentPath($document);
        $extension = mb_strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => (new Parser())->parseFile($path)->getText(),
            'docx' => $this->extractDocx($path),
            'doc' => throw new RuntimeException('Legacy DOC extraction is not supported.'),
            default => throw new RuntimeException('Unsupported CV format.'),
        };
    }

    private function extractDocx(string $path): string
    {
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw new RuntimeException('Unable to open DOCX file.');
        }

        $xml = $archive->getFromName('word/document.xml');
        $archive->close();
        if ($xml === false) {
            throw new RuntimeException('DOCX document body is missing.');
        }

        $xml = str_replace(['</w:p>', '</w:tr>', '<w:tab/>'], ["\n", "\n", "\t"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\xC2\xA0", "\r"], [' ', ''], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function section(string $text, array $aliases): ?string
    {
        $lines = preg_split('/\n/u', $text) ?: [];
        $capturing = false;
        $result = [];

        foreach ($lines as $line) {
            $clean = trim($line, " \t\n\r\0\x0B:-–—•|.");
            $heading = mb_strtolower($clean);
            if (! $capturing) {
                if (in_array($heading, $aliases, true)) {
                    $capturing = true;
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (preg_match('/^'.preg_quote($alias, '/').'\s*[:\-–—]\s*(.+)$/iu', $clean, $match)) {
                        $capturing = true;
                        $result[] = trim($match[1]);
                        continue 2;
                    }
                }
            }
            if ($capturing && in_array($heading, self::SECTION_HEADINGS, true)) {
                break;
            }
            if ($capturing && $clean !== '') {
                $result[] = $clean;
            }
        }

        $value = trim(implode("\n", $result));

        return $value !== '' ? $value : null;
    }

    private function items(?string $section, bool $splitCommas, int $limit): array
    {
        if (! $section) {
            return [];
        }

        $pattern = $splitCommas ? '/[\n,;•·|]+/u' : '/\n+/u';
        $items = array_map(
            fn (string $item) => trim($item, " \t\n\r\0\x0B-–—•·;,."),
            preg_split($pattern, $section) ?: []
        );
        $items = array_values(array_filter($items, fn (string $item) => mb_strlen($item) >= 2));
        $items = array_values(array_unique($items));

        return array_slice($items, 0, $limit);
    }

    private function lines(string $text): array
    {
        return array_values(array_filter(
            array_map(fn (string $line) => trim($line), preg_split('/\n/u', $text) ?: []),
            fn (string $line) => $line !== ''
        ));
    }

    private function containsAny(array $items, array $needles): bool
    {
        $value = mb_strtolower(implode(' ', $items));
        foreach ($needles as $needle) {
            if (str_contains($value, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function skillsFromCategoryLines(array $lines): array
    {
        $skills = [];
        $categories = 'front(?:end|ed)(?:\s*\/\s*mobile)?|mobile|backend|programming languages?|frameworks?|databases?(?:\s*&\s*tools)?|tools|cloud|devops|ai(?:\s*\/\s*emerging technologies)?|emerging technologies|soft skills?';

        foreach ($lines as $line) {
            if (! preg_match('/^(?:'.$categories.')\s*[-:–—]\s*(.+)$/iu', $line, $match)) {
                continue;
            }
            $parts = preg_split('/,(?![^()]*\))/u', $match[1]) ?: [];
            foreach ($parts as $part) {
                $skill = trim($part, " \t\n\r\0\x0B-–—•·;,." );
                if (mb_strlen($skill) >= 2) {
                    $skills[] = $skill;
                }
            }
        }

        return array_slice(array_values(array_unique($skills)), 0, 24);
    }

    private function educationFromDegreeLines(array $lines): array
    {
        $education = [];
        foreach ($lines as $index => $line) {
            if (! preg_match('/\b(B\.?SC|M\.?SC|BACHELOR|MASTER|DIPLOMA|PHD|DEGREE|HND)\b/iu', $line)) {
                continue;
            }
            $block = [$line];
            for ($offset = 1; $offset <= 3; $offset++) {
                $next = $lines[$index + $offset] ?? null;
                if (! $next || preg_match('/^(?:front(?:end|ed)|backend|skills|project|experience|certif)/iu', $next)) {
                    break;
                }
                $block[] = $next;
            }
            $education[] = implode(' · ', $block);
        }

        return array_slice(array_values(array_unique($education)), 0, 8);
    }

    private function projectBlocks(array $lines): array
    {
        $projects = [];
        $count = count($lines);
        for ($index = 0; $index < $count; $index++) {
            if (! preg_match('/\[(?:git(?:hub)?|project)\s*link\]/iu', $lines[$index])) {
                continue;
            }
            $block = [$lines[$index]];
            for ($offset = $index + 1; $offset < min($count, $index + 5); $offset++) {
                $next = $lines[$offset];
                if (preg_match('/\[(?:git(?:hub)?|project)\s*link\]/iu', $next)
                    || preg_match('/\b(B\.?SC|M\.?SC|BACHELOR|MASTER|DIPLOMA|PHD)\b/iu', $next)
                    || preg_match('/^(?:front(?:end|ed)|backend|databases?|certif)/iu', $next)) {
                    break;
                }
                $block[] = $next;
            }
            $projects[] = implode(' ', $block);
        }

        return array_slice(array_values(array_unique($projects)), 0, 8);
    }

    private function certificationsFromLines(array $lines): array
    {
        $certifications = [];
        foreach ($lines as $line) {
            if (preg_match('/\b(CCNA|COMPTIA|CISCO|ORACLE|AWS|AZURE|CERTIFICATION|CERTIFIED)\b/iu', $line)
                && ! preg_match('/^(professional )?certifications?$/iu', $line)) {
                $certifications[] = $line;
            }
        }

        return array_slice(array_values(array_unique($certifications)), 0, 12);
    }

    private function store(Application $application, array $data): CvProfile
    {
        return CvProfile::updateOrCreate(
            ['application_id' => $application->application_id],
            $this->supportedColumns(array_merge([
                'professional_summary' => null,
                'skills' => [],
                'education' => [],
                'experience' => [],
                'projects' => [],
                'certifications' => [],
                'languages' => [],
                'extracted_at' => now(),
            ], $data))
        );
    }

    private function supportedColumns(array $data): array
    {
        $optionalColumns = ['projects', 'confidence_score', 'review_status', 'parser_version', 'parser_metadata'];
        foreach ($optionalColumns as $column) {
            if (array_key_exists($column, $data) && ! Schema::hasColumn('candidate_cv_profiles', $column)) {
                unset($data[$column]);
            }
        }

        return $data;
    }
}
