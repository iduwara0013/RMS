<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\CvProfileExtractor;
use Illuminate\Console\Command;

class ExtractCvProfiles extends Command
{
    protected $signature = 'cv:extract {--force : Reprocess CVs that already have an extracted profile}';
    protected $description = 'Extract structured candidate profile information from stored CV files';

    public function handle(CvProfileExtractor $extractor): int
    {
        $query = Document::with('application.cvProfile')->where('document_type', 'CV')->orderBy('document_id');
        $processed = 0;

        $query->chunk(50, function ($documents) use ($extractor, &$processed) {
            foreach ($documents as $document) {
                if (! $document->application || (! $this->option('force') && $document->application->cvProfile)) {
                    continue;
                }
                $profile = $extractor->extractAndStore($document->application, $document);
                $this->line("Application {$document->application_id}: {$profile->parse_status}");
                $processed++;
            }
        });

        $this->info("Processed {$processed} CV(s).");

        return self::SUCCESS;
    }
}
