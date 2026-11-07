<?php
/** Shared student field definitions so the admission form, import and export stay in sync. */

const STUDENT_FIELDS = [
    // field => [label, group, type, master list]. 'select' and 'suggest' read their values from Masters → Dropdown Lists.
    'admission_no' => ['Admission No', 'basic', 'text'],
    'admission_date' => ['Admission Date', 'basic', 'date'],
    'first_name' => ['First Name', 'basic', 'text'],
    'last_name' => ['Last / Surname', 'basic', 'text'],
    'dob' => ['Date of Birth', 'basic', 'date'],
    'gender' => ['Gender', 'basic', 'select', 'gender'],
    'blood_group' => ['Blood Group', 'basic', 'select', 'blood_group'],
    'aadhaar' => ['Aadhaar No', 'basic', 'text'],
    'category' => ['Category', 'basic', 'select', 'category'],
    'religion' => ['Religion', 'basic', 'select', 'religion'],
    'caste_certificate_no' => ['Caste Certificate No', 'basic', 'text'],
    'mother_tongue' => ['Mother Tongue', 'basic', 'select', 'mother_tongue'],
    'nationality' => ['Nationality', 'basic', 'select', 'nationality'],
    'apaar_id' => ['APAAR ID', 'basic', 'text'],
    'pen_no' => ['PEN (UDISE+)', 'basic', 'text'],
    'father_name' => ['Father Name', 'parents', 'text'],
    'father_phone' => ['Father Mobile', 'parents', 'tel'],
    'father_occupation' => ['Father Occupation', 'parents', 'suggest', 'occupation'],
    'father_email' => ['Parent Email', 'parents', 'email'],
    'mother_name' => ['Mother Name', 'parents', 'text'],
    'mother_phone' => ['Mother Mobile', 'parents', 'tel'],
    'mother_occupation' => ['Mother Occupation', 'parents', 'suggest', 'occupation'],
    'guardian_name' => ['Guardian Name', 'parents', 'text'],
    'guardian_phone' => ['Guardian Mobile', 'parents', 'tel'],
    'guardian_relation' => ['Guardian Relation', 'parents', 'select', 'guardian_relation'],
    'whatsapp' => ['WhatsApp No (for fee alerts)', 'parents', 'tel'],
    'annual_income' => ['Family Annual Income', 'parents', 'select', 'income_range'],
    'address' => ['Address', 'address', 'textarea'],
    'city' => ['City / Village', 'address', 'suggest', 'city'],
    'state' => ['State', 'address', 'select', 'state'],
    'pincode' => ['PIN Code', 'address', 'text'],
    'previous_school' => ['Previous School', 'previous', 'text'],
    'previous_class' => ['Class Passed', 'previous', 'select', 'previous_class'],
    'tc_no' => ['TC No (from previous school)', 'previous', 'text'],
    'medical_notes' => ['Medical Notes / Allergies', 'other', 'textarea'],
    'remarks' => ['Remarks', 'other', 'textarea'],
];

const STUDENT_GROUPS = [
    'basic' => 'Student details', 'parents' => 'Parents / guardian', 'address' => 'Address',
    'previous' => 'Previous school', 'other' => 'Other',
];

function suggest_admission_no(): string
{
    $prefix = setting('admission_prefix', 'ADM');
    $max = 0;
    foreach (rows('SELECT admission_no FROM students WHERE admission_no LIKE ?', [$prefix . '%']) as $r) {
        if (preg_match('/(\d+)$/', $r['admission_no'], $m)) $max = max($max, (int)$m[1]);
    }
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function validate_student(array $d): array
{
    $err = [];
    if ($d['admission_no'] === '') $err[] = 'Admission number is required.';
    if ($d['first_name'] === '') $err[] = 'First name is required.';
    foreach (['father_phone', 'mother_phone', 'guardian_phone', 'whatsapp'] as $f) {
        if (!empty($d[$f]) && !preg_match('/^\+?[0-9 \-]{10,15}$/', $d[$f])) $err[] = STUDENT_FIELDS[$f][0] . ' looks wrong (10 digits).';
    }
    if (!empty($d['aadhaar']) && !preg_match('/^\d{4}\s?\d{4}\s?\d{4}$/', $d['aadhaar'])) $err[] = 'Aadhaar should be 12 digits.';
    if (!empty($d['pincode']) && !preg_match('/^\d{6}$/', $d['pincode'])) $err[] = 'PIN code should be 6 digits.';
    if (!empty($d['father_email']) && !filter_var($d['father_email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email looks wrong.';
    if (!empty($d['dob']) && $d['dob'] > today()) $err[] = 'Date of birth is in the future.';
    return $err;
}

function sections_json(bool $scoped = false): string
{
    $map = [];
    foreach (rows('SELECT id, class_id, name FROM sections ORDER BY name') as $s) {
        if ($scoped && !in_scope((int)$s['id'])) continue;
        $map[$s['class_id']][] = ['id' => $s['id'], 'name' => $s['name']];
    }
    return json_encode($map);
}

function student_enrollment(int $studentId, int $yearId): ?array
{
    return row('SELECT e.*, c.name AS class_name, sec.name AS section_name, tr.name AS route_name
                FROM enrollments e JOIN classes c ON c.id = e.class_id LEFT JOIN sections sec ON sec.id = e.section_id
                LEFT JOIN transport_routes tr ON tr.id = e.transport_route_id
                WHERE e.student_id = ? AND e.academic_year_id = ?', [$studentId, $yearId]);
}
