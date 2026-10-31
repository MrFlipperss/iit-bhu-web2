<?php
// includes/seed_users.php   [M4]
// Demo accounts for all four roles. The password comes from DEMO_PASSWORD (env/.env),
// is stored only as a password_hash(), and is listed in the README for judges.
//
// Accounts are tied to real dataset identities so permissions are meaningful:
//  - two admins, so an admin's own request (e.g. BR-01 from the Dean's office) can be
//    approved by the other admin and never by its requester
//  - the coordinator and faculty map to requesters who own dataset requests
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CIT_DEMO_USERS = [
    ['dean@cit.demo',        'Dean of Academics',      'admin',                  'Office of the Dean of Academics', null,  null],
    ['spectra@cit.demo',     'Spectra Core Team',      'admin',                  'Spectra Core Team',               null,  null],
    ['coord.ece@cit.demo',   'ECE Coordinator',        'department_coordinator', 'ECE Department',                  null,  'ECE'],
    ['f12@cit.demo',         'Dr. Amit Behera',        'faculty',                'Dr. Amit Behera (F12)',           'F12', null],
    ['dramatics@cit.demo',   'Dramatics Society',      'club',                   'Dramatics Society',               null,  null],
];

function cit_check_demo_password(string $password): void
{
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('DEMO_PASSWORD must be set (env or .env) and be at least 8 characters.');
    }
}

/** Insert or refresh the demo accounts. Returns the usernames. */
function cit_seed_demo_users(PDO $db, string $password): array
{
    cit_check_demo_password($password);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        'INSERT INTO users (username, display_name, role, password_hash, requester_name, faculty_id, department)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), role = VALUES(role),
           password_hash = VALUES(password_hash), requester_name = VALUES(requester_name),
           faculty_id = VALUES(faculty_id), department = VALUES(department), is_active = 1');
    foreach (CIT_DEMO_USERS as [$username, $name, $role, $requester, $facultyId, $dept]) {
        $stmt->execute([$username, $name, $role, $hash, $requester, $facultyId, $dept]);
    }
    return array_column(CIT_DEMO_USERS, 0);
}
