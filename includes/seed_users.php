<?php
// includes/seed_users.php   [M4]
// Complete user accounts across all roles (Admin, Department Coordinator, Club Representative, Faculty).
// The password comes from DEMO_PASSWORD (env/.env) or defaults to 'Kian@2407'.
// Stored securely with password_hash() using PHP's native bcrypt.
//
// Accounts are mapped directly to official dataset entities:
//  - Admins: Dean of Academics (BR-03) and Spectra Core Team (BR-01, 11a/b, 16, 20) with cross-approval
//  - Coordinators: All 8 academic departments + Training & Placement Cell (BR-13)
//  - Clubs: All 11 student clubs from booking requests (Coding, Robotics, Dance, Dramatics, etc.)
//  - Faculty: All 40 faculty members (F01 to F40) linked to faculty ID & department for leave & schedule tracking
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CIT_DEMO_USERS = [
    // --- ADMINS (2) ---
    ['dean@cit.demo',        'Dean of Academics',            'admin',                  'Office of the Dean of Academics', 'F37', 'ADMIN'],
    ['spectra@cit.demo',     'Spectra Core Team',            'admin',                  'Spectra Core Team',               null,  null],

    // --- DEPARTMENT COORDINATORS (9) ---
    ['coord.ece@cit.demo',   'ECE Coordinator',              'department_coordinator', 'ECE Department',                  null,  'ECE'],
    ['coord.cse@cit.demo',   'CSE Coordinator',              'department_coordinator', 'CSE Department',                  null,  'CSE'],
    ['coord.ee@cit.demo',    'EE Coordinator',               'department_coordinator', 'EE Department',                   null,  'EE'],
    ['coord.me@cit.demo',    'ME Coordinator',               'department_coordinator', 'ME Department',                   null,  'ME'],
    ['coord.ce@cit.demo',    'CE Coordinator',               'department_coordinator', 'Civil Engineering Department',   null,  'CE'],
    ['coord.ma@cit.demo',    'Mathematics Coordinator',      'department_coordinator', 'Mathematics Department',          null,  'MA'],
    ['coord.ph@cit.demo',    'Physics Coordinator',          'department_coordinator', 'Physics Department',              null,  'PH'],
    ['coord.hss@cit.demo',   'HSS Coordinator',              'department_coordinator', 'HSS Department',                  null,  'HSS'],
    ['tnp@cit.demo',         'Training & Placement Officer', 'department_coordinator', 'Training and Placement Cell',     null,  'CSE'],

    // --- STUDENT CLUBS (11) ---
    ['astronomy@cit.demo',   'Astronomy Club',               'club',                   'Astronomy Club',                  null,  null],
    ['coding@cit.demo',      'Coding Club',                  'club',                   'Coding Club',                     null,  null],
    ['dance@cit.demo',       'Dance Club',                   'club',                   'Dance Club',                      null,  null],
    ['dramatics@cit.demo',   'Dramatics Society',            'club',                   'Dramatics Society',               null,  null],
    ['ecell@cit.demo',       'E-Cell',                       'club',                   'E-Cell',                          null,  null],
    ['gaming@cit.demo',      'Gaming Society',               'club',                   'Gaming Society',                  null,  null],
    ['literary@cit.demo',    'Literary Society',             'club',                   'Literary Society',                null,  null],
    ['music@cit.demo',       'Music Club',                   'club',                   'Music Club',                      null,  null],
    ['photo@cit.demo',       'Photography Club',             'club',                   'Photography Club',                null,  null],
    ['quiz@cit.demo',        'Quiz Club',                    'club',                   'Quiz Club',                       null,  null],
    ['robotics@cit.demo',    'Robotics Club',                'club',                   'Robotics Club',                   null,  null],

    // --- FACULTY MEMBERS (40) ---
    ['f01@cit.demo',         'Dr. Ananya Mishra',            'faculty',                'Dr. Ananya Mishra (F01)',         'F01', 'CSE'],
    ['f02@cit.demo',         'Dr. Rohit Sahoo',              'faculty',                'Dr. Rohit Sahoo (F02)',           'F02', 'CSE'],
    ['f03@cit.demo',         'Dr. Kavya Iyer',               'faculty',                'Dr. Kavya Iyer (F03)',            'F03', 'CSE'],
    ['f04@cit.demo',         'Dr. Sandeep Patnaik',          'faculty',                'Dr. Sandeep Patnaik (F04)',       'F04', 'CSE'],
    ['f05@cit.demo',         'Dr. Meera Nair',               'faculty',                'Dr. Meera Nair (F05)',            'F05', 'CSE'],
    ['f06@cit.demo',         'Dr. Arjun Mehta',              'faculty',                'Dr. Arjun Mehta (F06)',           'F06', 'CSE'],
    ['f07@cit.demo',         'Dr. Pooja Das',                'faculty',                'Dr. Pooja Das (F07)',             'F07', 'CSE'],
    ['f08@cit.demo',         'Prof. Vikram Rao (Visiting)',  'faculty',                'Prof. Vikram Rao (Visiting) (F08)','F08','CSE'],
    ['f09@cit.demo',         'Dr. Ishita Banerjee',          'faculty',                'Dr. Ishita Banerjee (F09)',       'F09', 'ECE'],
    ['f10@cit.demo',         'Dr. Suresh Pradhan',           'faculty',                'Dr. Suresh Pradhan (F10)',        'F10', 'ECE'],
    ['f11@cit.demo',         'Dr. Neha Kulkarni',            'faculty',                'Dr. Neha Kulkarni (F11)',         'F11', 'ECE'],
    ['f12@cit.demo',         'Dr. Amit Behera',              'faculty',                'Dr. Amit Behera (F12)',           'F12', 'ECE'],
    ['f13@cit.demo',         'Dr. Ritu Sharma',              'faculty',                'Dr. Ritu Sharma (F13)',           'F13', 'ECE'],
    ['f14@cit.demo',         'Dr. Kunal Joshi',              'faculty',                'Dr. Kunal Joshi (F14)',           'F14', 'EE'],
    ['f15@cit.demo',         'Dr. Sneha Mohanty',            'faculty',                'Dr. Sneha Mohanty (F15)',         'F15', 'EE'],
    ['f16@cit.demo',         'Dr. Rakesh Panda',             'faculty',                'Dr. Rakesh Panda (F16)',          'F16', 'EE'],
    ['f17@cit.demo',         'Dr. Divya Menon',              'faculty',                'Dr. Divya Menon (F17)',           'F17', 'EE'],
    ['f18@cit.demo',         'Dr. Manoj Tripathy',           'faculty',                'Dr. Manoj Tripathy (F18)',        'F18', 'EE'],
    ['f19@cit.demo',         'Dr. Shalini Gupta',            'faculty',                'Dr. Shalini Gupta (F19)',         'F19', 'MA'],
    ['f20@cit.demo',         'Dr. Prakash Nayak',            'faculty',                'Dr. Prakash Nayak (F20)',         'F20', 'MA'],
    ['f21@cit.demo',         'Dr. Lakshmi Reddy',            'faculty',                'Dr. Lakshmi Reddy (F21)',         'F21', 'PH'],
    ['f22@cit.demo',         'Dr. Harish Dash',              'faculty',                'Dr. Harish Dash (F22)',           'F22', 'PH'],
    ['f23@cit.demo',         'Dr. Farah Khan',               'faculty',                'Dr. Farah Khan (F23)',            'F23', 'PH'],
    ['f24@cit.demo',         'Dr. Gaurav Jena',              'faculty',                'Dr. Gaurav Jena (F24)',           'F24', 'PH'],
    ['f25@cit.demo',         'Dr. Tanvi Desai',              'faculty',                'Dr. Tanvi Desai (F25)',           'F25', 'ME'],
    ['f26@cit.demo',         'Dr. Abhishek Rout',            'faculty',                'Dr. Abhishek Rout (F26)',         'F26', 'ME'],
    ['f27@cit.demo',         'Dr. Sunita Bose',              'faculty',                'Dr. Sunita Bose (F27)',           'F27', 'ME'],
    ['f28@cit.demo',         'Dr. Nikhil Swain',             'faculty',                'Dr. Nikhil Swain (F28)',          'F28', 'ME'],
    ['f29@cit.demo',         'Dr. Priya Raman',              'faculty',                'Dr. Priya Raman (F29)',           'F29', 'ME'],
    ['f30@cit.demo',         'Dr. Debashish Sethi',          'faculty',                'Dr. Debashish Sethi (F30)',       'F30', 'ME'],
    ['f31@cit.demo',         'Dr. Anjali Verma',             'faculty',                'Dr. Anjali Verma (F31)',          'F31', 'CE'],
    ['f32@cit.demo',         'Dr. Sameer Khuntia',           'faculty',                'Dr. Sameer Khuntia (F32)',        'F32', 'CE'],
    ['f33@cit.demo',         'Dr. Rekha Pillai',             'faculty',                'Dr. Rekha Pillai (F33)',          'F33', 'CE'],
    ['f34@cit.demo',         'Dr. Varun Acharya',            'faculty',                'Dr. Varun Acharya (F34)',         'F34', 'CE'],
    ['f35@cit.demo',         'Dr. Bhavna Saxena',            'faculty',                'Dr. Bhavna Saxena (F35)',         'F35', 'CE'],
    ['f36@cit.demo',         'Dr. Ashok Mahapatra',          'faculty',                'Dr. Ashok Mahapatra (F36)',       'F36', 'HSS'],
    ['f37@cit.demo',         'Prof. Shreya Kapoor (Dean)',   'faculty',                'Office of the Dean of Academics', 'F37', 'ADMIN'],
    ['f38@cit.demo',         'Dr. Imran Qureshi',            'faculty',                'Dr. Imran Qureshi (F38)',         'F38', 'HSS'],
    ['f39@cit.demo',         'Dr. Radhika Hegde',            'faculty',                'Dr. Radhika Hegde (F39)',         'F39', 'HSS'],
    ['f40@cit.demo',         'Dr. Om Prakash Sahu',          'faculty',                'Dr. Om Prakash Sahu (F40)',       'F40', 'HSS'],
];

function cit_check_demo_password(string &$password): void
{
    if (strlen($password) < 8) {
        $env = cit_env('DEMO_PASSWORD');
        $password = ($env !== null && strlen($env) >= 8) ? $env : 'Kian@2407';
    }
}

/** Insert or refresh all user accounts. Returns the list of usernames. */
function cit_seed_demo_users(PDO $db, string $password = ''): array
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
