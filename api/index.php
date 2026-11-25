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

/**
 * Parse uploaded CSV or Excel (.xlsx) file containing Register Number and Student Name.
 * Supports headers in first row (e.g., register_no / reg_no / register number, name / student_name)
 * or plain 2-column files (Column A = Register Number, Column B = Student Name).
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

    return $students;
}

if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_students.csv"');
    echo "register_no,student_name\n";
    echo "REG2026001,Arjun Menon\n";
    echo "REG2026002,Meera Nair\n";
    echo "REG2026003,Rahul Krishnan\n";
    echo "REG2026004,Sneha Varma\n";
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
        go("Subject $code added successfully with $count students.", 'success', $examId);
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
        $desks = (int)($_POST['desk_count'] ?? 0);
        if (!$name || $desks < 1) go('Enter a room name and a positive desk count.', 'error', $examId);
        try {
            $stmt = $pdo->prepare("INSERT INTO rooms (room_name, desk_count) VALUES (?, ?)");
            $stmt->execute([$name, $desks]);
        } catch (PDOException $e) {
            go('Room name already exists, or the data is invalid.', 'error', $examId);
        }
        go('Room added successfully.', 'success', $examId);
    }

    if ($action === 'delete_room') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([(int)$_POST['room_id']]);
        go('Room removed.', 'success', $examId);
    }

    if ($action === 'generate') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $subjectsStmt = $pdo->prepare("SELECT id, subject_code, student_count FROM subjects WHERE exam_id = ? ORDER BY student_count DESC, subject_code");
        $subjectsStmt->execute([$examId]);
        $subjects = $subjectsStmt->fetchAll();
        $rooms = $pdo->query("SELECT id, room_name, desk_count FROM rooms ORDER BY room_name")->fetchAll();

        if (!$examId || !$subjects || !$rooms) go('Add at least one subject and one room before generating.', 'error', $examId);

        // Load student queues ordered by student_no (register number order from file)
        $studentQueues = [];
        $stuStmt = $pdo->prepare("SELECT student_no, register_no, student_name FROM subject_students WHERE subject_id = ? ORDER BY student_no ASC");
        foreach ($subjects as $s) {
            $stuStmt->execute([$s['id']]);
            $list = $stuStmt->fetchAll();
            if (count($list) !== (int)$s['student_count']) {
                go("Student list count mismatch for {$s['subject_code']}. Re-upload the subject student file.", 'error', $examId);
            }
            $studentQueues[$s['subject_code']] = $list;
        }

        $roomState = [];
        foreach ($rooms as $r) {
            $desks = (int)$r['desk_count'];
            $cap = min(30, $desks * 2);
            $usableBenches = min($desks, (int)ceil($cap / 2));
            $roomState[$r['id']] = [
                'id' => (int)$r['id'],
                'name' => $r['room_name'],
                'desks' => $desks,
                'usable_benches' => $usableBenches,
                'capacity' => $cap,
                'benches' => []
            ];
        }

        $totalStudents = array_sum(array_column($subjects, 'student_count'));
        $totalCapacity = array_sum(array_column($roomState, 'capacity'));
        if ($totalStudents > $totalCapacity) {
            go("Insufficient room capacity. Students: $totalStudents; available capacity: $totalCapacity.", 'error', $examId);
        }

        // Fill sequentially: Room 1 -> Room 2..., and inside each room:
        // Row 1 (Bench 1..N) -> Row 2 (Bench 1..N) -> Row 3 (Bench 1..N).
        // Each bench gets 2 students of different subjects before moving to the next bench in that row.
        $remaining = [];
        foreach ($subjects as $s) $remaining[$s['subject_code']] = (int)$s['student_count'];
        $studentIndices = array_fill_keys(array_keys($remaining), 0);
        $placed = 0;

        foreach ($roomState as $rid => &$room) {
            if ($placed >= $totalStudents) break;
            $roomOccupied = 0;

            for ($b = 0; $b < $room['usable_benches']; $b++) {
                if ($placed >= $totalStudents || $roomOccupied >= $room['capacity']) break;

                $bench = [];
                // Seat 1 on this bench: pick subject with highest remaining count
                arsort($remaining);
                $sub1 = null;
                foreach ($remaining as $cand => $left) {
                    if ($left > 0) { $sub1 = $cand; break; }
                }
                if ($sub1 !== null) {
                    $stuObj = $studentQueues[$sub1][$studentIndices[$sub1]++];
                    $bench[] = [
                        'subject' => $sub1,
                        'student_no' => $stuObj['student_no'],
                        'register_no' => $stuObj['register_no'],
                        'student_name' => $stuObj['student_name']
                    ];
                    $remaining[$sub1]--;
                    $placed++;
                    $roomOccupied++;
                }

                // Seat 2 on the SAME bench: pick a DIFFERENT subject with highest remaining count
                if ($placed < $totalStudents && $roomOccupied < $room['capacity'] && $sub1 !== null) {
                    arsort($remaining);
                    $sub2 = null;
                    foreach ($remaining as $cand => $left) {
                        if ($left > 0 && $cand !== $sub1) { $sub2 = $cand; break; }
                    }
                    if ($sub2 !== null) {
                        $stuObj = $studentQueues[$sub2][$studentIndices[$sub2]++];
                        $bench[] = [
                            'subject' => $sub2,
                            'student_no' => $stuObj['student_no'],
                            'register_no' => $stuObj['register_no'],
                            'student_name' => $stuObj['student_name']
                        ];
                        $remaining[$sub2]--;
                        $placed++;
                        $roomOccupied++;
                    }
                }

                if (!empty($bench)) {
                    $room['benches'][$b] = $bench;
                }
            }
        }
        unset($room);

        if ($placed < $totalStudents) {
            go("Could not seat all students sequentially without placing the same subject together on a bench. " .
               "Placed $placed of $totalStudents. Add more rooms/desks or balance subject counts.", 'error', $examId);
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
        go("Seating arrangement generated successfully for $placed students in 3-row sequential order.", 'success', $examId);
    }
}

$exams = $pdo->query("SELECT * FROM exams ORDER BY exam_date DESC, id DESC")->fetchAll();
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY room_name")->fetchAll();
$selectedExam = (int)($_GET['exam_id'] ?? ($exams[0]['id'] ?? 0));
$selected = null;
foreach ($exams as $e) if ((int)$e['id'] === $selectedExam) $selected = $e;
$subjects = [];
$subjectStudents = [];
if ($selectedExam) {
    $st = $pdo->prepare("SELECT * FROM subjects WHERE exam_id = ? ORDER BY subject_code");
    $st->execute([$selectedExam]);
    $subjects = $st->fetchAll();

    $stStu = $pdo->prepare("SELECT ss.*, s.subject_code FROM subject_students ss JOIN subjects s ON s.id = ss.subject_id WHERE s.exam_id = ? ORDER BY s.subject_code, ss.student_no");
    $stStu->execute([$selectedExam]);
    foreach ($stStu->fetchAll() as $sr) {
        $subjectStudents[$sr['subject_id']][] = $sr;
    }
}
$seatRows = [];
if ($selectedExam) {
    $st = $pdo->prepare("SELECT s.*, r.room_name, r.desk_count FROM seating s JOIN rooms r ON r.id=s.room_id WHERE s.exam_id=? ORDER BY r.room_name, s.bench_no, s.seat_no");
    $st->execute([$selectedExam]);
    $seatRows = $st->fetchAll();
}

// Group seating by room and split into 3 rows (Row 1, Row 2, Row 3)
$byRoom = [];
$roomDesksMap = [];
foreach ($rooms as $r) {
    $usable = min((int)$r['desk_count'], (int)ceil(min(30, (int)$r['desk_count'] * 2) / 2));
    $roomDesksMap[$r['room_name']] = max(1, $usable);
}
foreach ($seatRows as $row) {
    $byRoom[$row['room_name']][$row['bench_no']][] = $row;
}
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
table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:9px;border-bottom:1px solid var(--line);text-align:left}th{background:#f8fafc}
.inline{display:inline}.inline button{margin:0;padding:6px 9px}.room{margin:22px 0;padding-bottom:12px;border-bottom:2px solid #e2e8f0}
.three-rows{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.row-col{background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:10px}
.row-col h4{margin:0 0 10px;padding:6px 10px;background:#173d75;color:#fff;border-radius:6px;font-size:14px;text-align:center}
.bench{border:1px solid var(--line);border-radius:8px;padding:9px;background:#ffffff;margin-bottom:10px}
.bench strong{display:block;margin-bottom:6px;font-size:13px;color:#1e293b}
.seat{font-size:13px;padding:6px 8px;margin:4px 0;background:#eaf1fb;border-radius:5px;line-height:1.35}
.seat .reg{font-weight:bold;color:#0f294a}.seat .sname{color:#334155;font-size:12px}
.actions{display:flex;gap:8px;flex-wrap:wrap}.actions button{margin-top:0}
details{margin-top:6px}details summary{cursor:pointer;color:var(--blue);font-size:13px;font-weight:bold}
.stu-list{max-height:180px;overflow-y:auto;border:1px solid var(--line);border-radius:6px;margin-top:6px;padding:6px;background:#f8fafc;font-size:12px}
@media (max-width:850px){.three-rows{grid-template-columns:1fr}}
@media print{
  header,.no-print,.card.forms{display:none!important}
  body{background:white}
  main{max-width:none;margin:0;padding:0}
  .card{box-shadow:none;border:0;padding:0}
  .room{page-break-inside:avoid;border-bottom:0}
  .three-rows{grid-template-columns:repeat(3,1fr)!important;gap:10px}
  .bench{border:1px solid #888}
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
<p class="muted" style="margin:6px 0 0">Columns: <b>Register Number</b>, <b>Student Name</b>. <a href="?download_sample=1">Download sample CSV</a></p>
<button type="submit">Upload & Add Subject</button>
</form>
</section>

<section class="card forms">
<h2>3. Add Examination Room</h2>
<form method="post"><input type="hidden" name="action" value="add_room">
<input type="hidden" name="exam_id" value="<?=h($selectedExam)?>">
<label>Room name / number</label><input name="room_name" placeholder="e.g. Room 101" required>
<label>Total desks</label><input type="number" name="desk_count" min="1" required>
<p class="muted">Capacity is automatically limited to the smaller of 30 students or 2 × desk count.</p>
<button type="submit">Add Room</button></form>
</section>
</div>

<section class="card no-print">
<h2>4. Current Examination & Subjects</h2>
<form method="get" class="actions"><select name="exam_id" style="max-width:420px"><?php foreach($exams as $e): ?><option value="<?=h($e['id'])?>" <?=$selectedExam==$e['id']?'selected':''?>><?=h($e['exam_name'])?> — <?=h($e['exam_date'])?></option><?php endforeach; ?></select><button type="submit">View</button></form>
<?php if($selected): ?><p><strong><?=h($selected['exam_name'])?></strong> | <?=h($selected['exam_date'])?></p><?php endif; ?>
<h3>Subjects</h3>
<?php if($subjects): ?>
<table>
<tr><th>Subject code</th><th>Students</th><th>Student Register List</th><th>Action</th></tr>
<?php foreach($subjects as $s): $stuList = $subjectStudents[$s['id']] ?? []; ?>
<tr>
  <td><strong><?=h($s['subject_code'])?></strong></td>
  <td><?=h($s['student_count'])?></td>
  <td>
    <details>
      <summary>View <?=count($stuList)?> students</summary>
      <div class="stu-list">
        <?php foreach($stuList as $stItem): ?>
          <div><?=h($stItem['student_no'])?>. <b><?=h($stItem['register_no'])?></b> — <?=h($stItem['student_name'])?></div>
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
<?php if($rooms): ?><table><tr><th>Room</th><th>Desks</th><th>Maximum capacity</th><th>Action</th></tr><?php foreach($rooms as $r): ?><tr><td><?=h($r['room_name'])?></td><td><?=h($r['desk_count'])?></td><td><?=min(30,(int)$r['desk_count']*2)?></td><td><form class="inline" method="post" onsubmit="return confirm('Remove this room?')"><input type="hidden" name="action" value="delete_room"><input type="hidden" name="exam_id" value="<?=h($selectedExam)?>"><input type="hidden" name="room_id" value="<?=h($r['id'])?>"><button class="danger">Remove</button></form></td></tr><?php endforeach; ?></table><?php else: ?><p class="muted">No rooms added yet.</p><?php endif; ?>
</section>

<section class="card no-print">
<h2>6. Generate Seating Arrangement</h2>
<p class="muted">Generation fills each room in 3 rows sequentially: Row 1 (first bench to last bench, 2 different subjects per bench), then Row 2, then Row 3.</p>
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
<p><strong><?=h($selected['exam_name'] ?? '')?> — <?=h($selected['exam_date'] ?? '')?></strong></p>

<?php foreach($byRoom as $roomName => $benches):
    $totalRoomBenches = max($roomDesksMap[$roomName] ?? 1, max(array_keys($benches)));
    $perRow = max(1, (int)ceil($totalRoomBenches / 3));
?>
<div class="room">
  <h3><?=h($roomName)?></h3>
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
        <div class="bench">
          <strong>Row <?= $rowNum ?> — Bench <?= $rowBenchIndex ?> <span class="muted">(Desk #<?= $bNo ?>)</span></strong>
          <?php foreach($benches[$bNo] as $seat): ?>
            <div class="seat">
              Seat <?=h($seat['seat_no'])?>: <b><?=h($seat['subject_code'])?></b><br>
              <span class="reg"><?=h($seat['register_no'])?></span><br>
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

<h3>Room-wise Student Register Summary</h3>
<table>
  <tr><th>Room</th><th>Row</th><th>Row Bench</th><th>Seat</th><th>Subject Code</th><th>Register Number</th><th>Student Name</th></tr>
  <?php foreach($seatRows as $sr):
      $rName = $sr['room_name'];
      $totB  = $roomDesksMap[$rName] ?? 15;
      $pRow  = max(1, (int)ceil($totB / 3));
      $bNo   = (int)$sr['bench_no'];
      $rNum  = (int)ceil($bNo / $pRow);
      $rbNo  = $bNo - ($rNum - 1) * $pRow;
  ?>
  <tr>
    <td><?=h($rName)?></td>
    <td>Row <?=$rNum?></td>
    <td>Bench <?=$rbNo?></td>
    <td><?=h($sr['seat_no'])?></td>
    <td><b><?=h($sr['subject_code'])?></b></td>
    <td><b><?=h($sr['register_no'])?></b></td>
    <td><?=h($sr['student_name'])?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h3 style="margin-top:20px">Subject-wise Allocation</h3>
<table><tr><th>Subject</th><th>Allocated</th></tr>
<?php foreach($subjects as $s): $count=0; foreach($seatRows as $sr) if($sr['subject_code']===$s['subject_code']) $count++; ?><tr><td><?=h($s['subject_code'])?></td><td><?=$count?> / <?=h($s['student_count'])?></td></tr><?php endforeach; ?></table>
</section>
<?php endif; ?>
<p class="muted no-print">Upload a .csv or .xlsx student list for each subject code to assign seats with actual Register Numbers and Student Names.</p>
</main>
</body></html>