<?php
// api.php
// CENTRALIZED Backend logic for Pure & Simple Bible Interactive Studies

// --- HOSTGATOR CACHE BUSTERS ---
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control, Pragma');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- 1. GLOBAL CONFIGURATION ---
$host = 'localhost';
$db_name = 'psbstudi_psbstudies';
$username = 'psbstudi_admin';
$password = 'Ephesians1:7!!';

$admin_panel_password = 'Micah6:8!';

// --- 2. EMAIL DELIVERABILITY CONFIGURATION ---
$default_admin_email = 'connect@psbstudies.com';
$use_smtp = false;
$smtp_host = 'mail.psbstudies.com';
$smtp_user = 'no-reply@psbstudies.com';
$smtp_pass = 'YourEmailPasswordHere';
$smtp_port = 465;
// ----------------------------------------------

function haversineGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 3959) {
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);

    // FIX: Delta variables defined to prevent PHP math crash
    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
        cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

function send_app_email($to, $subject, $html_message, $reply_to = null) {
    global $use_smtp, $smtp_host, $smtp_user, $smtp_pass, $smtp_port;
    $from_email = 'no-reply@psbstudies.com';
    $from_name = 'Pure & Simple Bible';

    if ($use_smtp) {
        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $smtp_port;

            $mail->setFrom($from_email, $from_name);
            $mail->addAddress($to);
            if ($reply_to) {
                $mail->addReplyTo($reply_to);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html_message;
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    } else {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: {$from_name} <{$from_email}>\r\n";
        if ($reply_to) {
            $headers .= "Reply-To: {$reply_to}\r\n";
        }
        return @mail($to, $subject, $html_message, $headers, "-f{$from_email}");
    }
}

try {
    // Uses utf8mb4 to guarantee special characters save correctly
    $conn = new PDO("mysql:host={$host};dbname={$db_name};charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database connection failed: " . $e->getMessage()]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));
if (!$data || !isset($data->action)) {
    echo json_encode(["success" => false, "message" => "Invalid request."]);
    exit();
}

switch ($data->action) {
    /* --- STUDENT AUTH / PROGRESS ROUTES --- */
    case 'authenticate':
        $email = trim($data->email);
        $pass = $data->password;
        $phone = isset($data->phone) ? trim($data->phone) : null;
        $country = isset($data->country) ? trim($data->country) : 'United States';
        $zipcode = isset($data->zipcode) ? trim($data->zipcode) : null;

        if (empty($email) || empty($pass)) {
            echo json_encode(["success" => false, "message" => "Email and password are required."]);
            break;
        }

        try {
            $stmt = $conn->prepare("SELECT id, password_hash FROM students WHERE email = ?");
            $stmt->execute([$email]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($student) {
                if (password_verify($pass, $student['password_hash'])) {
                    echo json_encode(["success" => true, "student_id" => $student['id']]);
                } else {
                    echo json_encode(["success" => false, "message" => "Incorrect password."]);
                }
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO students (email, phone, country, zipcode, password_hash) VALUES (?, ?, ?, ?, ?)");

                if ($stmt->execute([$email, $phone, $country, $zipcode, $hash])) {
                    $subject = "New Student Registered: " . $email;
                    $message = "
                    <html>
                    <head><style>
                        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                        h2 { color: #0a506e; border-bottom: 2px solid #0a506e; padding-bottom: 5px;}
                    </style></head>
                    <body>
                        <h2>New Student Registered!</h2>
                        <p>A new student has just created an account for the Pure & Simple Bible Interactive Studies.</p>
                        <p><strong>Email:</strong> {$email}</p>
                        <p><strong>Phone:</strong> " . ($phone ?: "N/A") . "</p>
                        <p><strong>Location:</strong> " . ($country ?: "N/A") . " - Zip: " . ($zipcode ?: "N/A") . "</p>
                    </body>
                    </html>
                    ";
                    send_app_email($default_admin_email, $subject, $message, $email);
                    echo json_encode(["success" => true, "student_id" => $conn->lastInsertId()]);
                } else {
                    echo json_encode(["success" => false, "message" => "Registration failed."]);
                }
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'save_progress':
        $student_id = $data->student_id;
        $course_id = isset($data->course_id) ? $data->course_id : 'redemption';
        $lesson_id = $data->lesson_id;
        $journal = json_encode($data->journal);
        $quiz = json_encode(isset($data->quiz) ? $data->quiz : []);
        $is_completed = $data->is_completed ? 1 : 0;

        try {
            $stmt = $conn->prepare("
                INSERT INTO student_progress (student_id, course_id, lesson_id, is_completed, journal_data, quiz_data) 
                VALUES (?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                is_completed = VALUES(is_completed), journal_data = VALUES(journal_data), quiz_data = VALUES(quiz_data)
            ");

            if ($stmt->execute([$student_id, $course_id, $lesson_id, $is_completed, $journal, $quiz])) {
                echo json_encode(["success" => true]);
            } else {
                echo json_encode(["success" => false, "message" => "Save failed."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'load_progress':
        $student_id = $data->student_id;
        $course_id = isset($data->course_id) ? $data->course_id : 'redemption';
        $lesson_id = $data->lesson_id;

        try {
            $stmt = $conn->prepare("SELECT is_completed, journal_data, quiz_data FROM student_progress WHERE student_id = ? AND course_id = ? AND lesson_id = ?");
            $stmt->execute([$student_id, $course_id, $lesson_id]);
            $progress = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($progress) {
                echo json_encode([
                    "success" => true,
                    "is_completed" => (bool) $progress['is_completed'],
                    "journal" => json_decode($progress['journal_data']),
                    "quiz" => json_decode($progress['quiz_data'])
                ]);
            } else {
                echo json_encode(["success" => false, "message" => "No progress found."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'load_all_progress':
        $student_id = $data->student_id;
        $course_id = isset($data->course_id) ? $data->course_id : 'redemption';

        try {
            $stmt = $conn->prepare("SELECT lesson_id FROM student_progress WHERE student_id = ? AND course_id = ? AND is_completed = 1");
            $stmt->execute([$student_id, $course_id]);
            $completed_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(["success" => true, "completed_lessons" => $completed_ids]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'submit_to_admin':
        $student_id = $data->student_id;
        $course_id = isset($data->course_id) ? $data->course_id : 'redemption';
        $course_title = isset($data->course_title) ? $data->course_title : 'The Redemption Series';
        $lesson_id = $data->lesson_id;

        $to = $default_admin_email;
        $assigned_preacher_name = "Default Instructor";
        $distance_note = "Routing Failed: Sent to default email.";

        try {
            $stmt = $conn->prepare("SELECT email, phone, country, zipcode, assigned_instructor FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student_rec = $stmt->fetch(PDO::FETCH_ASSOC);

            $student_country = !empty($student_rec['country']) ? $student_rec['country'] : (isset($data->country) ? $data->country : 'United States');
            $student_zipcode = !empty($student_rec['zipcode']) ? $student_rec['zipcode'] : (isset($data->zipcode) ? $data->zipcode : null);
            $student_phone = !empty($student_rec['phone']) ? $student_rec['phone'] : (isset($data->phone) ? $data->phone : null);
            $student_email = !empty($student_rec['email']) ? $student_rec['email'] : $data->email;

            if (!empty($student_rec['assigned_instructor'])) {
                $to = $student_rec['assigned_instructor'];
                $stmt = $conn->prepare("SELECT name FROM instructors WHERE email = ?");
                $stmt->execute([$to]);
                $inst = $stmt->fetch(PDO::FETCH_ASSOC);
                $assigned_preacher_name = $inst ? $inst['name'] : "Assigned Instructor";
                $distance_note = "Routed to permanently assigned instructor.";
            } else {
                $student_lat = null;
                $student_lon = null;

                if (!empty($student_zipcode) && !empty($student_country)) {
                    $query = urlencode(trim($student_zipcode) . ", " . trim($student_country));
                    $url = "https://nominatim.openstreetmap.org/search?q={$query}&format=json&limit=1";
                    
                    $response = false;
                    if (function_exists('curl_init')) {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'PureAndSimpleBible/1.0 (connect@psbstudies.com)');
                        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        $response = curl_exec($ch);
                        curl_close($ch);
                    }
                    
                    if (!$response) {
                        $options = ['http' => ['header' => "User-Agent: PureAndSimpleBible/1.0\r\n", 'timeout' => 5]];
                        $context = stream_context_create($options);
                        $response = @file_get_contents($url, false, $context);
                    }

                    if ($response) {
                        $geo_data = json_decode($response, true);
                        if (!empty($geo_data) && isset($geo_data[0]['lat']) && isset($geo_data[0]['lon'])) {
                            $student_lat = (float) $geo_data[0]['lat'];
                            $student_lon = (float) $geo_data[0]['lon'];
                        }
                    }
                }

                if ($student_lat !== null && $student_lon !== null) {
                    try {
                        $stmt = $conn->query("SELECT * FROM instructors");
                        $preachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $closest_distance = INF;
                        $closest_preacher = null;

                        foreach ($preachers as $preacher) {
                            $p_lat = isset($preacher['lat']) ? (float)$preacher['lat'] : 0;
                            $p_lon = isset($preacher['longitude']) ? (float)$preacher['longitude'] : (isset($preacher['long']) ? (float)$preacher['long'] : 0);
                            
                            if ($p_lat != 0 && $p_lon != 0) {
                                $dist = haversineGreatCircleDistance($student_lat, $student_lon, $p_lat, $p_lon);
                                if ($dist < $closest_distance) {
                                    $closest_distance = $dist;
                                    $closest_preacher = $preacher;
                                }
                            }
                        }

                        if ($closest_preacher && !empty($closest_preacher['email'])) {
                            $to = $closest_preacher['email'];
                            $assigned_preacher_name = $closest_preacher['name'] ?? "Instructor";
                            $distance_note = "Routed to closest instructor (" . round($closest_distance, 1) . " miles away).";

                            $stmt = $conn->prepare("UPDATE students SET assigned_instructor = ? WHERE id = ?");
                            $stmt->execute([$to, $student_id]);
                        }
                    } catch(PDOException $e) {
                        // Fail silently and use default if instructors table has missing columns
                    }
                }
            }

            $journal_payload = isset($data->journal) ? json_encode($data->journal) : json_encode(new stdClass());
            $quiz_payload = isset($data->answers) ? json_encode($data->answers) : json_encode(new stdClass());

            $stmt_comp = $conn->prepare("
                INSERT INTO student_progress (student_id, course_id, lesson_id, is_completed, journal_data, quiz_data) 
                VALUES (?, ?, ?, 1, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                is_completed = 1, 
                journal_data = IF(VALUES(journal_data) != '{}' AND VALUES(journal_data) != '[]', VALUES(journal_data), journal_data),
                quiz_data = IF(VALUES(quiz_data) != '{}' AND VALUES(quiz_data) != '[]', VALUES(quiz_data), quiz_data)
            ");
            $stmt_comp->execute([$student_id, $course_id, $lesson_id, $journal_payload, $quiz_payload]);

            $stmt_prog = $conn->prepare("SELECT journal_data, quiz_data FROM student_progress WHERE student_id = ? AND course_id = ? AND lesson_id = ?");
            $stmt_prog->execute([$student_id, $course_id, $lesson_id]);
            $prog_rec = $stmt_prog->fetch(PDO::FETCH_ASSOC);

            $db_quiz_answers = [];
            $db_journal_answers = [];

            if ($prog_rec) {
                if (!empty($prog_rec['quiz_data'])) {
                    $decoded_quiz = json_decode($prog_rec['quiz_data'], true);
                    if (is_string($decoded_quiz)) $decoded_quiz = json_decode($decoded_quiz, true);
                    $db_quiz_answers = is_array($decoded_quiz) ? $decoded_quiz : [];
                }
                if (!empty($prog_rec['journal_data'])) {
                    $decoded_journal = json_decode($prog_rec['journal_data'], true);
                    if (is_string($decoded_journal)) $decoded_journal = json_decode($decoded_journal, true);
                    $db_journal_answers = is_array($decoded_journal) ? $decoded_journal : [];
                }
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error during submission: " . $e->getMessage()]);
            break;
        }

        $subject = "New Lesson Completed: {$course_title} - Lesson {$lesson_id} by {$data->name}";

        $message = "
        <html>
        <head><style>
            body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
            h2 { color: #0a506e; border-bottom: 2px solid #0a506e; padding-bottom: 5px; margin-top: 30px;}
            .section { background: #f8fafc; padding: 15px; margin-bottom: 20px; border-left: 4px solid #8cbea0;}
            .q { font-weight: bold; color: #555; }
            .a { color: #222; margin-bottom: 15px;}
            .meta { font-size: 12px; color: #888; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px;}
            .course-badge { display: inline-block; background: #d2691e; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; text-transform: uppercase;}
            .correct { color: #16a34a; font-weight: bold; }
            .incorrect { color: #dc2626; font-weight: bold; }
        </style></head>
        <body>
            <span class='course-badge'>{$course_title}</span>
            <h2 style='margin-top:10px;'>Student Details</h2>
            <p><strong>Name:</strong> {$data->name}</p>
            <p><strong>Email:</strong> {$student_email}</p>
            <p><strong>Phone:</strong> " . ($student_phone ?: "N/A") . "</p>
            <p><strong>Location:</strong> " . ($student_country ?: "N/A") . " - Zip: " . ($student_zipcode ?: "N/A") . "</p>
            <p><strong>Message:</strong><br/>" . nl2br($data->message ?: "None") . "</p>
        ";

        $quiz_data = isset($data->quiz) ? $data->quiz : null;
        $student_answers = !empty($db_quiz_answers) ? $db_quiz_answers : (isset($data->answers) ? (array)$data->answers : []);
        
        $total_questions = 0;
        $correct_answers = 0;
        $quiz_html = "<div class='section'>";

        if ($quiz_data) {
            if (isset($quiz_data->true_false) && is_array($quiz_data->true_false)) {
                $quiz_html .= "<h3 style='margin-top:0; color:#0a506e;'>True / False</h3>";
                foreach ($quiz_data->true_false as $q) {
                    $total_questions++;
                    $ans = isset($student_answers[$q->id]) ? $student_answers[$q->id] : '';
                    
                    $expected_bool = filter_var($q->answer, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $expected_str = $expected_bool !== null ? ($expected_bool ? "True" : "False") : (string)$q->answer;
                    
                    $ans_bool = filter_var($ans, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($ans_bool === null) {
                        $ans_str = (string)$ans;
                    } else {
                        $ans_str = $ans_bool ? "True" : "False";
                    }
                    
                    $is_correct = (strtolower(trim($ans_str)) === strtolower(trim($expected_str)));
                    if ($is_correct) $correct_answers++;
                    
                    $status_class = $is_correct ? 'correct' : 'incorrect';
                    $status_text = $is_correct ? '✓ Correct' : '✗ Incorrect (Expected: ' . $expected_str . ')';
                    $display_ans = $ans_str === '' ? '<i>No answer</i>' : htmlspecialchars($ans_str);
                    
                    $quiz_html .= "<div>";
                    $quiz_html .= "<div class='q'>" . htmlspecialchars($q->question) . "</div>";
                    $quiz_html .= "<div class='a'>Student Answer: {$display_ans} <span class='{$status_class}'>[{$status_text}]</span></div>";
                    $quiz_html .= "</div>";
                }
            }
            
            if (isset($quiz_data->fill_blank) && is_array($quiz_data->fill_blank)) {
                $quiz_html .= "<h3 style='margin-top:15px; color:#0a506e;'>Search the Scriptures</h3>";
                foreach ($quiz_data->fill_blank as $q) {
                    $total_questions++;
                    $ans = isset($student_answers[$q->id]) ? $student_answers[$q->id] : '';
                    
                    $expected_str = (string)$q->answer;
                    $ans_str = (string)$ans;
                    
                    $is_correct = (strtolower(trim($ans_str)) === strtolower(trim($expected_str)));
                    if ($is_correct) $correct_answers++;
                    
                    $status_class = $is_correct ? 'correct' : 'incorrect';
                    $status_text = $is_correct ? '✓ Correct' : '✗ Incorrect (Expected: ' . htmlspecialchars($expected_str) . ')';
                    $display_ans = $ans_str === '' ? '<i>No answer</i>' : htmlspecialchars($ans_str);
                    
                    $quiz_html .= "<div>";
                    $quiz_html .= "<div class='q'>" . htmlspecialchars($q->verse) . "</div>";
                    $quiz_html .= "<div class='a'>Student Answer: {$display_ans} <span class='{$status_class}'>[{$status_text}]</span></div>";
                    $quiz_html .= "</div>";
                }
            }
        }
        $quiz_html .= "</div>";

        if ($total_questions > 0) {
            $score = round(($correct_answers / $total_questions) * 100);
            $message .= "<h2>Quiz Results ({$score}%)</h2>" . $quiz_html;
        } else {
            $message .= "<h2>Quiz Results</h2><div class='section'><p>No quiz data available.</p></div>";
        }

        $message .= "<h2>Deeper Connections</h2>\n<div class='section'>\n";

        if (isset($data->deeper_connections) && is_array($data->deeper_connections)) {
            $clean_journal = [];

            foreach ($db_journal_answers as $k => $v) {
                $clean_key = trim((string)$k);
                if (is_numeric($clean_key)) {
                    $clean_journal[(int)$clean_key] = $v;
                }
            }
            
            if (empty($clean_journal) && isset($data->journal)) {
                $j = is_string($data->journal) ? json_decode($data->journal, true) : json_decode(json_encode($data->journal), true);
                if (is_array($j)) {
                    foreach ($j as $k => $v) {
                        $clean_key = trim((string)$k);
                        if (is_numeric($clean_key) && !isset($clean_journal[(int)$clean_key])) {
                            $clean_journal[(int)$clean_key] = $v;
                        }
                    }
                }
            }

            if (empty($clean_journal) && isset($data->answers)) {
                $a = is_string($data->answers) ? json_decode($data->answers, true) : json_decode(json_encode($data->answers), true);
                if (is_array($a)) {
                    foreach ($a as $k => $v) {
                        $clean_key = trim((string)$k);
                        if (is_numeric($clean_key) && !isset($clean_journal[(int)$clean_key])) {
                            $clean_journal[(int)$clean_key] = $v;
                        }
                    }
                }
            }
            
            foreach ($data->deeper_connections as $index => $prompt) {
                $answer = "No response provided.";
                
                if (isset($clean_journal[$index]) && trim((string)$clean_journal[$index]) !== '') {
                    $answer = $clean_journal[$index];
                } elseif (isset($clean_journal[(string)$index]) && trim((string)$clean_journal[(string)$index]) !== '') {
                    $answer = $clean_journal[(string)$index];
                }
                
                $message .= "<div class='q'>" . ((int)$index + 1) . ". " . htmlspecialchars((string)$prompt) . "</div>";
                $message .= "<div class='a'>" . nl2br(htmlspecialchars((string)$answer)) . "</div>";
            }
        } else {
            $message .= "<p>No deeper connections data found.</p>";
        }

        $message .= "</div>
        <div class='meta'>
            System Routing Note: {$distance_note}<br/>
            Assigned to: {$assigned_preacher_name}
        </div>
        </body></html>";

        $mailSent = send_app_email($to, $subject, $message, $student_email);

        if ($mailSent) {
            echo json_encode(["success" => true, "message" => "Email sent successfully."]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to send email."]);
        }
        break;

    /* --- STUDENT PROFILE ROUTES --- */
    case 'get_student_profile':
        $student_id = $data->student_id;
        try {
            $stmt = $conn->prepare("SELECT email, phone, country, zipcode FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($student) {
                echo json_encode(["success" => true, "profile" => $student]);
            } else {
                echo json_encode(["success" => false, "message" => "Student not found."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'update_student_profile':
        $student_id = $data->student_id;
        $email = trim($data->email);
        $phone = trim($data->phone);
        $country = trim($data->country);
        $zipcode = trim($data->zipcode);
        $newPassword = isset($data->newPassword) ? $data->newPassword : '';

        try {
            $stmt = $conn->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
            $stmt->execute([$email, $student_id]);
            if ($stmt->fetch()) {
                echo json_encode(["success" => false, "message" => "Email is already in use by another account."]);
                break;
            }

            if (!empty($newPassword)) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE students SET email = ?, phone = ?, country = ?, zipcode = ?, password_hash = ? WHERE id = ?");
                $success = $stmt->execute([$email, $phone, $country, $zipcode, $hash, $student_id]);
            } else {
                $stmt = $conn->prepare("UPDATE students SET email = ?, phone = ?, country = ?, zipcode = ? WHERE id = ?");
                $success = $stmt->execute([$email, $phone, $country, $zipcode, $student_id]);
            }

            if ($success) {
                echo json_encode(["success" => true]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to update profile."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'reset_password':
        $email = trim($data->email);
        if (empty($email)) {
            echo json_encode(["success" => false, "message" => "Email is required."]);
            break;
        }
        try {
            $stmt = $conn->prepare("SELECT id FROM students WHERE email = ?");
            $stmt->execute([$email]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                echo json_encode(["success" => false, "message" => "No account found with that email address."]);
                break;
            }

            $tempPassword = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*"), 0, 10);
            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("UPDATE students SET password_hash = ? WHERE id = ?");
            if ($stmt->execute([$hash, $student['id']])) {
                $subject = "Password Reset - Pure & Simple Bible";
                $message = "
                <html>
                <head><style>
                    body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                    h2 { color: #0a506e; border-bottom: 2px solid #0a506e; padding-bottom: 5px;}
                </style></head>
                <body>
                    <h2>Password Reset Request</h2>
                    <p>Your password for the Pure & Simple Bible Interactive Studies has been reset.</p>
                    <p>Your temporary password is: <strong>{$tempPassword}</strong></p>
                    <p>Please log in and update your password in the 'My Profile' section.</p>
                </body>
                </html>
                ";
                send_app_email($email, $subject, $message);
                echo json_encode(["success" => true]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to reset password."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    /* --- MASTER ADMIN ROUTES --- */
    case 'admin_login':
        if (isset($data->admin_password) && $data->admin_password === $admin_panel_password) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "message" => "Invalid admin password."]);
        }
        break;

    case 'admin_get_students':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        try {
            $stmt = $conn->query("
                SELECT s.id, s.email, s.phone, s.country, s.zipcode, s.created_at, s.assigned_instructor,
                       (SELECT COUNT(id) FROM student_progress WHERE student_id = s.id AND is_completed = 1) as completed_count
                FROM students s
                ORDER BY s.created_at DESC
            ");
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["success" => true, "students" => $students]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_get_student_details':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $student_id = $data->student_id;

        try {
            $stmt = $conn->prepare("SELECT id, email, phone, country, zipcode, created_at, assigned_instructor FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("SELECT course_id, lesson_id, is_completed, journal_data, quiz_data, last_updated FROM student_progress WHERE student_id = ? ORDER BY course_id ASC, lesson_id ASC");
            $stmt->execute([$student_id]);
            $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "student" => $student, "progress" => $progress]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_add_student':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $email = trim($data->email);
        $new_password = $data->new_password;
        $phone = isset($data->phone) ? trim($data->phone) : null;
        $country = isset($data->country) ? trim($data->country) : 'United States';
        $zipcode = isset($data->zipcode) ? trim($data->zipcode) : null;

        if (empty($email) || empty($new_password)) {
             echo json_encode(["success" => false, "message" => "Email and password are required."]);
             break;
        }
        try {
            $stmt = $conn->prepare("SELECT id FROM students WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                 echo json_encode(["success" => false, "message" => "A student with this email already exists."]);
                 break;
            }
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO students (email, phone, country, zipcode, password_hash) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$email, $phone, $country, $zipcode, $password_hash])) {
                 echo json_encode(["success" => true]);
            } else {
                 echo json_encode(["success" => false, "message" => "Database error adding student."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_delete_student':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $student_id = $data->student_id;
        try {
            $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
            if ($stmt->execute([$student_id])) {
                 echo json_encode(["success" => true]);
            } else {
                 echo json_encode(["success" => false, "message" => "Database error deleting student."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_update_student_phone':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $student_id = $data->student_id;
        $new_phone = isset($data->new_phone) ? trim($data->new_phone) : null;
        try {
            $stmt = $conn->prepare("UPDATE students SET phone = ? WHERE id = ?");
            if ($stmt->execute([$new_phone, $student_id])) {
                 echo json_encode(["success" => true]);
            } else {
                 echo json_encode(["success" => false, "message" => "Database error updating phone."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_update_student_location':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $student_id = $data->student_id;
        $new_country = isset($data->new_country) ? trim($data->new_country) : 'United States';
        $new_zipcode = isset($data->new_zipcode) ? trim($data->new_zipcode) : null;
        try {
            $stmt = $conn->prepare("UPDATE students SET country = ?, zipcode = ? WHERE id = ?");
            if ($stmt->execute([$new_country, $new_zipcode, $student_id])) {
                 echo json_encode(["success" => true]);
            } else {
                 echo json_encode(["success" => false, "message" => "Database error updating location."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_update_student_password':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $student_id = $data->student_id;
        $new_password = $data->new_password;
        if (empty($new_password)) {
             echo json_encode(["success" => false, "message" => "Password cannot be empty."]);
             break;
        }
        try {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE students SET password_hash = ? WHERE id = ?");
            if ($stmt->execute([$password_hash, $student_id])) {
                 echo json_encode(["success" => true]);
            } else {
                 echo json_encode(["success" => false, "message" => "Database error updating password."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_assign_student':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $student_id = $data->student_id;
        $instructor_email = empty($data->instructor_email) ? null : trim($data->instructor_email);
        try {
            $stmt = $conn->prepare("UPDATE students SET assigned_instructor = ? WHERE id = ?");
            if ($stmt->execute([$instructor_email, $student_id])) {
                echo json_encode(["success" => true]);
            } else {
                echo json_encode(["success" => false, "message" => "Database error assigning instructor."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    /* --- DB INSTRUCTOR MANAGEMENT ROUTES --- */
    case 'admin_get_instructors':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        try {
            // Safely fetch all columns. 
            $stmt = $conn->query("SELECT * FROM instructors ORDER BY name ASC");
            $raw_instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $instructors = [];
            foreach ($raw_instructors as $inst) {
                // Prevents SQL crash by dynamically looking for whichever longitude column name exists
                $inst['long'] = $inst['longitude'] ?? $inst['long'] ?? $inst['lng'] ?? '';
                $instructors[] = $inst;
            }
            
            echo json_encode(["success" => true, "instructors" => $instructors]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_add_instructor':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        if (empty($data->email) || empty($data->password)) {
             echo json_encode(["success" => false, "message" => "Email and password are required."]);
             break;
        }
        try {
            $stmt = $conn->prepare("SELECT id FROM instructors WHERE email = ?");
            $stmt->execute([$data->email]);
            if ($stmt->fetch()) {
                 echo json_encode(["success" => false, "message" => "An instructor with this email already exists."]);
                 break;
            }

            $hash = password_hash($data->password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO instructors (name, email, password_hash, city, state, lat, longitude) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt->execute([$data->name, $data->email, $hash, $data->city, $data->state, $data->lat, $data->long])) {
                 echo json_encode(["success" => true]);
            } else {
                 echo json_encode(["success" => false, "message" => "Database error adding instructor."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_update_instructor':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        try {
            if (!empty($data->password)) {
                $hash = password_hash($data->password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE instructors SET name=?, email=?, password_hash=?, city=?, state=?, lat=?, longitude=? WHERE id=?");
                $success = $stmt->execute([$data->name, $data->email, $hash, $data->city, $data->state, $data->lat, $data->long, $data->instructor_id]);
            } else {
                $stmt = $conn->prepare("UPDATE instructors SET name=?, email=?, city=?, state=?, lat=?, longitude=? WHERE id=?");
                $success = $stmt->execute([$data->name, $data->email, $data->city, $data->state, $data->lat, $data->long, $data->instructor_id]);
            }

            if ($success) {
                echo json_encode(["success" => true]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to update instructor."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'admin_delete_instructor':
        if (!isset($data->admin_password) || $data->admin_password !== $admin_panel_password) {
            echo json_encode(["success" => false, "message" => "Unauthorized"]);
            break;
        }
        $instructor_id = $data->instructor_id;
        try {
            $stmt = $conn->prepare("DELETE FROM instructors WHERE id = ?");
            if ($stmt->execute([$instructor_id])) {
                 echo json_encode(["success" => true]);
            } else {
                 echo json_encode(["success" => false, "message" => "Database error deleting instructor."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    /* --- INSTRUCTOR PORTAL ROUTES --- */
    case 'instructor_login':
        $email = trim($data->email);
        $pass = $data->password;
        
        try {
            $stmt = $conn->prepare("SELECT name, password_hash FROM instructors WHERE email = ?");
            $stmt->execute([$email]);
            $inst = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inst && password_verify($pass, $inst['password_hash'])) {
                echo json_encode(["success" => true, "name" => $inst['name'], "email" => $email]);
            } else {
                echo json_encode(["success" => false, "message" => "Invalid email or password."]);
            }
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'instructor_get_students':
        $email = trim($data->instructor_email);
        
        try {
            $stmt = $conn->prepare("
                SELECT s.id, s.email, s.phone, s.country, s.zipcode, s.created_at, 
                       (SELECT COUNT(id) FROM student_progress WHERE student_id = s.id AND is_completed = 1) as completed_count
                FROM students s
                WHERE s.assigned_instructor = ?
                ORDER BY s.created_at DESC
            ");
            $stmt->execute([$email]);
            echo json_encode(["success" => true, "students" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'instructor_get_student_details':
        $email = trim($data->instructor_email);
        $student_id = $data->student_id;
        
        try {
            $stmt = $conn->prepare("SELECT id, email, phone, country, zipcode, created_at FROM students WHERE id = ? AND assigned_instructor = ?");
            $stmt->execute([$student_id, $email]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) { 
                echo json_encode(["success" => false, "message" => "Student not found or not assigned to you."]); 
                break; 
            }

            $stmt = $conn->prepare("SELECT course_id, lesson_id, is_completed, journal_data, quiz_data, last_updated FROM student_progress WHERE student_id = ? ORDER BY course_id ASC, lesson_id ASC");
            $stmt->execute([$student_id]);
            $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["success" => true, "student" => $student, "progress" => $progress]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "message" => "SQL Error: " . $e->getMessage()]);
        }
        break;

    case 'instructor_contact_admin':
        $instructor_email = trim($data->instructor_email);
        $instructor_name = trim($data->instructor_name);
        $subject_line = trim($data->subject);
        $msg_body = trim($data->message);
        
        $to = $default_admin_email; // Sends to your connect@psbstudies.com
        
        $subject = "Instructor Portal: " . $subject_line;
        
        $message = "
        <html>
        <head><style>
            body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
            h2 { color: #0a506e; border-bottom: 2px solid #0a506e; padding-bottom: 5px;}
            .section { background: #f8fafc; padding: 15px; border-left: 4px solid #8cbea0;}
        </style></head>
        <body>
            <h2>Message from Instructor Portal</h2>
            <p><strong>From:</strong> {$instructor_name} ({$instructor_email})</p>
            <p><strong>Subject:</strong> {$subject_line}</p>
            <div class='section'>
                " . nl2br(htmlspecialchars($msg_body)) . "
            </div>
        </body></html>
        ";
        
        $mailSent = send_app_email($to, $subject, $message, $instructor_email);
        
        if ($mailSent) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to send email."]);
        }
        break;

    default:
        echo json_encode(["success" => false, "message" => "Unknown action."]);
        break;
}
?>
