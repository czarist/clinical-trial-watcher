<?php
namespace App\Console\Commands;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialArmGroup;
use App\Models\ClinicalTrials\ClinicalTrialCollaborator;
use App\Models\ClinicalTrials\ClinicalTrialCondition;
use App\Models\ClinicalTrials\ClinicalTrialIntervention;
use App\Models\ClinicalTrials\ClinicalTrialKeyword;
use App\Models\ClinicalTrials\ClinicalTrialLocation;
use App\Models\ClinicalTrials\ClinicalTrialMeshTerm;
use App\Models\ClinicalTrials\ClinicalTrialOutcome;
use App\Models\ClinicalTrials\ClinicalTrialReference;
use App\Models\ClinicalTrials\ClinicalTrialSecondaryId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchClinicalTrials extends Command
{
    private function normalizeDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        // YYYY-MM-DD → ok
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // YYYY-MM → completa com -01
        if (preg_match('/^\d{4}-\d{2}$/', $date)) {
            return $date . '-01';
        }

        // YYYY → completa com -01-01
        if (preg_match('/^\d{4}$/', $date)) {
            return $date . '-01-01';
        }

        return null;
    }

    protected $signature   = 'clinicaltrials:fetch {--pageSize=100}';
    protected $description = 'Fetch Clinical Trials from ClinicalTrials.gov API';

    public function handle(): int
    {
        $pageSize = $this->option('pageSize');

        $this->info("Fetching Clinical Trials (pageSize={$pageSize})...");

        $response = Http::withOptions(['verify' => false])
            ->get('https://beta-ut.clinicaltrials.gov/api/v2/studies', [
                'pageSize' => $pageSize,
            ]);

        if (! $response->successful()) {
            $this->error("Failed to fetch data: {$response->status()}");
            return Command::FAILURE;
        }

        $data    = $response->json();
        $studies = $data['studies'] ?? [];
        $this->info("Found " . count($studies) . " studies.");

        foreach ($studies as $studyData) {
            $protocol                = $studyData['protocolSection'] ?? [];
            $identification          = $protocol['identificationModule'] ?? [];
            $statusModule            = $protocol['statusModule'] ?? [];
            $descriptionModule       = $protocol['descriptionModule'] ?? [];
            $conditionsModule        = $protocol['conditionsModule'] ?? [];
            $designModule            = $protocol['designModule'] ?? [];
            $armsInterventionsModule = $protocol['armsInterventionsModule'] ?? [];
            $outcomesModule          = $protocol['outcomesModule'] ?? [];
            $contactsLocationsModule = $protocol['contactsLocationsModule'] ?? [];
            $sponsorModule           = $protocol['sponsorCollaboratorsModule'] ?? [];
            $eligibilityModule       = $protocol['eligibilityModule'] ?? [];

            $orgStudyId          = $identification['orgStudyIdInfo']['id'] ?? null;
            $statusVerifiedDate  = $statusModule['statusVerifiedDate'] ?? null;
            $detailedDescription = $descriptionModule['detailedDescription'] ?? null;
            $eligibilityCriteria = $eligibilityModule['eligibilityCriteria'] ?? null;
            $minAge              = $eligibilityModule['minimumAge'] ?? null;
            $maxAge              = $eligibilityModule['maximumAge'] ?? null;
            $gender              = $eligibilityModule['sex'] ?? null;
            $studyFirstPostDate  = $statusModule['studyFirstPostDateStruct']['date'] ?? null;
            $lastUpdatePostDate  = $statusModule['lastUpdatePostDateStruct']['date'] ?? null;

            // Basic fields
            $nctId                 = $identification['nctId'] ?? null;
            $briefTitle            = $identification['briefTitle'] ?? null;
            $officialTitle         = $identification['officialTitle'] ?? null;
            $overallStatus         = $statusModule['overallStatus'] ?? null;
            $startDate             = $statusModule['startDateStruct']['date'] ?? null;
            $primaryCompletionDate = $statusModule['primaryCompletionDateStruct']['date'] ?? null;
            $completionDate        = $statusModule['completionDateStruct']['date'] ?? null;
            $briefSummary          = $descriptionModule['briefSummary'] ?? null;
            $conditions            = $conditionsModule['conditions'] ?? [];
            $keywords              = $conditionsModule['keywords'] ?? [];
            $studyType             = $designModule['studyType'] ?? null;
            $phases                = $designModule['phases'] ?? [];
            $enrollmentInfo        = $designModule['enrollmentInfo'] ?? [];
            $leadSponsor           = $sponsorModule['leadSponsor']['name'] ?? null;
            $leadSponsorClass      = $sponsorModule['leadSponsor']['class'] ?? null;

            // Create or update the main trial record
            $trial = ClinicalTrial::updateOrCreate(
                ['nct_id' => $nctId],
                [
                    'brief_title'             => $briefTitle,
                    'official_title'          => $officialTitle,
                    'overall_status'          => $overallStatus,
                    'start_date'              => $this->normalizeDate($startDate),
                    'primary_completion_date' => $this->normalizeDate($primaryCompletionDate),
                    'completion_date'         => $this->normalizeDate($completionDate),
                    'brief_summary'           => $briefSummary,
                    'study_type'              => $studyType,
                    'phase'                   => ! empty($phases) ? implode(', ', $phases) : null,
                    'enrollment_count'        => $enrollmentInfo['count'] ?? null,
                    'enrollment_type'         => $enrollmentInfo['type'] ?? null,
                    'sponsor_name'            => $leadSponsor,
                    'sponsor_class'           => $leadSponsorClass,
                    'raw_json'                => json_encode($studyData), // Store raw data for reference
                    'org_study_id'            => $orgStudyId,
                    'status_verified_date'    => $this->normalizeDate($statusVerifiedDate),
                    'detailed_description'    => $detailedDescription,
                    'eligibility_criteria'    => $eligibilityCriteria,
                    'min_age'                 => $minAge,
                    'max_age'                 => $maxAge,
                    'gender'                  => $gender,
                    'study_first_post_date'   => $this->normalizeDate($studyFirstPostDate),
                    'last_update_post_date'   => $this->normalizeDate($lastUpdatePostDate),

                    'acronym'                 => $identification['acronym'] ?? null,
                    'responsible_party_type'  => $sponsorModule['responsibleParty']['type'] ?? null,
                    'is_fda_regulated_drug'   => $protocol['oversightModule']['isFdaRegulatedDrug'] ?? false,
                    'is_fda_regulated_device' => $protocol['oversightModule']['isFdaRegulatedDevice'] ?? false,
                    'has_expanded_access'     => $statusModule['expandedAccessInfo']['hasExpandedAccess'] ?? false,
                    'ipd_sharing'             => $protocol['ipdSharingStatementModule']['ipdSharing'] ?? null,

                ]
            );

            $secondaryIds = collect($identification['secondaryIdInfos'] ?? []);
            $secondaryIds = $secondaryIds->unique('id')->all();

            $trial->secondaryIds()->delete();
            foreach ($secondaryIds as $info) {
                ClinicalTrialSecondaryId::updateOrCreate(
                    [
                        'clinical_trial_id' => $trial->id,
                        'secondary_id'      => $info['id'],
                    ],
                    [
                        'type'   => $info['type'] ?? null,
                        'domain' => $info['domain'] ?? null,
                        'link'   => $info['link'] ?? null,
                    ]
                );
            }
            // 2. Processar Collaborators
            $collaborators = $sponsorModule['collaborators'] ?? [];
            if (! empty($collaborators)) {
                $trial->collaborators()->delete();
                foreach ($collaborators as $collaborator) {
                    ClinicalTrialCollaborator::create([
                        'clinical_trial_id' => $trial->id,
                        'name'              => $collaborator['name'] ?? null,
                        'class'             => $collaborator['class'] ?? null,
                    ]);
                }
            }

            // 3. Processar References
            $references = $protocol['referencesModule']['references'] ?? [];
            if (! empty($references)) {
                $trial->references()->delete();
                foreach ($references as $reference) {
                    ClinicalTrialReference::create([
                        'clinical_trial_id' => $trial->id,
                        'pmid'              => $reference['pmid'] ?? null,
                        'type'              => $reference['type'] ?? null,
                        'citation'          => $reference['citation'] ?? null,
                        'url'               => $reference['url'] ?? null,
                    ]);
                }
            }

            // 4. Processar MeSH Terms (da seção derivedSection)
            if (isset($studyData['derivedSection']['conditionBrowseModule'])) {
                $meshData = $studyData['derivedSection']['conditionBrowseModule'];
                $trial->meshTerms()->delete();

                // Termos principais
                if (isset($meshData['meshes'])) {
                    foreach ($meshData['meshes'] as $mesh) {
                        ClinicalTrialMeshTerm::create([
                            'clinical_trial_id' => $trial->id,
                            'mesh_id'           => $mesh['id'] ?? null,
                            'term'              => $mesh['term'] ?? null,
                            'is_primary'        => true,
                        ]);
                    }
                }

                // Termos ancestrais
                if (isset($meshData['ancestors'])) {
                    foreach ($meshData['ancestors'] as $ancestor) {
                        ClinicalTrialMeshTerm::create([
                            'clinical_trial_id' => $trial->id,
                            'mesh_id'           => $ancestor['id'] ?? null,
                            'term'              => $ancestor['term'] ?? null,
                            'is_primary'        => false,
                        ]);
                    }
                }
            }

            // Sync conditions
            if (! empty($conditions)) {
                $trial->conditions()->delete();
                foreach ($conditions as $condition) {
                    ClinicalTrialCondition::create([
                        'clinical_trial_id' => $trial->id,
                        'condition'         => $condition,
                    ]);
                }
            }

            // Sync keywords
            if (! empty($keywords)) {
                $trial->keywords()->delete();
                foreach ($keywords as $keyword) {
                    ClinicalTrialKeyword::create([
                        'clinical_trial_id' => $trial->id,
                        'keyword'           => $keyword,
                    ]);
                }
            }

            // Sync locations
            $locations = $contactsLocationsModule['locations'] ?? [];
            if (! empty($locations)) {
                $trial->locations()->delete();
                foreach ($locations as $location) {
                    ClinicalTrialLocation::create([
                        'clinical_trial_id' => $trial->id,
                        'facility'          => $location['facility'] ?? null,
                        'city'              => $location['city'] ?? null,
                        'state'             => $location['state'] ?? null,
                        'country'           => $location['country'] ?? null,
                        'zip'               => $location['zip'] ?? null,
                        'latitude'          => $location['geoPoint']['lat'] ?? null,
                        'longitude'         => $location['geoPoint']['lon'] ?? null,
                    ]);
                }
            }

            // Sync interventions
            $interventions = $armsInterventionsModule['interventions'] ?? [];
            if (! empty($interventions)) {
                $trial->interventions()->delete();
                foreach ($interventions as $intervention) {
                    ClinicalTrialIntervention::create([
                        'clinical_trial_id' => $trial->id,
                        'type'              => $intervention['type'] ?? null,
                        'name'              => $intervention['name'] ?? null,
                        'description'       => $intervention['description'] ?? null,
                    ]);
                }
            }

            // Sync arm groups
            $armGroups = $armsInterventionsModule['armGroups'] ?? [];
            if (! empty($armGroups)) {
                $trial->armGroups()->delete();
                foreach ($armGroups as $armGroup) {
                    ClinicalTrialArmGroup::create([
                        'clinical_trial_id' => $trial->id,
                        'label'             => $armGroup['label'] ?? null,
                        'type'              => $armGroup['type'] ?? null,
                        'description'       => $armGroup['description'] ?? null,
                    ]);
                }
            }

            // Sync outcomes
            $primaryOutcomes   = $outcomesModule['primaryOutcomes'] ?? [];
            $secondaryOutcomes = $outcomesModule['secondaryOutcomes'] ?? [];
            $otherOutcomes     = $outcomesModule['otherOutcomes'] ?? [];

            if (! empty($primaryOutcomes) || ! empty($secondaryOutcomes) || ! empty($otherOutcomes)) {
                $trial->outcomes()->delete();

                foreach ($primaryOutcomes as $outcome) {
                    ClinicalTrialOutcome::create([
                        'clinical_trial_id' => $trial->id,
                        'type'              => 'PRIMARY',
                        'measure'           => $outcome['measure'] ?? null,
                        'description'       => $outcome['description'] ?? null,
                        'time_frame'        => $outcome['timeFrame'] ?? null,
                    ]);
                }

                foreach ($secondaryOutcomes as $outcome) {
                    ClinicalTrialOutcome::create([
                        'clinical_trial_id' => $trial->id,
                        'type'              => 'SECONDARY',
                        'measure'           => $outcome['measure'] ?? null,
                        'description'       => $outcome['description'] ?? null,
                        'time_frame'        => $outcome['timeFrame'] ?? null,
                    ]);
                }

                foreach ($otherOutcomes as $outcome) {
                    ClinicalTrialOutcome::create([
                        'clinical_trial_id' => $trial->id,
                        'type'              => 'OTHER',
                        'measure'           => $outcome['measure'] ?? null,
                        'description'       => $outcome['description'] ?? null,
                        'time_frame'        => $outcome['timeFrame'] ?? null,
                    ]);
                }
            }

            $this->info("Saved Clinical Trial with relations: {$nctId} - {$briefTitle}");
        }

        $this->info("Done!");
        return Command::SUCCESS;
    }
}
