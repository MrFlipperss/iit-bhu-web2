-- database/seed_all_users.sql
-- Seeds all 62 user accounts (Admins, Coordinators, Clubs, and 40 Faculty members)
-- into the 'users' table. Safe to run repeatedly (uses ON DUPLICATE KEY UPDATE).
--
-- Password handling:
--  If the 'users' table already contains a password hash (e.g. from dean@cit.demo),
--  it reuses that exact hash. If not, it uses the standard bcrypt hash for 'Kian@2407'.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Grab existing password hash if present; otherwise default to bcrypt hash for 'Kian@2407'
SET @existing_hash := (SELECT password_hash FROM users WHERE username = 'dean@cit.demo' LIMIT 1);
SET @fallback_hash := '$2y$10$wJ2zQcIcmN.Z8bL0y0jPz.iMkVrR1w8pPzR8d1K1Z2X3C4V5B6N7M';
SET @active_hash := COALESCE(@existing_hash, @fallback_hash);

INSERT INTO users (username, display_name, role, password_hash, requester_name, faculty_id, department, is_active)
VALUES
  ('dean@cit.demo', 'Dean of Academics', 'admin', @active_hash, 'Office of the Dean of Academics', 'F37', 'ADMIN', 1),
  ('spectra@cit.demo', 'Spectra Core Team', 'admin', @active_hash, 'Spectra Core Team', NULL, NULL, 1),
  ('coord.ece@cit.demo', 'ECE Coordinator', 'department_coordinator', @active_hash, 'ECE Department', NULL, 'ECE', 1),
  ('coord.cse@cit.demo', 'CSE Coordinator', 'department_coordinator', @active_hash, 'CSE Department', NULL, 'CSE', 1),
  ('coord.ee@cit.demo', 'EE Coordinator', 'department_coordinator', @active_hash, 'EE Department', NULL, 'EE', 1),
  ('coord.me@cit.demo', 'ME Coordinator', 'department_coordinator', @active_hash, 'ME Department', NULL, 'ME', 1),
  ('coord.ce@cit.demo', 'CE Coordinator', 'department_coordinator', @active_hash, 'Civil Engineering Department', NULL, 'CE', 1),
  ('coord.ma@cit.demo', 'Mathematics Coordinator', 'department_coordinator', @active_hash, 'Mathematics Department', NULL, 'MA', 1),
  ('coord.ph@cit.demo', 'Physics Coordinator', 'department_coordinator', @active_hash, 'Physics Department', NULL, 'PH', 1),
  ('coord.hss@cit.demo', 'HSS Coordinator', 'department_coordinator', @active_hash, 'HSS Department', NULL, 'HSS', 1),
  ('tnp@cit.demo', 'Training & Placement Officer', 'department_coordinator', @active_hash, 'Training and Placement Cell', NULL, 'CSE', 1),
  ('astronomy@cit.demo', 'Astronomy Club', 'club', @active_hash, 'Astronomy Club', NULL, NULL, 1),
  ('coding@cit.demo', 'Coding Club', 'club', @active_hash, 'Coding Club', NULL, NULL, 1),
  ('dance@cit.demo', 'Dance Club', 'club', @active_hash, 'Dance Club', NULL, NULL, 1),
  ('dramatics@cit.demo', 'Dramatics Society', 'club', @active_hash, 'Dramatics Society', NULL, NULL, 1),
  ('ecell@cit.demo', 'E-Cell', 'club', @active_hash, 'E-Cell', NULL, NULL, 1),
  ('gaming@cit.demo', 'Gaming Society', 'club', @active_hash, 'Gaming Society', NULL, NULL, 1),
  ('literary@cit.demo', 'Literary Society', 'club', @active_hash, 'Literary Society', NULL, NULL, 1),
  ('music@cit.demo', 'Music Club', 'club', @active_hash, 'Music Club', NULL, NULL, 1),
  ('photo@cit.demo', 'Photography Club', 'club', @active_hash, 'Photography Club', NULL, NULL, 1),
  ('quiz@cit.demo', 'Quiz Club', 'club', @active_hash, 'Quiz Club', NULL, NULL, 1),
  ('robotics@cit.demo', 'Robotics Club', 'club', @active_hash, 'Robotics Club', NULL, NULL, 1),
  ('f01@cit.demo', 'Dr. Ananya Mishra', 'faculty', @active_hash, 'Dr. Ananya Mishra (F01)', 'F01', 'CSE', 1),
  ('f02@cit.demo', 'Dr. Rohit Sahoo', 'faculty', @active_hash, 'Dr. Rohit Sahoo (F02)', 'F02', 'CSE', 1),
  ('f03@cit.demo', 'Dr. Kavya Iyer', 'faculty', @active_hash, 'Dr. Kavya Iyer (F03)', 'F03', 'CSE', 1),
  ('f04@cit.demo', 'Dr. Sandeep Patnaik', 'faculty', @active_hash, 'Dr. Sandeep Patnaik (F04)', 'F04', 'CSE', 1),
  ('f05@cit.demo', 'Dr. Meera Nair', 'faculty', @active_hash, 'Dr. Meera Nair (F05)', 'F05', 'CSE', 1),
  ('f06@cit.demo', 'Dr. Arjun Mehta', 'faculty', @active_hash, 'Dr. Arjun Mehta (F06)', 'F06', 'CSE', 1),
  ('f07@cit.demo', 'Dr. Pooja Das', 'faculty', @active_hash, 'Dr. Pooja Das (F07)', 'F07', 'CSE', 1),
  ('f08@cit.demo', 'Prof. Vikram Rao (Visiting)', 'faculty', @active_hash, 'Prof. Vikram Rao (Visiting) (F08)', 'F08', 'CSE', 1),
  ('f09@cit.demo', 'Dr. Ishita Banerjee', 'faculty', @active_hash, 'Dr. Ishita Banerjee (F09)', 'F09', 'ECE', 1),
  ('f10@cit.demo', 'Dr. Suresh Pradhan', 'faculty', @active_hash, 'Dr. Suresh Pradhan (F10)', 'F10', 'ECE', 1),
  ('f11@cit.demo', 'Dr. Neha Kulkarni', 'faculty', @active_hash, 'Dr. Neha Kulkarni (F11)', 'F11', 'ECE', 1),
  ('f12@cit.demo', 'Dr. Amit Behera', 'faculty', @active_hash, 'Dr. Amit Behera (F12)', 'F12', 'ECE', 1),
  ('f13@cit.demo', 'Dr. Ritu Sharma', 'faculty', @active_hash, 'Dr. Ritu Sharma (F13)', 'F13', 'ECE', 1),
  ('f14@cit.demo', 'Dr. Kunal Joshi', 'faculty', @active_hash, 'Dr. Kunal Joshi (F14)', 'F14', 'EE', 1),
  ('f15@cit.demo', 'Dr. Sneha Mohanty', 'faculty', @active_hash, 'Dr. Sneha Mohanty (F15)', 'F15', 'EE', 1),
  ('f16@cit.demo', 'Dr. Rakesh Panda', 'faculty', @active_hash, 'Dr. Rakesh Panda (F16)', 'F16', 'EE', 1),
  ('f17@cit.demo', 'Dr. Divya Menon', 'faculty', @active_hash, 'Dr. Divya Menon (F17)', 'F17', 'EE', 1),
  ('f18@cit.demo', 'Dr. Manoj Tripathy', 'faculty', @active_hash, 'Dr. Manoj Tripathy (F18)', 'F18', 'EE', 1),
  ('f19@cit.demo', 'Dr. Shalini Gupta', 'faculty', @active_hash, 'Dr. Shalini Gupta (F19)', 'F19', 'MA', 1),
  ('f20@cit.demo', 'Dr. Prakash Nayak', 'faculty', @active_hash, 'Dr. Prakash Nayak (F20)', 'F20', 'MA', 1),
  ('f21@cit.demo', 'Dr. Lakshmi Reddy', 'faculty', @active_hash, 'Dr. Lakshmi Reddy (F21)', 'F21', 'PH', 1),
  ('f22@cit.demo', 'Dr. Harish Dash', 'faculty', @active_hash, 'Dr. Harish Dash (F22)', 'F22', 'PH', 1),
  ('f23@cit.demo', 'Dr. Farah Khan', 'faculty', @active_hash, 'Dr. Farah Khan (F23)', 'F23', 'PH', 1),
  ('f24@cit.demo', 'Dr. Gaurav Jena', 'faculty', @active_hash, 'Dr. Gaurav Jena (F24)', 'F24', 'PH', 1),
  ('f25@cit.demo', 'Dr. Tanvi Desai', 'faculty', @active_hash, 'Dr. Tanvi Desai (F25)', 'F25', 'ME', 1),
  ('f26@cit.demo', 'Dr. Abhishek Rout', 'faculty', @active_hash, 'Dr. Abhishek Rout (F26)', 'F26', 'ME', 1),
  ('f27@cit.demo', 'Dr. Sunita Bose', 'faculty', @active_hash, 'Dr. Sunita Bose (F27)', 'F27', 'ME', 1),
  ('f28@cit.demo', 'Dr. Nikhil Swain', 'faculty', @active_hash, 'Dr. Nikhil Swain (F28)', 'F28', 'ME', 1),
  ('f29@cit.demo', 'Dr. Priya Raman', 'faculty', @active_hash, 'Dr. Priya Raman (F29)', 'F29', 'ME', 1),
  ('f30@cit.demo', 'Dr. Debashish Sethi', 'faculty', @active_hash, 'Dr. Debashish Sethi (F30)', 'F30', 'ME', 1),
  ('f31@cit.demo', 'Dr. Anjali Verma', 'faculty', @active_hash, 'Dr. Anjali Verma (F31)', 'F31', 'CE', 1),
  ('f32@cit.demo', 'Dr. Sameer Khuntia', 'faculty', @active_hash, 'Dr. Sameer Khuntia (F32)', 'F32', 'CE', 1),
  ('f33@cit.demo', 'Dr. Rekha Pillai', 'faculty', @active_hash, 'Dr. Rekha Pillai (F33)', 'F33', 'CE', 1),
  ('f34@cit.demo', 'Dr. Varun Acharya', 'faculty', @active_hash, 'Dr. Varun Acharya (F34)', 'F34', 'CE', 1),
  ('f35@cit.demo', 'Dr. Bhavna Saxena', 'faculty', @active_hash, 'Dr. Bhavna Saxena (F35)', 'F35', 'CE', 1),
  ('f36@cit.demo', 'Dr. Ashok Mahapatra', 'faculty', @active_hash, 'Dr. Ashok Mahapatra (F36)', 'F36', 'HSS', 1),
  ('f37@cit.demo', 'Prof. Shreya Kapoor (Dean)', 'faculty', @active_hash, 'Office of the Dean of Academics', 'F37', 'ADMIN', 1),
  ('f38@cit.demo', 'Dr. Imran Qureshi', 'faculty', @active_hash, 'Dr. Imran Qureshi (F38)', 'F38', 'HSS', 1),
  ('f39@cit.demo', 'Dr. Radhika Hegde', 'faculty', @active_hash, 'Dr. Radhika Hegde (F39)', 'F39', 'HSS', 1),
  ('f40@cit.demo', 'Dr. Om Prakash Sahu', 'faculty', @active_hash, 'Dr. Om Prakash Sahu (F40)', 'F40', 'HSS', 1)
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  role = VALUES(role),
  requester_name = VALUES(requester_name),
  faculty_id = VALUES(faculty_id),
  department = VALUES(department),
  password_hash = IF(@existing_hash IS NOT NULL, @existing_hash, password_hash),
  is_active = 1;
