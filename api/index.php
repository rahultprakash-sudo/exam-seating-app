<?php
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/config.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function go($msg,$type = 'success', $examId = 0) {$url = 'index.php?msg=' . urlencode($msg) . '&type=' . urlencode($type);
    if ($examId > 0) $url .= '&exam_id=' . (int)$examId;
    header('Location: ' . $url);
    exit;
}

function isSeminarHall(string $roomName): bool {
    return stripos($roomName, 'seminar') !== false;
}

function getRoomMetrics(string $roomName, int$count): array {
    if (isSeminarHall($roomName)) {
        $chairs = max(1,$count);
        return [
            'is_seminar'     => true,
            'unit_label'     => 'Chairs',
            'seats_per_unit' => 1,
            'usable_benches' => $chairs,
            'capacity'       => $chairs,
        ];
    }
    $desks = max(1, $count);$cap = min(34, $desks * 2);$usableBenches = min($desks, (int)ceil($cap / 2));
    return [
        'is_seminar'     => false,
        'unit_label'     => 'Desks',
        'seats_per_unit' => 2,
        'usable_benches' => $usableBenches,
        'capacity'       => $cap,
    ];
}

/**
 * Parse a register number such as IGW23AIM001 or LIGW23AIM029 into structured parts:
 * - year: Admission year (e.g., 21, 22, 23, 25, 26)
 * - dept: Department code (e.g., AIM, ALM, BBA, BCA, CE, CS, EEE)
 * - is_lateral: 0 for regular (IGW), 1 for lateral entry (LIGW)
 * - roll: Numeric roll number
 * - cohort: Combined Year + Department key (e.g., "0023_AIM") so IGW and LIGW stay bound together
 */
function parseRegisterNo(string $regNo): array {
    $clean = strtoupper(trim($regNo));
    if (preg_match('/^(L?)([A-Z]+)(\d{2})([A-Z]+)(\d+)$/', $clean,$m)) {
        $year = (int)$m[3];
        $dept =$m[4];
        return [
            'matched'    => true,
            'is_lateral' => ($m[1] === 'L') ? 1 : 0,
            'college'    => $m[2], 'year'       =>$year,
            'dept'       => $dept,
            'roll'       => (int)$m[5],
            'cohort'     => sprintf('%04d_%s', $year,$dept),
            'raw'        => $clean,
        ];
    }
    return [
        'matched'    => false,
        'is_lateral' => 0,
        'college'    => '',
        'year'       => PHP_INT_MAX,
        'dept'       => $clean,
        'roll'       => PHP_INT_MAX,
        'cohort'     => '9999_' . $clean,
        'raw'        => $clean,
    ];
}

/**
 * Sort comparator:
 * 1. Year ascending (21, 22, 23, 25, 26...)
 * 2. Department ascending (AIM, BBA, BCA, CE, CS, EEE...)
 * 3. Regular (IGW = 0) first, followed IMMEDIATELY by Lateral Entry (LIGW = 1) of the SAME year & department
 * 4. Roll number ascending (1, 2, 3, ..., 29...)
 */
function compareRegisterNumbers(string $regA, string$regB): int {
    $a = parseRegisterNo($regA);
    $b = parseRegisterNo($regB);

    if ($a['matched'] &&$b['matched']) {
        if ($a['year'] !==$b['year']) {
            return $a['year'] <=>$b['year'];
        }
        $deptCmp = strcmp($a['dept'],$b['dept']);
        if ($deptCmp !== 0) {
            return $deptCmp;
        }
        if ($a['is_lateral'] !==$b['is_lateral']) {
            return $a['is_lateral'] <=>$b['is_lateral'];
        }
        if ($a['roll'] !==$b['roll']) {
            return $a['roll'] <=>$b['roll'];
        }
    }
    return strnatcasecmp($a['raw'],$b['raw']);
}

/**
 * Parse uploaded CSV or Excel (.xlsx) file containing Register Number and Student Name.
 * Automatically sorts students by Year -> Department -> Regular (IGW) then Lateral (LIGW) -> Roll Number.
 */
function parseStudentFile(array $file): array {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please upload a valid CSV or Excel (.xlsx) student list file.');
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $rows = [];

    if ($ext === 'csv' || $ext === 'txt') {
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) throw new RuntimeException('Unable to read uploaded CSV file.');
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }
    } elseif ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is not enabled. Enable php_zip in php.ini or upload a CSV file.');
        }
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            throw new RuntimeException('Could not open the uploaded .xlsx file.');
        }

        $sharedStrings = [];
        $ssXml =$zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $sx = simplexml_load_string($ssXml);
            if ($sx !== false) {
                foreach ($sx->si as$si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } else {
                        $text = '';
                        foreach ($si->r as$r) $text .= (string)$r->t;
                        $sharedStrings[] =$text;
                    }
                }
            }
        }

        $sheetXml =$zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            for ($i = 0; $i <$zip->numFiles; $i++) {$name = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {$sheetXml = $zip->getFromName($name);
                    break;
                }
            }
        }
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('No worksheet found inside the .xlsx file.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false || !isset($sheet->sheetData->row)) {
            throw new RuntimeException('Invalid worksheet format in .xlsx file.');
        }

        foreach ($sheet->sheetData->row as $row) {$rowVals = [];
            foreach ($row->c as $c) {$ref = (string)$c['r'];$colLetters = preg_replace('/[^A-Z]/', '', strtoupper($ref));$colIdx = 0;
                for ($k = 0; $k < strlen($colLetters);$k++) {
                    $colIdx =$colIdx * 26 + (ord($colLetters[$k]) - 64);
                }
                $colIdx = max(0,$colIdx - 1);

                $type = (string)$c['t'];$val = '';
                if ($type === 's') {
                    $idx = (int)$c->v;
                    $val = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)($c->is->t ?? '');
                } else {
                    $val = (string)($c->v ?? '');
                }
                $rowVals[$colIdx] = trim($val);
            }
            if (!empty($rowVals)) {
                ksort($rowVals);$maxCol = max(array_keys($rowVals));$dense = [];
                for ($i = 0; $i <=$maxCol; $i++)$dense[] = $rowVals[$i] ?? '';
                $rows[] =$dense;
            }
        }
    } else {
        throw new RuntimeException('Unsupported file format. Please upload a .csv or .xlsx file.');
    }

    if (empty($rows)) {
        throw new RuntimeException('The uploaded file is empty.');
    }

    $regCol = 0;
    $nameCol = 1;
    $startIdx = 0;

    $firstRowLower = array_map(fn($v) => strtolower(trim((string)$v)), $rows[0]);$foundHeader = false;
    foreach ($firstRowLower as $idx =>$cell) {
        if (preg_match('/^(reg|register|roll|usn|id|admission)/', $cell)) {
            $regCol =$idx;
            $foundHeader = true;
        } elseif (preg_match('/^(name|student)/', $cell)) {
            $nameCol =$idx;
            $foundHeader = true;
        }
    }
    if ($foundHeader) {$startIdx = 1;
    }

    $students = [];$seenRegs = [];
    for ($i =$startIdx; $i < count($rows); $i++) {$r = $rows[$i];
        $regNo = strtoupper(trim((string)($r[$regCol] ?? '')));$studentName = trim((string)($r[$nameCol] ?? ''));

        if ($regNo === '' &&$studentName === '') continue;
        if ($regNo === '' ||$studentName === '') {
            throw new RuntimeException("Row " . ($i + 1) . " is missing either Register Number or Student Name.");
        }
        if (isset($seenRegs[$regNo])) {
            throw new RuntimeException("Duplicate Register Number '$regNo' found in file at row " . ($i + 1) . ".");
        }
        $seenRegs[$regNo] = true;
        $students[] = [
            'register_no' => $regNo,
            'student_name' => $studentName
        ];
    }

    if (empty($students)) {
        throw new RuntimeException('No valid student rows found in the uploaded file.');
    }

    usort($students, fn($a,$b) => compareRegisterNumbers($a['register_no'],$b['register_no']));

    return $students;
}

if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_students.csv"');
    echo "register_no,student_name\n";
    echo "IGW23AIM001,Student One\n";
    echo "IGW23AIM002,Student Two\n";
    echo "IGW23AIM003,Student Three\n";
    echo "LIGW23AIM029,Lateral Student One\n";
    echo "IGW23EEE001,EEE Student One\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? '';

    if ($action === 'add_exam') {
        $date =$_POST['exam_date'] ?? '';
        $name = trim($_POST['exam_name'] ?? 'Examination');
        if (!$date) go('Please select an examination date.', 'error');
        $stmt =$pdo->prepare("INSERT INTO exams (exam_date, exam_name) VALUES (?, ?)");
        $stmt->execute([$date,$name ?: 'Examination']);
        $newId = (int)$pdo->lastInsertId();
        go('Examination created successfully.', 'success', $newId);
    }

    if ($action === 'add_subject') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $code = strtoupper(trim($_POST['subject_code'] ?? ''));
        if (!$examId || !$code) go('Enter a valid examination and subject code.', 'error',$examId);

        try {
            $students = parseStudentFile($_FILES['student_file'] ?? []);
        } catch (Throwable $e) {
            go($e->getMessage(), 'error',$examId);
        }

        $count = count($students);$pdo->beginTransaction();
        try {
            $stmt =$pdo->prepare("INSERT INTO subjects (exam_id, subject_code, student_count) VALUES (?, ?, ?)");
            $stmt->execute([$examId, $code,$count]);
            $subjectId = (int)$pdo->lastInsertId();

            $insStu =$pdo->prepare("INSERT INTO subject_students (subject_id, student_no, register_no, student_name) VALUES (?, ?, ?, ?)");
            foreach ($students as$idx => $stu) {$insStu->execute([$subjectId,$idx + 1, $stu['register_no'],$stu['student_name']]);
            }
            $pdo->commit();
        } catch (PDOException $e) {$pdo->rollBack();
            go('This subject code is already added for the selected examination, or the file data is invalid.', 'error', $examId);
        }
        go("Subject $code added successfully with$count students (IGW followed by LIGW per Year & Dept).", 'success', $examId);
    }

    if ($action === 'delete_subject') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $stmt =$pdo->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->execute([(int)$_POST['subject_id']]);
        go('Subject and its student list removed.', 'success', $examId);
    }

    if ($action === 'add_room') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $name = trim($_POST['room_name'] ?? '');
        $roomType =$_POST['room_type'] ?? 'classroom';
        $desks = (int)($_POST['desk_count'] ?? 0);
        if ($roomType === 'seminar' && stripos($name, 'seminar') === false) {$name = $name !== '' ? ($name . ' (Seminar Hall)') : 'Seminar Hall';
        }
        if (!$name ||$desks < 1) {
            $unitWord = isSeminarHall($name) ? 'chair' : 'desk';
            go("Enter a room name and a positive $unitWord count.", 'error', $examId);
        }
        try {
            $stmt =$pdo->prepare("INSERT INTO rooms (room_name, desk_count) VALUES (?, ?)");
            $stmt->execute([$name,$desks]);
        } catch (PDOException $e) {
            go('Room name already exists, or the data is invalid.', 'error', $examId);
        }
        $msgAdded = isSeminarHall($name)
            ? "Seminar Hall added with $desks individual chairs (1 student per chair)."
            : "Room added successfully ($desks desks, capacity " . min(34, $desks * 2) . ").";
        go($msgAdded, 'success',$examId);
    }

    if ($action === 'delete_room') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $stmt =$pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([(int)$_POST['room_id']]);
        go('Room removed.', 'success', $examId);
    }

    if ($action === 'generate') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $subjectsStmt =$pdo->prepare("SELECT id, subject_code, student_count FROM subjects WHERE exam_id = ? ORDER BY student_count DESC, subject_code ASC");
        $subjectsStmt->execute([$examId]);
        $subjects =$subjectsStmt->fetchAll();
        $rooms =$pdo->query("SELECT id, room_name, desk_count FROM rooms ORDER BY room_name ASC")->fetchAll();
        usort($rooms, fn($a,$b) => strnatcasecmp($a['room_name'],$b['room_name']));

        if (!$examId || !$subjects || !$rooms) go('Add at least one subject and one room before generating.', 'error',$examId);

        // Load student queues strictly sorted by:
        // Year -> Department -> Regular (IGW) then Lateral (LIGW) -> Roll Number
        $studentQueues = [];
        $stuStmt =$pdo->prepare("SELECT student_no, register_no, student_name FROM subject_students WHERE subject_id = ? ORDER BY student_no ASC");
        foreach ($subjects as$s) {
            $stuStmt->execute([$s['id']]);
            $list =$stuStmt->fetchAll();
            if (count($list) !== (int)$s['student_count']) {
                go("Student list count mismatch for {$s['subject_code']}. Re-upload the subject student file.", 'error', $examId);
            }
            usort($list, fn($a,$b) => compareRegisterNumbers($a['register_no'],$b['register_no']));
            foreach ($list as &$item) {
                $parsed = parseRegisterNo($item['register_no']);
                $item['cohort']     =$parsed['cohort'];
                $item['is_lateral'] =$parsed['is_lateral'];
            }
            unset($item);
            $studentQueues[$s['