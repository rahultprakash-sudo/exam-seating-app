<?php
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/config.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function go($msg, $type = 'success', $examId = 0) {
    $url = 'index.php?msg=' . urlencode($msg) . '&type=' . urlencode($type);
    if ($examId > 0) $url .= '&exam_id=' . (int)$examId;
    header('Location: ' . $url);
    exit;
}

function isSeminarHall(string $roomName): bool {
    return stripos($roomName, 'seminar') !== false;
}

function getRoomMetrics(string $roomName, int $count): array {
    if (isSeminarHall($roomName)) {
        // Seminar Hall: count is number of individual chairs (1 student per chair, no 34-seat cap)
        $chairs = max(1, $count);
        return [
            'is_seminar'     => true,
            'unit_label'     => 'Chairs',
            'seats_per_unit' => 1,
            'usable_benches' => $chairs,
            'capacity'       => $chairs,
        ];
    }
    // Standard Classroom: count is number of desks (2 seats per desk, capped at 34 seats / 17 desks)
    $desks = max(1, $count);
    $cap = min(34, $desks * 2);
    $usableBenches = min($desks, (int)ceil($cap / 2));
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
    if (preg_match('/^(L?)([A-Z]+)(\d{2})([A-Z]+)(\d+)$/', $clean, $m)) {
        $year = (int)$m[3];
        $dept = $m[4];
        return [
            'matched'    => true,
            'is_lateral' => ($m[1] === 'L') ? 1 : 0,
            'college'    => $m[2],
            'year'       => $year,
            'dept'       => $dept,
            'roll'       => (int)$m[5],
            'cohort'     => sprintf('%04d_%s', $year, $dept),
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
function compareRegisterNumbers(string $regA, string $regB): int {
    $a = parseRegisterNo($regA);
    $b = parseRegisterNo($regB);

    if ($a['matched'] && $b['matched']) {
        if ($a['year'] !== $b['year']) {
            return $a['year'] <=> $b['year'];
        }
        $deptCmp = strcmp($a['dept'], $b['dept']);
        if ($deptCmp !== 0) {
            return $deptCmp;
        }
        if ($a['is_lateral'] !== $b['is_lateral']) {
            return $a['is_lateral'] <=> $b['is_lateral'];
        }
        if ($a['roll'] !== $b['roll']) {
            return $a['roll'] <=> $b['roll'];
        }
    }
    return strnatcasecmp($a['raw'], $b['raw']);
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
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $sx = simplexml_load_string($ssXml);
            if ($sx !== false) {
                foreach ($sx->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } else {
                        $text = '';
                        foreach ($si->r as $r) $text .= (string)$r->t;
                        $sharedStrings[] = $text;
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetXml = $zip->getFromName($name);
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

        foreach ($sheet->sheetData->row as $row) {
            $rowVals = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $colLetters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
                $colIdx = 0;
                for ($k = 0; $k < strlen($colLetters); $k++) {
                    $colIdx = $colIdx * 26 + (ord($colLetters[$k]) - 64);
                }
                $colIdx = max(0, $colIdx - 1);

                $type = (string)$c['t'];
                $val = '';
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
                ksort($rowVals);
                $maxCol = max(array_keys($rowVals));
                $dense = [];
                for ($i = 0; $i <= $maxCol; $i++) $dense[] = $rowVals[$i] ?? '';
                $rows[] = $dense;
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

    $firstRowLower = array_map(fn($v) => strtolower(trim((string)$v)), $rows[0]);
    $foundHeader = false;
    foreach ($firstRowLower as $idx => $cell) {
        if (preg_match('/^(reg|register|roll|usn|id|admission)/', $cell)) {
            $regCol = $idx;
            $foundHeader = true;
        } elseif (preg_match('/^(name|student)/', $cell)) {
            $nameCol = $idx;
            $foundHeader = true;
        }
    }
    if ($foundHeader) {
        $startIdx = 1;
    }

    $students = [];
    $seenRegs = [];
    for ($i = $startIdx; $i < count($rows); $i++) {
        $r = $rows[$i];
        $regNo = strtoupper(trim((string)($r[$regCol] ?? '')));
        $studentName = trim((string)($r[$nameCol] ?? ''));

        if ($regNo === '' && $studentName === '') continue;
        if ($regNo === '' || $studentName === '') {
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

    // Sort strictly by Year -> Department -> Regular (IGW) then Lateral (LIGW) -> Roll Number
    usort($students, fn($a, $b) => compareRegisterNumbers($a['register_no'], $b['register_no']));

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
    $action = $_POST['action'] ?? '';

    if ($action === 'add_exam') {
        $date = $_POST['exam_date'] ?? '';
        $name = trim($_POST['exam_name'] ?? 'Examination');
        if (!$date) go('Please select an examination date.', 'error');
        $stmt = $pdo->prepare("INSERT INTO exams (exam_date, exam_name) VALUES (?, ?)");
        $stmt->execute([$date, $name ?: 'Examination']);
        $newId = (int)$pdo->lastInsertId();
        go('Examination created successfully.', 'success', $newId);
    }

    if ($action === 'add_subject') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $code = strtoupper(trim($_POST['subject_code'] ?? ''));
        if (!$examId || !$code) go('Enter a valid examination and subject code.', 'error', $examId);

        try {
            $students = parseStudentFile($_FILES['student_file'] ?? []);
        } catch (Throwable $e) {
            go($e->getMessage(), 'error', $examId);
        }

        $count = count($students);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (exam_id, subject_code, student_count) VALUES (?, ?, ?)");
            $stmt->execute([$examId, $code, $count]);
            $subjectId = (int)$pdo->lastInsertId();

            $insStu = $pdo->prepare("INSERT INTO subject_students (subject_id, student_no, register_no, student_name) VALUES (?, ?, ?, ?)");
            foreach ($students as $idx => $stu) {
                $insStu->execute([$subjectId, $idx + 1, $stu['register_no'], $stu['student_name']]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            go('This subject code is already added for the selected examination, or the file data is invalid.', 'error', $examId);
        }
        go("Subject $code added successfully with $count students (IGW followed by LIGW per Year & Dept).", 'success', $examId);
    }

    if ($action === 'delete_subject') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->execute([(int)$_POST['subject_id']]);
        go('Subject and its student list removed.', 'success', $examId);
    }

    if ($action === 'add_room') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $name = trim($_POST['room_name'] ?? '');
        $roomType = $_POST['room_type'] ?? 'classroom';
        $desks = (int)($_POST['desk_count'] ?? 0);
        if ($roomType === 'seminar' && stripos($name, 'seminar') === false) {
            $name = $name !== '' ? ($name . ' (Seminar Hall)') : 'Seminar Hall';
        }
        if (!$name || $desks < 1) {
            $unitWord = isSeminarHall($name) ? 'chair' : 'desk';
            go("Enter a room name and a positive $unitWord count.", 'error', $examId);
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO rooms (room_name, desk_count) VALUES (?, ?)");
            $stmt->execute([$name, $desks]);
        } catch (PDOException $e) {
            go('Room name already exists, or the data is invalid.', 'error', $examId);
        }
        $msgAdded = isSeminarHall($name)
            ? "Seminar Hall added with $desks individual chairs (1 student per chair)."
            : "Room added successfully ($desks desks, capacity " . min(34, $desks * 2) . ").";
        go($msgAdded, 'success', $examId);
    }

    if ($action === 'delete_room') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([(int)$_POST['room_id']]);
        go('Room removed.', 'success', $examId);
    }

    if ($action === 'generate') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $subjectsStmt = $pdo->prepare("SELECT id, subject_code, student_count FROM subjects WHERE exam_id = ? ORDER BY student_count DESC, subject_code ASC");
        $subjectsStmt->execute([$examId]);
        $subjects = $subjectsStmt->fetchAll();
        $rooms = $pdo->query("SELECT id, room_name, desk_count FROM rooms ORDER BY room_name ASC")->fetchAll();
        usort($rooms, fn($a, $b) => strnatcasecmp($a['room_name'], $b['room_name']));

        if (!$examId || !$subjects || !$rooms) go('Add at least one subject and one room before generating.', 'error', $examId);

        // Load student queues strictly sorted by:
        // Year -> Department -> Regular (IGW) then Lateral (LIGW) -> Roll Number
        $studentQueues = [];
        $stuStmt = $pdo->prepare("SELECT student_no, register_no, student_name FROM subject_students WHERE subject_id = ? ORDER BY student_no ASC");
        foreach ($subjects as $s) {
            $stuStmt->execute([$s['id']]);
            $list = $stuStmt->fetchAll();
            if (count($list) !== (int)$s['student_count']) {
                go("Student list count mismatch for {$s['subject_code']}. Re-upload the subject student file.", 'error', $examId);
            }
            usort($list, fn($a, $b) => compareRegisterNumbers($a['register_no'], $b['register_no']));
            foreach ($list as &$item) {
                $parsed = parseRegisterNo($item['register_no']);
                $item['cohort']     = $parsed['cohort'];
                $item['is_lateral'] = $parsed['is_lateral'];
            }
            unset($item);
            $studentQueues[$s['subject_code']] = $list;
        }

        $roomState = [];
        foreach ($rooms as $r) {
            $desks = (int)$r['desk_count'];
            $metrics = getRoomMetrics($r['room_name'], $desks);
            $roomState[$r['id']] = [
                'id' => (int)$r['id'],
                'name' => $r['room_name'],
                'is_seminar' => $metrics['is_seminar'],
                'seats_per_unit' => $metrics['seats_per_unit'],
                'desks' => $desks,
                'usable_benches' => $metrics['usable_benches'],
                'capacity' => $metrics['capacity'],
                'benches' => []
            ];
        }

        $totalStudents = array_sum(array_column($subjects, 'student_count'));
        $totalCapacity = array_sum(array_column($roomState, 'capacity'));
        if ($totalStudents > $totalCapacity) {
            go("Insufficient room capacity. Students: $totalStudents; available capacity: $totalCapacity.", 'error', $examId);
        }

        $totalUsableBenches = array_sum(array_column($roomState, 'usable_benches'));
        $maxSubjectCount = max(array_column($subjects, 'student_count'));

        // Fill rooms strictly in Room Number order (Room 1 -> Room 2 -> ...) and Row 1 -> Row 2 -> Row 3.
        // Pass 1 (Anti-bunching primary pass):
        // - In Classrooms: places 1 student per desk for Seat 1, and pairs Seat 2 with a DIFFERENT subject code whenever available.
        // - In Seminar Hall: places 1 student per chair, alternating subject codes across adjacent chairs whenever available.
        // Pass 2 (Unavoidable overflow pass when one subject has >50% of total seats, e.g., 154 out of 165 students):
        // - Any remaining students that exceed single-per-bench / non-adjacent slots are placed into the remaining open seats
        //   in room order so all students are seated cleanly without failing.
        $remaining = [];
        foreach ($subjects as $s) $remaining[$s['subject_code']] = (int)$s['student_count'];
        $studentIndices = array_fill_keys(array_keys($remaining), 0);
        $placed = 0;
        $benchesRemainingTotal = $totalUsableBenches;
        $overflowWarningCount = 0;

        foreach ($roomState as $rid => &$room) {
            if ($placed >= $totalStudents) break;
            $roomOccupied = 0;

            for ($b = 0; $b < $room['usable_benches']; $b++) {
                if ($placed >= $totalStudents || $roomOccupied >= $room['capacity']) {
                    $benchesRemainingTotal--;
                    continue;
                }

                $bench = [];
                $prevBenchSub1 = ($b > 0 && isset($room['benches'][$b - 1][0])) ? $room['benches'][$b - 1][0]['subject'] : null;

                $pickSubject = function (?string $excludeSub, ?string $avoidSameSlotSub = null) use (&$remaining, &$studentQueues, &$studentIndices, &$room, &$benchesRemainingTotal): ?string {
                    $activeCohortsInRoom = [];
                    foreach ($room['benches'] as $existingBench) {
                        foreach ($existingBench as $existingSeat) {
                            $activeCohortsInRoom[$existingSeat['cohort']] = true;
                        }
                    }

                    $bestSub = null;
                    $bestScore = null;

                    foreach ($remaining as $cand => $left) {
                        if ($left <= 0 || $cand === $excludeSub) continue;
                        $nextStu = $studentQueues[$cand][$studentIndices[$cand]];

                        $mustPlaceNow = ($left >= $benchesRemainingTotal) ? 1 : 0;
                        $isSameCohortLigw = ($nextStu['is_lateral'] === 1 && isset($activeCohortsInRoom[$nextStu['cohort']])) ? 1 : 0;
                        $diffFromPrevBench = ($avoidSameSlotSub !== null && $cand !== $avoidSameSlotSub) ? 1 : 0;

                        $score = [
                            $mustPlaceNow,
                            $isSameCohortLigw,
                            $left,
                            $diffFromPrevBench,
                            $nextStu['cohort']
                        ];

                        if ($bestScore === null) {
                            $bestSub = $cand;
                            $bestScore = $score;
                        } else {
                            if ($score[0] !== $bestScore[0]) {
                                if ($score[0] > $bestScore[0]) { $bestSub = $cand; $bestScore = $score; }
                            } elseif ($score[1] !== $bestScore[1]) {
                                if ($score[1] > $bestScore[1]) { $bestSub = $cand; $bestScore = $score; }
                            } elseif ($score[2] !== $bestScore[2]) {
                                if ($score[2] > $bestScore[2]) { $bestSub = $cand; $bestScore = $score; }
                            } elseif ($score[3] !== $bestScore[3]) {
                                if ($score[3] > $bestScore[3]) { $bestSub = $cand; $bestScore = $score; }
                            } elseif (strcmp($score[4], $bestScore[4]) < 0) {
                                $bestSub = $cand;
                                $bestScore = $score;
                            }
                        }
                    }
                    return $bestSub;
                };

                // Seat 1 on this bench / Chair
                // In Seminar Hall, prefer a different subject from the previous chair ($prevBenchSub1),
                // falling back to $prevBenchSub1 only if no other subject remains.
                $sub1 = $room['is_seminar']
                    ? ($pickSubject($prevBenchSub1, null) ?? $pickSubject(null, null))
                    : $pickSubject(null, $prevBenchSub1);

                if ($sub1 !== null) {
                    $stuObj = $studentQueues[$sub1][$studentIndices[$sub1]++];
                    $bench[] = [
                        'subject' => $sub1,
                        'student_no' => $stuObj['student_no'],
                        'register_no' => $stuObj['register_no'],
                        'student_name' => $stuObj['student_name'],
                        'cohort' => $stuObj['cohort']
                    ];
                    $remaining[$sub1]--;
                    $placed++;
                    $roomOccupied++;
                }

                // Seat 2 on the SAME bench (only for 2-seater desks; Seminar Hall has 1 student per chair):
                // Strictly pick a DIFFERENT subject code during Pass 1.
                if (!$room['is_seminar'] && $placed < $totalStudents && $roomOccupied < $room['capacity'] && $sub1 !== null) {
                    $sub2 = $pickSubject($sub1, null);
                    if ($sub2 !== null) {
                        $stuObj = $studentQueues[$sub2][$studentIndices[$sub2]++];
                        $bench[] = [
                            'subject' => $sub2,
                            'student_no' => $stuObj['student_no'],
                            'register_no' => $stuObj['register_no'],
                            'student_name' => $stuObj['student_name'],
                            'cohort' => $stuObj['cohort']
                        ];
                        $remaining[$sub2]--;
                        $placed++;
                        $roomOccupied++;
                    }
                }

                if (!empty($bench)) {
                    $room['benches'][$b] = $bench;
                }
                $benchesRemainingTotal--;
            }
        }
        unset($room);

        // Pass 2: If a dominant subject still has leftover students after filling every single bench/chair once
        // (for example, 154 students of one subject across 148 benches), seat the remaining students in Seat 2
        // of benches in room order so everyone gets a seat.
        if ($placed < $totalStudents) {
            foreach ($roomState as $rid => &$room) {
                if ($placed >= $totalStudents) break;
                if ($room['is_seminar']) continue; // Seminar Hall chairs hold 1 student each

                $roomOccupied = 0;
                foreach ($room['benches'] as $existingBench) {
                    $roomOccupied += count($existingBench);
                }

                for ($b = 0; $b < $room['usable_benches']; $b++) {
                    if ($placed >= $totalStudents || $roomOccupied >= $room['capacity']) break;
                    $currentCount = isset($room['benches'][$b]) ? count($room['benches'][$b]) : 0;
                    if ($currentCount >= 2) continue;

                    // Pick any subject with remaining students
                    $pickAny = null;
                    $maxLeft = 0;
                    foreach ($remaining as $cand => $left) {
                        if ($left > $maxLeft) {
                            $maxLeft = $left;
                            $pickAny = $cand;
                        }
                    }
                    if ($pickAny !== null) {
                        $stuObj = $studentQueues[$pickAny][$studentIndices[$pickAny]++];
                        $room['benches'][$b][] = [
                            'subject' => $pickAny,
                            'student_no' => $stuObj['student_no'],
                            'register_no' => $stuObj['register_no'],
                            'student_name' => $stuObj['student_name'],
                            'cohort' => $stuObj['cohort']
                        ];
                        $remaining[$pickAny]--;
                        $placed++;
                        $roomOccupied++;
                        $overflowWarningCount++;
                    }
                }
            }
            unset($room);
        }

        if ($placed < $totalStudents) {
            go("Could not seat all students in room order without placing the same subject together. " .
               "Placed $placed of $totalStudents. Add more rooms/chairs so same-subject students stay separated.", 'error', $examId);
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM seating WHERE exam_id = ?");
            $del->execute([$examId]);
            $ins = $pdo->prepare("INSERT INTO seating (exam_id, room_id, bench_no, seat_no, subject_code, student_no, register_no, student_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($roomState as $rid => $room) {
                foreach ($room['benches'] as $benchIndex => $bench) {
                    foreach ($bench as $seatIndex => $seat) {
                        $ins->execute([
                            $examId,
                            $rid,
                            $benchIndex + 1,
                            $seatIndex + 1,
                            $seat['subject'],
                            $seat['student_no'],
                            $seat['register_no'],
                            $seat['student_name']
                        ]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            go('Could not save seating arrangement: ' . $e->getMessage(), 'error', $examId);
        }
        $doneMsg = $overflowWarningCount > 0
            ? "Seating arrangement generated for all $placed students in room order (note: $overflowWarningCount excess students of the largest subject had to share benches because the subject count exceeded available single-seat benches)."
            : "Seating arrangement generated successfully for $placed students across rooms/chairs without grouping same-subject students together.";
        go($doneMsg, 'success', $examId);
    }
}

$exams = $pdo->query("SELECT * FROM exams ORDER BY exam_date DESC, id DESC")->fetchAll();
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY room_name ASC")->fetchAll();
usort($rooms, fn($a, $b) => strnatcasecmp($a['room_name'], $b['room_name']));
$selectedExam = (int)($_GET['exam_id'] ?? ($exams[0]['id'] ?? 0));
$selected = null;
foreach ($exams as $e) if ((int)$e['id'] === $selectedExam) $selected = $e;
$subjects = [];
$subjectStudents = [];
if ($selectedExam) {
    $st = $pdo->prepare("SELECT * FROM subjects WHERE exam_id = ? ORDER BY subject_code ASC");
    $st->execute([$selectedExam]);
    $subjects = $st->fetchAll();

    $stStu = $pdo->prepare("SELECT ss.*, s.subject_code FROM subject_students ss JOIN subjects s ON s.id = ss.subject_id WHERE s.exam_id = ? ORDER BY s.subject_code ASC, ss.student_no ASC");
    $stStu->execute([$selectedExam]);
    foreach ($stStu->fetchAll() as $sr) {
        $subjectStudents[$sr['subject_id']][] = $sr;
    }
    foreach ($subjectStudents as &$list) {
        usort($list, fn($a, $b) => compareRegisterNumbers($a['register_no'], $b['register_no']));
    }
    unset($list);
}
$seatRows = [];
if ($selectedExam) {
    $st = $pdo->prepare("SELECT s.*, r.room_name, r.desk_count FROM seating s JOIN rooms r ON r.id=s.room_id WHERE s.exam_id=? ORDER BY s.bench_no ASC, s.seat_no ASC");
    $st->execute([$selectedExam]);
    $seatRows = $st->fetchAll();
}

// Group seating by room for 3-row bench layout AND room-wise + subject-wise ascending list
$byRoom = [];
$byRoomSubject = [];
$roomDesksMap = [];
foreach ($rooms as $r) {
    $m = getRoomMetrics($r['room_name'], (int)$r['desk_count']);
    $roomDesksMap[$r['room_name']] = $m['usable_benches'];
}
foreach ($seatRows as $row) {
    $rName = $row['room_name'];
    $byRoom[$rName][$row['bench_no']][] = $row;
    $byRoomSubject[$rName][$row['subject_code']][] = $row;
}
uksort($byRoom, 'strnatcasecmp');
uksort($byRoomSubject, 'strnatcasecmp');
// Ensure subject-wise lists inside every room follow Year -> Department -> IGW then LIGW -> Roll Number
foreach ($byRoomSubject as $rName => &$subGroups) {
    ksort($subGroups);
    foreach ($subGroups as $sCode => &$sList) {
        usort($sList, fn($a, $b) => compareRegisterNumbers($a['register_no'], $b['register_no']));
    }
}
unset($subGroups, $sList);

$msg = $_GET['msg'] ?? ''; $type = $_GET['type'] ?? 'success';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Examination Seating Arrangement</title>
<style>
:root{--blue:#2457a7;--bg:#f4f6fa;--line:#dce2eb;--text:#1f2937}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,sans-serif;color:var(--text)}
header{background:#173d75;color:white;padding:22px 5%;}header h1{margin:0 0 5px;font-size:24px}header p{margin:0;color:#dce8fa}
main{max-width:1200px;margin:24px auto;padding:0 16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}
.card{background:white;border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 8px #14213d0a}
h2{font-size:18px;margin:0 0 14px}label{display:block;font-size:13px;font-weight:bold;margin:10px 0 5px}
input,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px}
input[type="file"]{padding:7px;background:#f8fafc}
button,.btn{display:inline-block;border:0;background:var(--blue);color:white;padding:10px 15px;border-radius:7px;cursor:pointer;text-decoration:none;font-size:14px;margin-top:12px}
button.danger{background:#b42318}button.secondary{background:#475569}.muted{color:#64748b;font-size:13px}.alert{padding:12px;border-radius:8px;margin-bottom:16px;background:#e8f5e9;color:#176b2c}.alert.error{background:#fdecec;color:#a11b1b}
table{width:100%;border-collapse:collapse;font-size:14px;margin-bottom:14px}th,td{padding:8px 9px;border:1px solid var(--line);text-align:left}th{background:#f1f5f9}
.inline{display:inline}.inline button{margin:0;padding:6px 9px}.room{margin:22px 0;padding-bottom:14px;border-bottom:2px solid #cbd5e1}
.three-rows{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.row-col{background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:10px}
.row-col h4{margin:0 0 10px;padding:6px 10px;background:#173d75;color:#fff;border-radius:6px;font-size:14px;text-align:center}
.bench{border:1px solid var(--line);border-radius:8px;padding:9px;background:#ffffff;margin-bottom:10px}
.bench strong{display:block;margin-bottom:6px;font-size:13px;color:#1e293b}
.seat{font-size:13px;padding:6px 8px;margin:4px 0;background:#eaf1fb;border-radius:5px;line-height:1.35}
.seat .reg{font-weight:bold;color:#0f294a}.seat .sname{color:#334155;font-size:12px}
.badge-lat{display:inline-block;background:#fef3c7;color:#92400e;font-size:10px;font-weight:bold;padding:1px 5px;border-radius:4px;margin-left:4px;vertical-align:middle}
.actions{display:flex;gap:8px;flex-wrap:wrap}.actions button{margin-top:0}
details{margin-top:6px}details summary{cursor:pointer;color:var(--blue);font-size:13px;font-weight:bold}
.stu-list{max-height:180px;overflow-y:auto;border:1px solid var(--line);border-radius:6px;margin-top:6px;padding:6px;background:#f8fafc;font-size:12px}
.sub-badge{display:inline-block;background:#173d75;color:#fff;padding:3px 9px;border-radius:5px;font-size:13px;margin:10px 0 6px}
.print-only-header{display:none}
@media (max-width:850px){.three-rows{grid-template-columns:1fr}}
@media print{
  @page{size:A4 portrait;margin:10mm}
  header,.no-print,.card.forms,.screen-only{display:none!important}
  body{background:white;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  main{max-width:none;margin:0;padding:0}
  .card{box-shadow:none;border:0;padding:0;margin:0}
  .print-only-header{display:block;border-bottom:2px solid #173d75;padding-bottom:6px;margin-bottom:10px}
  .print-only-header h2{margin:0 0 2px;font-size:16px;color:#173d75}
  .print-only-header p{margin:0;font-size:12px;color:#334155}
  /* Force each room (both bench chart and room-wise register list) onto its own single page */
  .room,.room-print-block{
    page-break-inside:avoid;
    break-inside:avoid;
    page-break-after:always;
    break-after:page;
    border-bottom:0;
    margin:0;
    padding:0;
  }
  .room-print-block:last-of-type{
    page-break-after:auto;
    break-after:auto;
  }
  .room h3,.room-print-block h3{margin:0 0 8px;font-size:15px}
  .three-rows{grid-template-columns:repeat(3,1fr)!important;gap:8px}
  .row-col{padding:6px;border-radius:6px}
  .row-col h4{margin:0 0 6px;padding:4px 6px;font-size:12px}
  .bench{border:1px solid #888;padding:6px;margin-bottom:6px;border-radius:5px}
  .bench strong{font-size:11px;margin-bottom:4px}
  .seat{font-size:11px;padding:4px 6px;margin:3px 0;line-height:1.25}
  .seat .sname{font-size:10px}
  .sub-badge{margin:6px 0 4px;padding:2px 7px;font-size:11px}
  table{font-size:11px;margin-bottom:8px}
  th,td{padding:4px 6px;border:1px solid #888}
}
</style>
</head>
<body>
<header><h1>Examination Seating Arrangement</h1><p>Indira Gandhi Institute of Engineering and Technology</p></header>
<main>
<?php if ($msg): ?><div class="alert <?= $type==='error'?'error':'' ?>"><?=h($msg)?></div><?php endif; ?>
<div class="grid no-print">
<section class="card forms">
<h2>1. Create Examination</h2>
<form method="post"><input type="hidden" name="action" value="add_exam">
<label>Examination name</label><input name="exam_name" placeholder="e.g. S3 B.Tech Internal Examination">
<label>Examination date</label><input type="date" name="exam_date" required>
<button type="submit">Create Examination</button></form>
</section>

<section class="card forms">
<h2>2. Add Subject & Student List File</h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="add_subject">
<label>Select examination</label>
<select name="exam_id" required><?php foreach($exams as $e): ?><option value="<?=h($e['id'])?>" <?=$selectedExam==$e['id']?'selected':''?>><?=h($e['exam_name'])?> — <?=h($e['exam_date'])?></option><?php endforeach; ?></select>
<label>Subject code</label>
<input name="subject_code" placeholder="e.g. CS301" required>
<label>Student list file (.csv or .xlsx)</label>
<input type="file" name="student_file" accept=".csv,.xlsx" required>
<p class="muted" style="margin:6px 0 0">Columns: <b>Register Number</b>, <b>Student Name</b>. Sorted by Year → Dept → IGW then LIGW. <a href="?download_sample=1">Download sample CSV</a></p>
<button type="submit">Upload & Add Subject</button>
</form>
</section>

<section class="card forms">
<h2>3. Add Examination Room</h2>
<form method="post"><input type="hidden" name="action" value="add_room">
<input type="hidden" name="exam_id" value="<?=h($selectedExam)?>">
<label>Room type</label>
<select name="room_type" id="room_type" onchange="toggleRoomUnit()">
  <option value="classroom">Classroom (Desks — 2 seats per desk)</option>
  <option value="seminar">Seminar Hall (Chairs — 1 seat per chair)</option>
</select>
<label>Room name / number</label>
<input name="room_name" id="room_name" placeholder="e.g. Room 101 or Seminar Hall" oninput="autoDetectSeminar()" required>
<label id="desk_count_label">Total desks</label>
<div style="display:flex;gap:8px;align-items:center">
  <input type="number" name="desk_count" id="desk_count" min="1" required>
  <button type="button" id="double_desk_btn" class="secondary" style="display:none;margin-top:0;white-space:nowrap" onclick="convertDesksToChairs()" title="Convert entered desk count × 2 into individual chairs">×2 to Chairs</button>
</div>
<p class="muted" id="room_cap_hint">Capacity is automatically limited to the smaller of 34 students or 2 × desk count.</p>
<button type="submit">Add Room</button></form>
<script>
function autoDetectSeminar() {
  const nameVal = document.getElementById('room_name').value.toLowerCase();
  const typeSel = document.getElementById('room_type');
  if (nameVal.includes('seminar')) {
    typeSel.value = 'seminar';
  }
  toggleRoomUnit();
}
function toggleRoomUnit() {
  const isSem = document.getElementById('room_type').value === 'seminar' ||
                document.getElementById('room_name').value.toLowerCase().includes('seminar');
  document.getElementById('desk_count_label').textContent = isSem ? 'Total chairs' : 'Total desks';
  document.getElementById('double_desk_btn').style.display = isSem ? 'inline-block' : 'none';
  document.getElementById('room_cap_hint').textContent = isSem
    ? 'Seminar Hall mode: counted as individual chairs (1 student per chair, no 34-seat limit). Tip: type 17 and click "×2 to Chairs" for 34 chairs.'
    : 'Classroom mode: capacity is the smaller of 34 students or 2 × desk count (e.g. 17 desks = 34 seats).';
}
function convertDesksToChairs() {
  const inp = document.getElementById('desk_count');
  const val = parseInt(inp.value, 10);
  if (val > 0) inp.value = val * 2;
}
</script>
</section>
</div>

<section class="card no-print">
<h2>4. Current Examination & Subjects</h2>
<form method="get" class="actions"><select name="exam_id" style="max-width:420px"><?php foreach($exams as $e): ?><option value="<?=h($e['id'])?>" <?=$selectedExam==$e['id']?'selected':''?>><?=h($e['exam_name'])?> — <?=h($e['exam_date'])?></option><?php endforeach; ?></select><button type="submit">View</button></form>
<?php if($selected): ?><p><strong><?=h($selected['exam_name'])?></strong> | <?=h($selected['exam_date'])?></p><?php endif; ?>
<h3>Subjects</h3>
<?php if($subjects): ?>
<table>
<tr><th>Subject code</th><th>Students</th><th>Student Register List (Year → Dept → IGW + LIGW)</th><th>Action</th></tr>
<?php foreach($subjects as $s): $stuList = $subjectStudents[$s['id']] ?? []; ?>
<tr>
  <td><strong><?=h($s['subject_code'])?></strong></td>
  <td><?=h($s['student_count'])?></td>
  <td>
    <details>
      <summary>View <?=count($stuList)?> students</summary>
      <div class="stu-list">
        <?php foreach($stuList as $idx => $stItem): $parsed = parseRegisterNo($stItem['register_no']); ?>
          <div><?=($idx + 1)?>. <b><?=h($stItem['register_no'])?></b><?php if($parsed['is_lateral']): ?><span class="badge-lat">LE</span><?php endif; ?> — <?=h($stItem['student_name'])?></div>
        <?php endforeach; ?>
      </div>
    </details>
  </td>
  <td>
    <form class="inline" method="post" onsubmit="return confirm('Remove this subject and its uploaded students?')">
      <input type="hidden" name="action" value="delete_subject">
      <input type="hidden" name="exam_id" value="<?=h($selectedExam)?>">
      <input type="hidden" name="subject_id" value="<?=h($s['id'])?>">
      <button class="danger">Remove</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</table>
<p><strong>Total students across all subjects: <?=array_sum(array_column($subjects,'student_count'))?></strong></p>
<?php else: ?><p class="muted">No subjects added for this examination.</p><?php endif; ?>
</section>

<section class="card no-print">
<h2>5. Available Rooms</h2>
<?php if($rooms): ?><table><tr><th>Room</th><th>Type</th><th>Desks / Chairs</th><th>Maximum capacity</th><th>Action</th></tr><?php foreach($rooms as $r): $rm = getRoomMetrics($r['room_name'], (int)$r['desk_count']); ?><tr><td><?=h($r['room_name'])?></td><td><?=$rm['is_seminar']?'Seminar Hall (Chairs)':'Classroom (Desks)'?></td><td><?=h($r['desk_count'])?> <?=$rm['unit_label']?></td><td><?=$rm['capacity']?></td><td><form class="inline" method="post" onsubmit="return confirm('Remove this room?')"><input type="hidden" name="action" value="delete_room"><input type="hidden" name="exam_id" value="<?=h($selectedExam)?>"><input type="hidden" name="room_id" value="<?=h($r['id'])?>"><button class="danger">Remove</button></form></td></tr><?php endforeach; ?></table><?php else: ?><p class="muted">No rooms added yet.</p><?php endif; ?>
</section>

<section class="card no-print">
<h2>6. Generate Seating Arrangement</h2>
<p class="muted">Fills Room 1 (Row 1 → Row 2 → Row 3) sequentially in Year → Department → Regular (IGW) followed immediately by Lateral (LIGW) → Ascending Roll order, keeping LIGW in the same room whenever seats remain vacant after IGW.</p>
<form method="post" onsubmit="return confirm('Generate or replace the seating arrangement for this examination?')">
<input type="hidden" name="action" value="generate"><input type="hidden" name="exam_id" value="<?=h($selectedExam)?>">
<button type="submit" <?=(!$subjects || !$rooms)?'disabled':''?>>Generate Seating Arrangement</button>
</form>
</section>

<?php if($seatRows): ?>
<section class="card">
<div class="actions no-print" style="justify-content:space-between;align-items:center">
  <h2>Generated Seating Chart (3-Row Layout)</h2>
  <button onclick="window.print()">Print Seating Chart</button>
</div>
<p class="screen-only"><strong><?=h($selected['exam_name'] ?? '')?> — <?=h($selected['exam_date'] ?? '')?></strong></p>

<?php foreach($byRoom as $roomName => $benches):
    $totalRoomBenches = max($roomDesksMap[$roomName] ?? 1, max(array_keys($benches)));
    $perRow = max(1, (int)ceil($totalRoomBenches / 3));
?>
<div class="room">
  <div class="print-only-header">
    <h2>Indira Gandhi Institute of Engineering and Technology</h2>
    <p><strong><?=h($selected['exam_name'] ?? '')?></strong> | Date: <strong><?=h($selected['exam_date'] ?? '')?></strong> | Room: <strong><?=h($roomName)?></strong> (Bench Layout)</p>
  </div>
  <h3>Room No : <?=h($roomName)?> </h3>
  <div class="three-rows">
    <?php for ($rowNum = 1; $rowNum <= 3; $rowNum++):
        $startBench = ($rowNum - 1) * $perRow + 1;
        $endBench   = min($totalRoomBenches, $rowNum * $perRow);
    ?>
    <div class="row-col">
      <h4>Row <?= $rowNum ?></h4>
      <?php
      $hasBenchesInRow = false;
      for ($bNo = $startBench; $bNo <= $endBench; $bNo++):
          if (!isset($benches[$bNo])) continue;
          $hasBenchesInRow = true;
          $rowBenchIndex = $bNo - $startBench + 1;
      ?>
        <?php $isSemRoom = isSeminarHall($roomName); ?>
        <div class="bench">
          <strong>Row <?= $rowNum ?> — <?= $isSemRoom ? "Chair $rowBenchIndex" : "Bench $rowBenchIndex" ?> <span class="muted">(<?= $isSemRoom ? "Chair #$bNo" : "Desk #$bNo" ?>)</span></strong>
          <?php foreach($benches[$bNo] as $seat): $parsed = parseRegisterNo($seat['register_no']); ?>
            <div class="seat">
              <?= $isSemRoom ? "Chair #$bNo" : "Seat " . h($seat['seat_no']) ?>: <b><?=h($seat['subject_code'])?></b><br>
              <span class="reg"><?=h($seat['register_no'])?></span><?php if($parsed['is_lateral']): ?><span class="badge-lat">LE</span><?php endif; ?><br>
              <span class="sname"><?=h($seat['student_name'])?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
      <?php if (!$hasBenchesInRow): ?>
        <p class="muted" style="text-align:center">Empty</p>
      <?php endif; ?>
    </div>
    <?php endfor; ?>
  </div>
</div>
<?php endforeach; ?>

<h2 class="screen-only" style="margin-top:24px">Room-wise & Subject-wise Register List (Year → Dept → IGW + LIGW)</h2>
<?php foreach($byRoomSubject as $roomName => $subGroups):
    $totB = $roomDesksMap[$roomName] ?? 15;
    $pRow = max(1, (int)ceil($totB / 3));
?>
<div class="room-print-block">
  <div class="print-only-header">
    <h2>Indira Gandhi Institute of Engineering and Technology</h2>
    <p><strong><?=h($selected['exam_name'] ?? '')?></strong> | Date: <strong><?=h($selected['exam_date'] ?? '')?></strong> | Room: <strong><?=h($roomName)?></strong> (Register List)</p>
  </div>
  <h3><?=h($roomName)?></h3>
  <?php foreach($subGroups as $subCode => $stuRows):
      $firstReg = $stuRows[0]['register_no'] ?? '';
      $lastReg  = $stuRows[count($stuRows) - 1]['register_no'] ?? '';
  ?>
    <div class="sub-badge">Subject: <?=h($subCode)?> (<?=count($stuRows)?> Students: <?=h($firstReg)?> to <?=h($lastReg)?>)</div>
    <table>
      <tr>
        <th style="width:55px">Sl.No</th>
        <th style="width:170px">Register Number</th>
        <th>Student Name</th>
        <th style="width:85px">Row</th>
        <?php $isSemList = isSeminarHall($roomName); ?>
        <th style="width:95px"><?= $isSemList ? 'Chair' : 'Bench' ?></th>
        <th style="width:70px">Seat</th>
      </tr>
      <?php foreach($stuRows as $idx => $sr):
          $bNo  = (int)$sr['bench_no'];
          $rNum = (int)ceil($bNo / $pRow);
          $rbNo = $bNo - ($rNum - 1) * $pRow;
          $parsed = parseRegisterNo($sr['register_no']);
      ?>
      <tr>
        <td><?=($idx + 1)?></td>
        <td><b><?=h($sr['register_no'])?></b><?php if($parsed['is_lateral']): ?><span class="badge-lat">LE</span><?php endif; ?></td>
        <td><?=h($sr['student_name'])?></td>
        <td>Row <?=$rNum?></td>
        <td><?= $isSemList ? "Chair $rbNo (#$bNo)" : "Bench $rbNo" ?></td>
        <td>Seat <?=h($sr['seat_no'])?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="no-print">
<h3 style="margin-top:20px">Subject-wise Total Allocation</h3>
<table><tr><th>Subject</th><th>Allocated</th></tr>
<?php foreach($subjects as $s): $count=0; foreach($seatRows as $sr) if($sr['subject_code']===$s['subject_code']) $count++; ?><tr><td><?=h($s['subject_code'])?></td><td><?=$count?> / <?=h($s['student_count'])?></td></tr><?php endforeach; ?></table>
</div>
</section>
<?php endif; ?>
<p class="muted no-print">Upload a .csv or .xlsx student list for each subject code to assign seats with actual Register Numbers and Student Names.</p>
</main>
</body></html>