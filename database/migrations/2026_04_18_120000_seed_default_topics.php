<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the two public reporting topics that meldeplattform.tum.de shipped
 * with on the Go stack: "IT Sicherheit / IT Security" and "Compliance".
 * Field labels, helper texts, order, required-flags and select options are
 * transcribed verbatim from the upstream HTML.
 *
 * Idempotent: reruns skip any topic whose German name already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->defaults() as $topic) {
            $this->upsertTopic($topic);
        }
    }

    public function down(): void
    {
        $names = array_column($this->defaults(), 'name_de');
        // fields cascade via foreign key
        DB::table('topics')->whereIn('name_de', $names)->delete();
    }

    /**
     * @param  array{
     *     name_de: string, name_en: string,
     *     summary_de: string, summary_en: string,
     *     email: ?string,
     *     fields: list<array{
     *         name_de: string, name_en: string,
     *         description_de: ?string, description_en: ?string,
     *         type: string, required: bool, choices: ?list<string>,
     *     }>,
     * }  $topic
     */
    private function upsertTopic(array $topic): void
    {
        $exists = DB::table('topics')->where('name_de', $topic['name_de'])->exists();
        if ($exists) {
            return;
        }

        $now = now();
        $topicId = DB::table('topics')->insertGetId([
            'name_de' => $topic['name_de'],
            'name_en' => $topic['name_en'],
            'summary_de' => $topic['summary_de'],
            'summary_en' => $topic['summary_en'],
            'email' => $topic['email'],
            'contacts' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($topic['fields'] as $position => $field) {
            DB::table('fields')->insert([
                'topic_id' => $topicId,
                'name_de' => $field['name_de'],
                'name_en' => $field['name_en'],
                'description_de' => $field['description_de'],
                'description_en' => $field['description_en'],
                'type' => $field['type'],
                'required' => $field['required'],
                'choices' => $field['choices'] !== null ? json_encode($field['choices']) : null,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return list<array{
     *     name_de: string, name_en: string,
     *     summary_de: string, summary_en: string,
     *     email: ?string,
     *     fields: list<array{
     *         name_de: string, name_en: string,
     *         description_de: ?string, description_en: ?string,
     *         type: string, required: bool, choices: ?list<string>,
     *     }>,
     * }>
     */
    private function defaults(): array
    {
        return [
            [
                'name_de' => 'IT Sicherheit',
                'name_en' => 'IT Security',
                'email' => 'it-sicherheit@tum.de',
                'summary_de' => 'Melden Sie Schwachstellen und Sicherheitsvorfälle in IT-Systemen der Technischen Universität München – vertraulich und auf Wunsch anonym. Ihre Meldung wird vom TUM IT-Sicherheitsteam bearbeitet und nicht an Dritte weitergegeben. Bitte beschreiben Sie den Vorfall so konkret wie möglich, damit wir schnell reagieren können.',
                'summary_en' => 'Report vulnerabilities and security incidents in IT systems of the Technical University of Munich – confidentially, and anonymously if you wish. Your report is handled by the TUM IT Security team and is not shared with third parties. Please describe the incident as specifically as possible so we can respond quickly.',
                'fields' => [
                    [
                        'name_de' => 'Welche Art von Sicherheitsrisiko haben Sie entdeckt?',
                        'name_en' => 'What type of security risk did you discover?',
                        'description_de' => 'z. B. Schwachstelle in einer Anwendung, verdächtige Aktivität im Netzwerk, unautorisierter Datenzugriff.',
                        'description_en' => 'e.g., a vulnerability in an application, suspicious network activity, unauthorized data access.',
                        'type' => 'textarea',
                        'required' => true,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Wo haben Sie das Sicherheitsrisiko entdeckt?',
                        'name_en' => 'Where did you discover the security risk?',
                        'description_de' => 'z. B. Name des Systems, der Anwendung, Ort oder Abteilung.',
                        'description_en' => 'e.g., name of the system or application, location, or department.',
                        'type' => 'textarea',
                        'required' => true,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Wann haben Sie das Sicherheitsrisiko entdeckt?',
                        'name_en' => 'When did you discover the security risk?',
                        'description_de' => 'Datum, an dem das Risiko erstmals aufgetreten oder bemerkt wurde.',
                        'description_en' => 'Date when the risk first occurred or was noticed.',
                        'type' => 'date',
                        'required' => true,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Welche Auswirkungen kann das Sicherheitsrisiko haben?',
                        'name_en' => 'What is the potential impact of the security risk?',
                        'description_de' => 'z. B. Datenverlust, Datenabfluss, Reputationsschaden, Ausfall von Diensten.',
                        'description_en' => 'e.g., data loss, data leakage, reputational damage, service outage.',
                        'type' => 'textarea',
                        'required' => false,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Wie haben Sie bisher reagiert?',
                        'name_en' => 'How have you responded so far?',
                        'description_de' => 'z. B. Meldung an die/den System-Verantwortliche:n, Abschalten des Systems, keine Reaktion.',
                        'description_en' => 'e.g., reported to the system owner, shut the system down, no response yet.',
                        'type' => 'textarea',
                        'required' => false,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Dateien (optional)',
                        'name_en' => 'Files (optional)',
                        'description_de' => 'Screenshots, Logs oder andere Nachweise, die uns helfen das Problem zu verstehen.',
                        'description_en' => 'Screenshots, logs, or other evidence that helps us understand the issue.',
                        'type' => 'files',
                        'required' => false,
                        'choices' => null,
                    ],
                ],
            ],
            [
                'name_de' => 'Compliance',
                'name_en' => 'Compliance',
                'email' => null,
                'summary_de' => 'Melden Sie mögliche Verstöße gegen die TUM Codes of Conduct oder den TUM Respekt Guide, wissenschaftliches Fehlverhalten, Belästigung, Diskriminierung oder Interessenkonflikte – vertraulich und auf Wunsch anonym. Ihre Meldung wird vom TUM Compliance Office (TUM CO) weisungsunabhängig und nach festgelegten Verfahren geprüft, einschließlich des Rechts auf Stellungnahme und Gegendarstellung. Die Identitäten hinweisgebender und betroffener Personen werden streng vertraulich behandelt.',
                'summary_en' => 'Report potential violations of the TUM Codes of Conduct or TUM Respect Guide, academic misconduct, harassment, discrimination, or conflicts of interest – confidentially, and anonymously if you wish. Your report is examined independently by the TUM Compliance Office (TUM CO) following formal procedures that include the right to respond and to present a counter-statement. The identities of informants and affected persons are treated with strict confidentiality.',
                'fields' => [
                    [
                        'name_de' => 'Schwerpunkt',
                        'name_en' => 'Focus',
                        'description_de' => 'Welche Kategorie trifft am ehesten auf den Vorfall zu?',
                        'description_en' => 'Which category fits the incident most accurately?',
                        'type' => 'select',
                        'required' => true,
                        'choices' => [
                            'Korruption / Interessenskonflikte - Corruption / Conflict of interest',
                            'Wissenschaftliches Fehlverhalten - Academic misconduct',
                            'Belästigung / Sexuelle Belästigung - Harassment / Sexual harassment',
                            'Diskriminierung - Discrimination',
                            'Verletzung der TUM Codes of Conduct / TUM Respect Guide - Violation of the TUM Codes of Conduct / TUM Respect Guide',
                            'Anderes - Other',
                        ],
                    ],
                    [
                        'name_de' => 'Beschreibung des Vorfalls',
                        'name_en' => 'Incident description',
                        'description_de' => 'Bitte beschreiben Sie den Vorfall so konkret wie möglich – wann, wo, wer, was.',
                        'description_en' => 'Please describe the incident as specifically as possible – when, where, who, what.',
                        'type' => 'textarea',
                        'required' => true,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'TUM-Einheit',
                        'name_en' => 'TUM unit',
                        'description_de' => 'In welcher School, Abteilung oder Einrichtung hat sich der Vorfall ereignet?',
                        'description_en' => 'In which school, department, or office did the incident take place?',
                        'type' => 'text',
                        'required' => true,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Ihre Verbindung zur TUM',
                        'name_en' => 'Your affiliation to TUM',
                        'description_de' => 'In welchem Verhältnis stehen Sie zur TU München?',
                        'description_en' => 'What is your relationship to the Technical University of Munich?',
                        'type' => 'select',
                        'required' => true,
                        'choices' => [
                            'Bedienstete - Employee',
                            'Studierende - Student',
                            'Extern - External',
                        ],
                    ],
                    [
                        'name_de' => 'Wurden Dritte bereits informiert?',
                        'name_en' => 'Have third parties been informed?',
                        'description_de' => 'Intern oder extern – z. B. Vorgesetzte, Ombudsperson, Polizei. Hilft dem TUM CO bei der weiteren Prüfung.',
                        'description_en' => 'Internal or external – e.g., supervisor, ombudsperson, police. Helps the TUM CO coordinate the review.',
                        'type' => 'text',
                        'required' => true,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Name (optional)',
                        'name_en' => 'Name (optional)',
                        'description_de' => 'Nur angeben, wenn das TUM CO Sie für Rückfragen direkt kontaktieren darf. Ihre Meldung bleibt vertraulich.',
                        'description_en' => 'Only provide if you want the TUM CO to be able to contact you directly for clarifications. Your report remains confidential.',
                        'type' => 'text',
                        'required' => false,
                        'choices' => null,
                    ],
                    [
                        'name_de' => 'Dokumente (optional)',
                        'name_en' => 'Documents (optional)',
                        'description_de' => 'z. B. Dokumente, die den Vorfall belegen oder bei der Einschätzung helfen.',
                        'description_en' => 'e.g., documents that substantiate or help assess the incident.',
                        'type' => 'files',
                        'required' => false,
                        'choices' => null,
                    ],
                ],
            ],
        ];
    }
};
