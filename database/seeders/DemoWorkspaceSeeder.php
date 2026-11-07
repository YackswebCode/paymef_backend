<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignAuditEvent;
use App\Models\CampaignDocument;
use App\Models\FeeSchedule;
use App\Models\PlatformFeeConfig;
use App\Models\School;
use App\Models\User;
use App\Models\WorkspaceRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The demo tenant: one secondary school, one university, their students and
 * guardians, a funding campaign per student, fee schedules and the NGO/admin
 * records the dashboard surfaces read from.
 *
 * Idempotent: every record is keyed, so re-running refreshes rather than
 * duplicating.
 */
class DemoWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $feeConfig = $this->feeConfig();
        [$demoSchool, $testSchool] = $this->schools();

        $this->demoAccounts();
        $admin = $this->user('governance@paymef.test');
        $schoolAdmin = $this->user('admin@demo.test');
        $student = $this->user('student@demo.test');
        $parent = $this->user('yahayaibraheem808+parent@gmail.com');

        $this->profiles($demoSchool, $student, $parent);

        $siblings = [
            $this->student($demoSchool, 'Sade Okonkwo', 'sade.okonkwo@demo.test', 'DEMO/2024/002', 'SS 2', 'Science', $parent),
            $this->student($demoSchool, 'Ibrahim Musa', 'ibrahim.musa@demo.test', 'DEMO/2023/003', 'SS 3', 'Commercial', null),
            $this->student($demoSchool, 'Grace Eze', 'grace.eze@demo.test', 'DEMO/2025/004', 'SS 1', 'Arts', null),
        ];

        $this->feeSchedules($demoSchool, $schoolAdmin);
        $this->campaigns($student, $siblings, $feeConfig, $schoolAdmin, $admin);
        $this->ngo($this->user('ngo@demo.test'));
        $this->adminRecords();
        $this->notifications([$student, $parent, $schoolAdmin, $admin]);
        $this->bankApplication($student);
        $this->bankAccounts($student, $parent);

        // Keep the older fixtures coherent with the demo school.
        $testSchool->update(['profile' => ['theme' => 'default']]);
    }

    private function feeConfig(): PlatformFeeConfig
    {
        return PlatformFeeConfig::updateOrCreate(
            ['name' => 'Standard PayMef Crowdfunding Model (10% Processing)'],
            [
                'percentage_rate' => 0.10,
                'fixed_fee_minor' => 0,
                'min_fee_minor' => 10000,
                'currency' => 'NGN',
                'category' => 'all',
                'effective_from' => '2026-01-01',
                'applies_to_new_only' => true,
                'notes' => 'Gross = Net / (1 - 0.10) so the student always receives the full goal.',
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array{0: School, 1: School}
     */
    private function schools(): array
    {
        $demo = School::updateOrCreate(
            ['slug' => 'demo-academy'],
            [
                'name' => 'Demo Academy',
                'short_name' => 'DEMO',
                'type' => 'secondary',
                'email' => 'bursary@demo-academy.test',
                'phone' => '+234 803 000 1000',
                'address' => '12 Adeola Odeku Street, Victoria Island',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'NG',
                'website' => 'https://demo-academy.test',
                'accreditation_number' => 'LASG/EDU/2026/0194',
                'verification_status' => 'verified',
                'profile' => [
                    'motto' => 'Knowledge with character',
                    'brand_color' => '#0F5132',
                    'current_session' => '2025/2026',
                    'current_term' => 'Second Term',
                    'settlement_cycle' => 't_plus_1_clearing',
                ],
            ]
        );

        $test = School::updateOrCreate(
            ['slug' => 'test-academy'],
            [
                'name' => 'Test Academy',
                'short_name' => 'TAC',
                'type' => 'secondary',
                'city' => 'Abuja',
                'state' => 'FCT',
                'verification_status' => 'verified',
            ]
        );

        return [$demo, $test];
    }

    private function demoAccounts(): void
    {
        $accounts = [
            ['Admin', 'governance@paymef.test', 'admin', null],
            ['Demo Admin', 'admin@demo.test', 'school_admin', School::where('slug', 'demo-academy')->value('id')],
            ['Eleanor Vance Foundation', 'donor@demo.test', 'donor', null],
            ['Hope Scholars Africa Initiative', 'ngo@demo.test', 'ngo', null],
            ['Partner Commercial Bank', 'bank@demo.test', 'partner_institution', null],
        ];

        foreach ($accounts as [$name, $email, $role, $schoolId]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->fill([
                'name' => $name,
                'role' => $role,
                'school_id' => $schoolId,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (! $user->exists) {
                $user->password = Hash::make('Password123!');
            }

            $user->save();
        }
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function profiles(School $school, User $student, User $parent): void
    {
        $student->update([
            'school_id' => $school->id,
            'profile' => [
                'matric_number' => 'DEMO/2022/0491',
                'level' => 'SS 2',
                'department' => 'Science',
                'category' => 'secondary',
                'is_minor' => false,
                'consent_given' => true,
                'course_of_study' => 'Science (WAEC Track)',
                'parent_name' => $parent->name,
                'parent_phone' => $parent->phone,
            ],
        ]);

        $parent->update([
            'profile' => [
                'relationship' => 'mother',
                'linked_since' => '2025-09-01',
            ],
        ]);

        if (! $parent->children()->whereKey($student->id)->exists()) {
            $parent->children()->attach($student->id, [
                'school_id' => $school->id,
                'relationship' => 'mother',
            ]);
        }
    }

    private function student(School $school, string $name, string $email, string $matric, string $level, string $department, ?User $guardian): User
    {
        $student = User::firstOrNew(['email' => $email]);
        $student->fill([
            'name' => $name,
            'role' => 'student',
            'school_id' => $school->id,
            'email_verified_at' => $student->email_verified_at ?? now(),
            'profile' => [
                'matric_number' => $matric,
                'level' => $level,
                'department' => $department,
                'category' => 'secondary',
                'is_minor' => true,
                'consent_given' => $guardian !== null,
                'parent_name' => $guardian?->name,
                'parent_phone' => $guardian?->phone,
            ],
        ]);

        if (! $student->exists) {
            $student->password = Hash::make('Password123!');
        }

        $student->save();

        if ($guardian !== null && ! $guardian->children()->whereKey($student->id)->exists()) {
            $guardian->children()->attach($student->id, [
                'school_id' => $school->id,
                'relationship' => 'guardian',
            ]);
        }

        return $student;
    }

    private function feeSchedules(School $school, User $schoolAdmin): void
    {
        $rows = [
            ['Tuition — Second Term', 'Second Term', '2025/2026', 15000000, '2026-12-01'],
            ['Science laboratory levy', 'Second Term', '2025/2026', 2500000, '2026-12-15'],
            ['Examination and records', 'Second Term', '2025/2026', 1500000, '2027-01-10'],
        ];

        foreach ($rows as [$name, $term, $year, $amount, $due]) {
            FeeSchedule::updateOrCreate(
                ['school_id' => $school->id, 'name' => $name, 'term' => $term, 'academic_year' => $year],
                [
                    'amount_minor' => $amount,
                    'currency' => 'NGN',
                    'due_date' => $due,
                    'status' => 'published',
                    'created_by' => $schoolAdmin->id,
                ]
            );
        }
    }

    /**
     * @param  list<User>  $siblings
     */
    private function campaigns(User $student, array $siblings, PlatformFeeConfig $feeConfig, User $schoolAdmin, User $admin): void
    {
        $definitions = [
            [
                'student' => $student,
                'slug' => 'demo-second-term-tuition',
                'title' => 'Second Term Tuition & Science Laboratory Levy',
                'story' => 'I am in SS 2 at Demo Academy and need help covering my second term tuition and '
                    .'the compulsory science laboratory levy. My guardian was recently between jobs and the '
                    .'school has given us until the end of term.',
                'category' => 'tuition',
                'net' => 25000000,
                'raised' => 19500000,
                'fee' => 1950000,
                'disbursed' => 12000000,
                'status' => 'active',
                'featured' => true,
                'deadline' => '2026-12-01',
                'documents' => [
                    ['type' => 'tuition_invoice', 'title' => 'Bursary certified invoice 2025/2026.pdf', 'file_size' => '820 KB'],
                ],
            ],
            [
                'student' => $siblings[0],
                'slug' => 'sade-waec-registration',
                'title' => 'WAEC Registration and Practical Materials',
                'story' => 'Final-year registration fees for WAEC, plus the practical consumables required for '
                    .'chemistry and biology examinations. I have maintained a distinction average and want to '
                    .'sit the exams without interruption.',
                'category' => 'exam',
                'net' => 12000000,
                'raised' => 8200000,
                'fee' => 820000,
                'disbursed' => 0,
                'status' => 'active',
                'featured' => true,
                'deadline' => '2027-01-31',
                'documents' => [
                    ['type' => 'admission_letter', 'title' => 'WAEC candidate registration slip.pdf', 'file_size' => '410 KB'],
                ],
            ],
            [
                'student' => $siblings[1],
                'slug' => 'ibrahim-accounting-kit',
                'title' => 'Accounting Practical Kit and Revision Classes',
                'story' => 'Commercial class practical materials, past-question workbooks and weekend revision '
                    .'classes ahead of the mock examinations.',
                'category' => 'stem_research',
                'net' => 9500000,
                'raised' => 4100000,
                'fee' => 410000,
                'disbursed' => 0,
                'status' => 'active',
                'featured' => false,
                'deadline' => '2027-02-28',
                'documents' => [],
            ],
        ];

        foreach ($definitions as $definition) {
            $gross = (int) ceil($definition['net'] / (1 - $feeConfig->percentage_rate));

            $campaign = Campaign::updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'student_id' => $definition['student']->id,
                    'school_id' => $definition['student']->school_id,
                    'title' => $definition['title'],
                    'story' => $definition['story'],
                    'category' => $definition['category'],
                    'student_name' => $definition['student']->name,
                    'school_name' => School::find($definition['student']->school_id)?->name,
                    'course_of_study' => $definition['student']->profile['department'] ?? null,
                    'grade_level' => $definition['student']->profile['level'] ?? null,
                    'net_base_goal_minor' => $definition['net'],
                    'gross_target_minor' => $gross,
                    'gross_raised_minor' => $definition['raised'],
                    'fee_collected_minor' => $definition['fee'],
                    'disbursed_minor' => $definition['disbursed'],
                    'currency' => 'NGN',
                    'status' => $definition['status'],
                    'overfunding_policy' => 'continue_accepting',
                    'beneficiary_verified' => true,
                    'school_verified' => true,
                    'featured' => $definition['featured'],
                    'donor_count' => intdiv($definition['raised'], 1500000),
                    'deadline_date' => $definition['deadline'],
                    'submitted_at' => now()->subDays(20),
                    'approved_at' => now()->subDays(18),
                    'published_at' => now()->subDays(18),
                ]
            );

            foreach ($definition['documents'] as $document) {
                CampaignDocument::updateOrCreate(
                    ['campaign_id' => $campaign->id, 'title' => $document['title']],
                    [
                        'type' => $document['type'],
                        'file_url' => '#',
                        'file_size' => $document['file_size'],
                        'status' => 'verified',
                        'verified_by' => $admin->name,
                        'uploaded_at' => now()->subDays(19),
                    ]
                );
            }

            CampaignAuditEvent::firstOrCreate(
                ['campaign_id' => $campaign->id, 'action' => 'publish_campaign'],
                [
                    'actor_id' => $schoolAdmin->id,
                    'actor_name' => $schoolAdmin->name,
                    'previous_status' => 'approved',
                    'new_status' => 'published',
                    'reason' => 'Invoice cross-matched with the bursary record.',
                    'occurred_at' => now()->subDays(18),
                ]
            );
        }
    }

    private function ngo(User $ngo): void
    {
        $initiatives = [
            [
                'id' => 'ngo_ini_med_2026',
                'title' => 'Medical & Health Sciences Bursary 2026',
                'code' => 'HSA-MED-2026',
                'description' => 'Full tuition bursaries for clinical-year medical and nursing students across three states.',
                'focusArea' => 'medical',
                'targetTier' => 'tertiary',
                'totalBudget' => 18000000,
                'disbursedBudget' => 6400000,
                'committedBudget' => 9200000,
                'currency' => 'NGN',
                'beneficiaryCount' => 42,
                'targetBeneficiaries' => 60,
                'status' => 'active',
                'startDate' => '2026-01-15',
                'endDate' => '2026-12-20',
                'matchingMultiplier' => 1.0,
                'targetedInstitutions' => ['Demo Academy', 'Lagos State University'],
                'milestones' => [
                    ['id' => 'ms_1', 'title' => 'Cohort selection', 'completed' => true, 'dueDate' => '2026-02-01', 'fundsAllocated' => 0],
                ],
                'createdAt' => '2026-01-10T09:00:00Z',
            ],
            [
                'id' => 'ngo_ini_stem_2026',
                'title' => 'STEM Practical Equipment Fund',
                'code' => 'HSA-STEM-2026',
                'description' => 'Laboratory kits, calculators and revision materials for public secondary schools.',
                'focusArea' => 'stem',
                'targetTier' => 'secondary',
                'totalBudget' => 9000000,
                'disbursedBudget' => 3100000,
                'committedBudget' => 5200000,
                'currency' => 'NGN',
                'beneficiaryCount' => 118,
                'targetBeneficiaries' => 150,
                'status' => 'active',
                'startDate' => '2026-03-01',
                'endDate' => '2026-11-30',
                'matchingMultiplier' => 0.5,
                'targetedInstitutions' => ['Demo Academy'],
                'milestones' => [],
                'createdAt' => '2026-02-20T09:00:00Z',
            ],
        ];

        foreach ($initiatives as $initiative) {
            $this->record('ngo_initiative', $ngo, $initiative['id'], $initiative);
        }

        $beneficiaries = [
            [
                'id' => 'ngo_ben_001',
                'studentId' => null,
                'studentName' => 'Amina Bello',
                'matricNumber' => 'LASU/2023/ENG/00344',
                'schoolId' => '2',
                'schoolName' => 'Demo Academy',
                'educationTier' => 'tertiary',
                'courseOrGrade' => 'Civil Engineering, 300L',
                'initiativeId' => 'ngo_ini_stem_2026',
                'initiativeTitle' => 'STEM Practical Equipment Fund',
                'awardedGrantAmount' => 450000,
                'disbursedToDate' => 300000,
                'academicStanding' => 'in_good_standing',
                'verificationStatus' => 'verified',
                'gpa' => '4.21 / 5.00',
                'academicSession' => '2025/2026 Academic Session',
                'disbursementHistory' => [
                    ['id' => 'disb_001', 'date' => '2026-04-02T10:00:00Z', 'amount' => 150000, 'term' => 'First semester', 'recipientType' => 'school_bursary_direct', 'reference' => 'PMF-BURS-NGO-0001', 'status' => 'completed', 'verifiedBy' => 'Finance officer'],
                    ['id' => 'disb_002', 'date' => '2026-06-18T10:00:00Z', 'amount' => 150000, 'term' => 'Second semester', 'recipientType' => 'school_bursary_direct', 'reference' => 'PMF-BURS-NGO-0002', 'status' => 'completed', 'verifiedBy' => 'Finance officer'],
                ],
                'verificationDocuments' => [
                    ['title' => 'Certified transcript', 'type' => 'transcript', 'verified' => true, 'verifiedDate' => '2026-03-12', 'verifiedBy' => 'Field officer'],
                ],
                'lastAuditNote' => 'Transcript matched against the bursary ledger.',
                'enrolledAt' => '2026-03-01T09:00:00Z',
                'gender' => 'female',
            ],
            [
                'id' => 'ngo_ben_002',
                'studentId' => null,
                'studentName' => 'Chukwuemeka Obi',
                'matricNumber' => 'DEMO/2022/0491',
                'schoolId' => '2',
                'schoolName' => 'Demo Academy',
                'educationTier' => 'secondary',
                'courseOrGrade' => 'Science, SS 2',
                'initiativeId' => 'ngo_ini_med_2026',
                'initiativeTitle' => 'Medical & Health Sciences Bursary 2026',
                'awardedGrantAmount' => 250000,
                'disbursedToDate' => 100000,
                'academicStanding' => 'distinction',
                'verificationStatus' => 'verified',
                'gpa' => '4.68 / 5.00',
                'academicSession' => '2025/2026 Academic Session',
                'disbursementHistory' => [
                    ['id' => 'disb_003', 'date' => '2026-05-04T10:00:00Z', 'amount' => 100000, 'term' => 'Second term', 'recipientType' => 'student_stipend_wallet', 'reference' => 'PMF-BURS-NGO-0003', 'status' => 'completed', 'verifiedBy' => 'Finance officer'],
                ],
                'verificationDocuments' => [],
                'lastAuditNote' => 'Awaiting second-term result slip.',
                'enrolledAt' => '2026-02-10T09:00:00Z',
                'gender' => 'male',
            ],
        ];

        foreach ($beneficiaries as $beneficiary) {
            $this->record('ngo_beneficiary', $ngo, $beneficiary['id'], $beneficiary);
        }

        $donations = [
            ['id' => 'ngo_don_001', 'donorName' => 'Vance Global Education Foundation', 'donorEmail' => 'grants@vancefoundation.test', 'donorType' => 'foundation', 'amount' => 2500000, 'currency' => 'NGN', 'initiativeId' => 'ngo_ini_med_2026', 'initiativeTitle' => 'Medical & Health Sciences Bursary 2026', 'timestamp' => '2026-04-11T10:00:00Z', 'paymentChannel' => 'Paystack', 'reference' => 'PMF-NGO-1001', 'receiptNumber' => 'RCPT-1001', 'taxExemptStatus' => 'tax_deductible_certified', 'status' => 'completed'],
            ['id' => 'ngo_don_002', 'donorName' => 'Lagos Corporate CSR Trust', 'donorEmail' => 'csr@lagostrust.test', 'donorType' => 'corporate_csr', 'amount' => 1200000, 'currency' => 'NGN', 'initiativeId' => 'ngo_ini_stem_2026', 'initiativeTitle' => 'STEM Practical Equipment Fund', 'timestamp' => '2026-05-20T10:00:00Z', 'paymentChannel' => 'Partner Commercial Bank BaaS', 'reference' => 'PMF-NGO-1002', 'receiptNumber' => 'RCPT-1002', 'taxExemptStatus' => 'standard', 'status' => 'reconciled'],
        ];

        foreach ($donations as $donation) {
            $this->record('ngo_donation', $ngo, $donation['id'], $donation);
        }

        $team = [
            ['id' => 'ngo_staff_001', 'name' => 'Dr. Ada Nwosu', 'email' => 'ada@hopescholars.test', 'role' => 'executive_director', 'department' => 'Executive', 'status' => 'active', 'permissions' => [], 'joinedAt' => '2025-01-12T09:00:00Z', 'lastActive' => '2026-11-05T16:20:00Z'],
            ['id' => 'ngo_staff_002', 'name' => 'Tunde Fashola', 'email' => 'tunde@hopescholars.test', 'role' => 'program_manager', 'department' => 'Programmes', 'status' => 'active', 'permissions' => [], 'joinedAt' => '2025-03-04T09:00:00Z', 'lastActive' => '2026-11-06T09:10:00Z'],
            ['id' => 'ngo_staff_003', 'name' => 'Halima Yusuf', 'email' => 'halima@hopescholars.test', 'role' => 'finance_officer', 'department' => 'Finance', 'status' => 'invited', 'permissions' => [], 'joinedAt' => '2026-10-28T09:00:00Z', 'lastActive' => '2026-10-28T09:00:00Z'],
        ];

        foreach ($team as $member) {
            $this->record('ngo_team', $ngo, $member['id'], $member);
        }

        $this->record('ngo_impact', $ngo, 'impact', [
            'totalDeployed' => 9500000,
            'activeInitiatives' => 2,
            'totalBeneficiaries' => 160,
            'institutionsReached' => 12,
            'graduationRatePercent' => 93.5,
            'femaleBeneficiaryPercent' => 58.2,
            'retentionRatePercent' => 88.4,
            'stemAllocationPercent' => 41.0,
        ]);
    }

    private function adminRecords(): void
    {
        foreach ([
            ['aud_001', 'Update platform fee configuration', 'fee_config', 'Olumide Vance', 'success', '2026-11-01T10:00:00Z'],
            ['aud_002', 'Approve campaign demo-second-term-tuition', 'campaign', 'Olumide Vance', 'success', '2026-10-30T12:00:00Z'],
            ['aud_003', 'Reject unverified school application', 'school', 'Compliance desk', 'failure', '2026-10-29T09:30:00Z'],
            ['aud_004', 'Disburse campaign funds to Demo Academy', 'disbursement', 'Finance desk', 'success', '2026-10-27T15:45:00Z'],
        ] as [$id, $action, $category, $actor, $result, $timestamp]) {
            WorkspaceRecord::updateOrCreate(
                ['type' => 'audit_log', 'key' => $id],
                [
                    'data' => [
                        'id' => $id,
                        'action' => $action,
                        'category' => $category,
                        'actor' => $actor,
                        'result' => $result,
                        'timestamp' => $timestamp,
                        'ipAddress' => '102.89.43.'.random_int(2, 240),
                        'hash' => hash('sha256', $id.$action.$timestamp),
                        'previousHash' => null,
                    ],
                    'status' => $result,
                ]
            );
        }

        foreach ([
            ['dsp_001', 'Duplicate settlement', 'A parent reports two debits for the same invoice.', 'open'],
            ['dsp_002', 'Missing campaign disbursement', 'School bursary has not confirmed receipt.', 'under_review'],
        ] as [$id, $title, $description, $status]) {
            WorkspaceRecord::updateOrCreate(
                ['type' => 'dispute', 'key' => $id],
                [
                    'data' => [
                        'id' => $id,
                        'title' => $title,
                        'description' => $description,
                        'raisedBy' => 'support@paymef.test',
                        'openedAt' => '2026-10-26T10:00:00Z',
                    ],
                    'status' => $status,
                ]
            );
        }

        foreach ([
            ['app_001', 'Fatima Suleiman', 'f.suleiman@example.test', 'JSS 1', 'pending'],
            ['app_002', 'Peter Adeyemi', 'p.adeyemi@example.test', 'SS 1', 'approved'],
        ] as [$id, $name, $email, $level, $status]) {
            WorkspaceRecord::updateOrCreate(
                ['type' => 'school_application', 'key' => $id],
                [
                    'school_id' => School::where('slug', 'demo-academy')->value('id'),
                    'data' => ['id' => $id, 'studentName' => $name, 'guardianEmail' => $email, 'level' => $level, 'submittedAt' => '2026-10-20T10:00:00Z'],
                    'status' => $status,
                ]
            );
        }
    }

    /**
     * @param  list<User>  $users
     */
    private function notifications(array $users): void
    {
        foreach ($users as $user) {
            \App\Models\AppNotification::firstOrCreate(
                ['user_id' => $user->id, 'title' => 'Welcome to your PayMef workspace'],
                [
                    'type' => 'verification',
                    'body' => 'Your dashboard now reads directly from the PayMef ledger and school records.',
                    'action_tab' => 'overview',
                    'data' => ['severity' => 'info', 'channels' => [['channel' => 'in_app', 'status' => 'delivered']]],
                ]
            );
        }
    }

    /**
     * One pending bank-account request per student. Numbers are issued by the
     * partner bank only, so no NUBAN is seeded here.
     */
    private function bankAccounts(User $student, User $parent): void
    {
        $students = collect([$student])->merge($parent->children()->get());

        foreach ($students->unique('id') as $subject) {
            \App\Models\BankAccount::updateOrCreate(
                [
                    'user_id' => $subject->id,
                    'account_type' => 'student_savings',
                    'currency' => 'NGN',
                ],
                [
                    'requested_by' => $parent->id,
                    'provider' => 'partner_bank',
                    'account_number' => null,
                    'account_name' => $subject->name,
                    'status' => 'pending_partner_bank_approval',
                    'application' => [
                        'account_type' => 'student_savings',
                        'currency' => 'NGN',
                        'source' => 'demo_seed',
                    ],
                    'metadata' => [
                        'bank_rails' => 'awaiting_partner_approval',
                        'note' => 'Account number is issued only by the licensed partner bank.',
                    ],
                ]
            );
        }
    }

    private function bankApplication(User $student): void
    {
        WorkspaceRecord::updateOrCreate(
            ['type' => 'bank_application', 'key' => 'bank_app_001'],
            [
                'owner_id' => $student->id,
                'status' => 'awaiting_partner_approval',
                'data' => [
                    'id' => 'bank_app_001',
                    'userId' => (string) $student->id,
                    'requestedAt' => '2026-10-01T09:00:00Z',
                    'status' => 'awaiting_partner_approval',
                    'message' => 'Virtual account issuance is queued until the partner bank integration is approved.',
                ],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $type, User $owner, string $key, array $payload): WorkspaceRecord
    {
        return WorkspaceRecord::updateOrCreate(
            ['type' => $type, 'key' => $key],
            [
                'owner_id' => $owner->id,
                'data' => $payload,
                'status' => (string) ($payload['status'] ?? 'active'),
            ]
        );
    }
}
