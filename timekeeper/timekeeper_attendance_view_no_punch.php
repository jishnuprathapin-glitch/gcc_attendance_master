<?php

require dirname(__DIR__) . '/admin/include/bootstrap.php';

$page_title = 'No Punch Daily';

function normalize_date(?string $value, string $fallback): string {
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return $fallback;
    }
    return $dt->format('Y-m-d');
}

function normalize_multi_param($value): array {
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = [$value];
    }
    $clean = [];
    foreach ($items as $item) {
        if (!is_scalar($item) && $item !== null) {
            continue;
        }
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        $clean[$item] = true;
    }
    return array_keys($clean);
}

function normalize_search_terms(?string $value): array {
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }
    $parts = preg_split('/[\\s,;]+/', $value);
    if (!$parts) {
        return [];
    }
    $clean = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $clean[$part] = true;
    }
    return array_keys($clean);
}

function ensure_timekeeper_project_map_table(mysqli $bd): bool {
    $sql = 'CREATE TABLE IF NOT EXISTS `gcc_attendance_master`.`timekeeper_project_map` (' .
        '`user_id` varchar(50) NOT NULL,' .
        '`project_code` varchar(20) NOT NULL,' .
        '`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'PRIMARY KEY (`user_id`, `project_code`)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return (bool) $bd->query($sql);
}

function load_timekeeper_projects(mysqli $bd, string $userId): array {
    if ($userId === '') {
        return [];
    }
    $projects = [];
    $stmt = $bd->prepare(
        'SELECT project_code FROM gcc_attendance_master.timekeeper_project_map WHERE user_id = ? ORDER BY project_code'
    );
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = trim((string) ($row['project_code'] ?? ''));
                    if ($code !== '') {
                        $projects[] = $code;
                    }
                }
                $result->free();
            }
        }
        $stmt->close();
    }
    return $projects;
}

function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function build_query_url(array $params): string {
    $base = admin_url('timekeeper_attendance_view_no_punch.php');
    $query = http_build_query($params);
    if ($query === '') {
        return $base;
    }
    return $base . '?' . $query;
}

$uaeTz = new DateTimeZone('Asia/Dubai');
$todayUae = (new DateTimeImmutable('now', $uaeTz))->format('Y-m-d');

$selectedDate = normalize_date($_GET['date'] ?? '', $todayUae);
$projectFilter = normalize_multi_param($_GET['project_code'] ?? []);
$searchInput = trim((string) ($_GET['search'] ?? ''));
$searchTerms = normalize_search_terms($searchInput);

$userId = trim((string) ($_SESSION['user_id'] ?? ''));
$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$loadError = null;
$mappingRequired = false;
$mappedProjects = [];
$projectOptions = [];
$workTypeOptions = [];
$noPunchReasonOptions = [];
$rows = [];
$remainingCount = 0;
$flash = get_flash();

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    if (!ensure_timekeeper_project_map_table($bd)) {
        $loadError = 'Unable to load project access configuration.';
    } else {
        $mappedProjects = load_timekeeper_projects($bd, $userId);
        if (empty($mappedProjects)) {
            $mappingRequired = true;
        }
    }

    if (!$loadError) {
        ensure_no_punch_review_table($bd);
    }

    $projectResult = $bd->query(
        'SELECT project_code, project_name FROM gcc_attendance_master.hrms_projects ORDER BY project_code'
    );
    if ($projectResult) {
        while ($row = $projectResult->fetch_assoc()) {
            $code = trim((string) ($row['project_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $projectOptions[$code] = trim((string) ($row['project_name'] ?? ''));
        }
        $projectResult->free();
    }

    $workResult = $bd->query(
        'SELECT wt_cd, wt_desc FROM gcc_attendance_master.work_type_master ORDER BY wt_desc, wt_cd'
    );
    if ($workResult) {
        while ($row = $workResult->fetch_assoc()) {
            $code = trim((string) ($row['wt_cd'] ?? ''));
            if ($code === '') {
                continue;
            }
            $workTypeOptions[$code] = trim((string) ($row['wt_desc'] ?? ''));
        }
        $workResult->free();
    }

    if (!$loadError) {
        // Use a DB-backed list so production can extend without code changes.
        ensure_no_punch_reason_table($bd);

        // Seed a minimal set of timekeeper-friendly reasons (idempotent).
        $bd->query(
            'INSERT IGNORE INTO gcc_attendance_master.attendance_no_punch_reasons (reason_code, reason_text, override_work_hours, override_work_code) VALUES ' .
            '("TIME_INCORRECT","Time Captured Incorrectly",NULL,NULL),' .
            '("NO_LUNCH","No Lunch",NULL,NULL),' .
            '("MISS_PUNCH","Miss Punch",NULL,NULL),' .
            '("NIGHT_SHIFT","Night Shift",NULL,NULL),' .
            '("NIGHT_DAY_SHIFT","Night Day Shift",NULL,NULL),' .
            '("COMP_OFF","Compensatory Off",NULL,NULL),' .
            '("OTH","Others",NULL,NULL)'
        );

        $reasonResult = $bd->query(
            'SELECT reason_code, reason_text FROM gcc_attendance_master.attendance_no_punch_reasons ' .
            'ORDER BY CASE WHEN UPPER(TRIM(reason_code)) = \'OTH\' THEN 1 ELSE 0 END, reason_text, reason_code'
        );
        if ($reasonResult) {
            while ($row = $reasonResult->fetch_assoc()) {
                $code = strtoupper(trim((string) ($row['reason_code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $noPunchReasonOptions[$code] = trim((string) ($row['reason_text'] ?? ''));
            }
            $reasonResult->free();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$mappingRequired) {
    $action = $_POST['action'] ?? '';
    $rowSave = $_POST['row_save'] ?? null;
    $rowSubmit = $_POST['row_submit'] ?? null;
    if ($rowSave !== null) {
        $action = 'row_save';
    } elseif ($rowSubmit !== null) {
        $action = 'row_submit';
    }
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        set_flash('warning', 'Invalid request token. Please try again.');
    } elseif ($action === 'row_save') {
        if (!ensure_attendance_override_table($bd)) {
            set_flash('warning', 'Override table not available.');
        } else {
            $empCodes = $_POST['emp_code'] ?? [];
            $attDates = $_POST['att_date'] ?? [];
            $hoursList = $_POST['work_hours'] ?? [];
            $codeList = $_POST['work_code'] ?? [];
            $reasonCodeList = $_POST['override_reason_code'] ?? [];
            $reasonNoteList = $_POST['override_reason_note'] ?? [];

            if (!is_array($empCodes)) {
                $empCodes = [$empCodes];
            }
            if (!is_array($attDates)) {
                $attDates = [$attDates];
            }
            if (!is_array($hoursList)) {
                $hoursList = [$hoursList];
            }
            if (!is_array($codeList)) {
                $codeList = [$codeList];
            }
            if (!is_array($reasonCodeList)) {
                $reasonCodeList = [$reasonCodeList];
            }
            if (!is_array($reasonNoteList)) {
                $reasonNoteList = [$reasonNoteList];
            }

            $i = is_scalar($rowSave) ? (int) $rowSave : -1;
            $empCode = trim((string) ($empCodes[$i] ?? ''));
            $attDate = trim((string) ($attDates[$i] ?? ''));
            if ($empCode === '' || $attDate === '') {
                set_flash('warning', 'Row data missing. Please refresh the page.');
            } else {
                // Lock after submission/review.
                $lockStmt = $bd->prepare(
                    'SELECT timekeeper_submitted_at, campboss_reason_code, campboss_reviewed_at, is_escalated ' .
                    'FROM gcc_attendance_master.attendance_no_punch_reviews WHERE emp_code = ? AND att_date = ? LIMIT 1'
                );
                $isLocked = false;
                if ($lockStmt) {
                    $lockStmt->bind_param('ss', $empCode, $attDate);
                    if ($lockStmt->execute()) {
                        $res = $lockStmt->get_result();
                        if ($res) {
                            $rev = $res->fetch_assoc() ?: null;
                            $res->free();
                            if ($rev) {
                                $submittedAt = trim((string) ($rev['timekeeper_submitted_at'] ?? ''));
                                $campbossReason = trim((string) ($rev['campboss_reason_code'] ?? ''));
                                $campbossReviewed = trim((string) ($rev['campboss_reviewed_at'] ?? ''));
                                $isEscalated = (int) ($rev['is_escalated'] ?? 0);
                                if ($submittedAt !== '' || $campbossReason !== '' || $campbossReviewed !== '' || $isEscalated === 1) {
                                    $isLocked = true;
                                }
                            }
                        }
                    }
                    $lockStmt->close();
                }

                if ($isLocked) {
                    set_flash('warning', 'This entry is already submitted to camp boss. Overrides are locked.');
                } else {
                    $approvedStmt = $bd->prepare(
                        'SELECT override_is_approved FROM gcc_attendance_master.employee_att_daily_overrides WHERE emp_code = ? AND att_date = ? LIMIT 1'
                    );
                    $isApproved = false;
                    if ($approvedStmt) {
                        $approvedStmt->bind_param('ss', $empCode, $attDate);
                        if ($approvedStmt->execute()) {
                            $res = $approvedStmt->get_result();
                            if ($res) {
                                $ov = $res->fetch_assoc() ?: null;
                                $res->free();
                                if ($ov && (int) ($ov['override_is_approved'] ?? 0) === 1) {
                                    $isApproved = true;
                                }
                            }
                        }
                        $approvedStmt->close();
                    }

                    if ($isApproved) {
                        set_flash('warning', 'This override is already approved and cannot be edited.');
                    } else {
                        $hoursRaw = trim((string) ($hoursList[$i] ?? ''));
                        $workCodeRaw = trim((string) ($codeList[$i] ?? ''));
                        $workCode = normalize_work_type_code($workCodeRaw);
                        $hours = null;

                        $reasonCodeRaw = strtoupper(trim((string) ($reasonCodeList[$i] ?? '')));
                        $reasonCode = $reasonCodeRaw !== '' ? $reasonCodeRaw : null;
                        $reasonNote = trim((string) ($reasonNoteList[$i] ?? ''));
                        if ($reasonNote !== '') {
                            $reasonNote = substr($reasonNote, 0, 255);
                        } else {
                            $reasonNote = null;
                        }

                        $errors = [];

                        if ($hoursRaw !== '') {
                            if (!is_numeric($hoursRaw)) {
                                $errors[] = 'Invalid hours.';
                            } else {
                                $hoursFloat = (float) $hoursRaw;
                                if ($hoursFloat < 0 || $hoursFloat > 24) {
                                    $errors[] = 'Hours must be between 0 and 24.';
                                } else {
                                    $hours = number_format($hoursFloat, 2, '.', '');
                                }
                            }
                        }

                        if ($hours !== null && $workCode !== null) {
                            $errors[] = 'Choose only one override (hours OR work code).';
                        }
                        if ($hours !== null && $reasonCode === null) {
                            $errors[] = 'Reason is required when overriding hours.';
                        }
                        if ($reasonCode !== null) {
                            if (empty($noPunchReasonOptions) || !isset($noPunchReasonOptions[$reasonCode])) {
                                $errors[] = 'Invalid reason.';
                            }
                        }
                        if ($workCode !== null) {
                            if (empty($workTypeOptions) || !isset($workTypeOptions[$workCode])) {
                                $errors[] = 'Invalid work code.';
                            }
                        }

                        if (!empty($errors)) {
                            set_flash('warning', implode(' ', $errors));
                        } else {
                            $insertSql = 'INSERT INTO `gcc_attendance_master`.`employee_att_daily_overrides` ' .
                                '(emp_code, att_date, override_work_hours, override_work_code, override_reason_code, override_reason_note, override_change_date, ' .
                                'override_changed_by_email, override_changed_by_name, override_is_approved, ' .
                                'override_approved_by_email, override_approved_by_name, override_approved_date) ' .
                                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
                                'ON DUPLICATE KEY UPDATE ' .
                                'override_work_hours = VALUES(override_work_hours), ' .
                                'override_work_code = VALUES(override_work_code), ' .
                                'override_reason_code = VALUES(override_reason_code), ' .
                                'override_reason_note = VALUES(override_reason_note), ' .
                                'override_change_date = VALUES(override_change_date), ' .
                                'override_changed_by_email = VALUES(override_changed_by_email), ' .
                                'override_changed_by_name = VALUES(override_changed_by_name), ' .
                                'override_is_approved = 0, ' .
                                'override_approved_by_email = NULL, ' .
                                'override_approved_by_name = NULL, ' .
                                'override_approved_date = NULL';

                            $insertStmt = $bd->prepare($insertSql);
                            $deleteStmt = $bd->prepare(
                                'DELETE FROM `gcc_attendance_master`.`employee_att_daily_overrides` WHERE emp_code = ? AND att_date = ?'
                            );

                            if ($hours === null && $workCode === null) {
                                if ($deleteStmt) {
                                    $deleteStmt->bind_param('ss', $empCode, $attDate);
                                    $deleteStmt->execute();
                                }
                                set_flash('success', 'Override cleared.');
                            } else {
                                $changeDate = gmdate('Y-m-d H:i:s');
                                $approved = 0;
                                $approvedByEmail = null;
                                $approvedByName = null;
                                $approvedDate = null;
                                $emailParam = $userEmail !== '' ? $userEmail : null;
                                $nameParam = $userName !== '' ? $userName : null;

                                if ($insertStmt) {
                                    $insertStmt->bind_param(
                                        'sssssssssisss',
                                        $empCode,
                                        $attDate,
                                        $hours,
                                        $workCode,
                                        $reasonCode,
                                        $reasonNote,
                                        $changeDate,
                                        $emailParam,
                                        $nameParam,
                                        $approved,
                                        $approvedByEmail,
                                        $approvedByName,
                                        $approvedDate
                                    );
                                    if ($insertStmt->execute()) {
                                        set_flash('success', 'Override saved.');
                                    } else {
                                        set_flash('warning', 'Unable to save override.');
                                    }
                                } else {
                                    set_flash('warning', 'Unable to save override.');
                                }
                            }

                            if ($insertStmt) {
                                $insertStmt->close();
                            }
                            if ($deleteStmt) {
                                $deleteStmt->close();
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'row_submit') {
        if (!ensure_no_punch_review_table($bd)) {
            set_flash('warning', 'Unable to submit to camp boss.');
        } else {
            $empCodes = $_POST['emp_code'] ?? [];
            $attDates = $_POST['att_date'] ?? [];
            $hoursList = $_POST['work_hours'] ?? [];
            $codeList = $_POST['work_code'] ?? [];

            if (!is_array($empCodes)) {
                $empCodes = [$empCodes];
            }
            if (!is_array($attDates)) {
                $attDates = [$attDates];
            }
            if (!is_array($hoursList)) {
                $hoursList = [$hoursList];
            }
            if (!is_array($codeList)) {
                $codeList = [$codeList];
            }

            $i = is_scalar($rowSubmit) ? (int) $rowSubmit : -1;
            $empCode = trim((string) ($empCodes[$i] ?? ''));
            $attDate = trim((string) ($attDates[$i] ?? ''));
            if ($empCode === '' || $attDate === '') {
                set_flash('warning', 'Row data missing. Please refresh the page.');
            } else {
                $hoursRaw = trim((string) ($hoursList[$i] ?? ''));
                $workCodeRaw = trim((string) ($codeList[$i] ?? ''));
                $workCode = normalize_work_type_code($workCodeRaw);
                if ($hoursRaw !== '' || $workCode !== null) {
                    set_flash('warning', 'Clear override hours/code before submitting to camp boss.');
                } else {
                    // Verify employee is active + within the timekeeper's mapped projects.
                    $jbno = null;
                    $empStmt = $bd->prepare(
                        'SELECT jbno, is_deleted, st_code FROM gcc_attendance_master.hrmsvw_sync WHERE emp_code = ? LIMIT 1'
                    );
                    if ($empStmt) {
                        $empStmt->bind_param('s', $empCode);
                        if ($empStmt->execute()) {
                            $res = $empStmt->get_result();
                            if ($res) {
                                $row = $res->fetch_assoc() ?: null;
                                $res->free();
                                if ($row) {
                                    $isDeleted = (int) ($row['is_deleted'] ?? 0);
                                    $stCode = trim((string) ($row['st_code'] ?? ''));
                                    $jbnoCandidate = trim((string) ($row['jbno'] ?? ''));
                                    if ($isDeleted !== 0 || strtoupper($stCode) !== 'A') {
                                        $jbno = null;
                                    } else {
                                        $jbno = $jbnoCandidate !== '' ? $jbnoCandidate : null;
                                    }
                                }
                            }
                        }
                        $empStmt->close();
                    }

                    if ($jbno === null || !in_array($jbno, $mappedProjects, true)) {
                        set_flash('warning', 'Employee not found or not mapped to your projects.');
                    } else {
                        // Block if already submitted/reviewed/escalated.
                        $lockStmt = $bd->prepare(
                            'SELECT timekeeper_submitted_at, campboss_reason_code, campboss_reviewed_at, is_escalated ' .
                            'FROM gcc_attendance_master.attendance_no_punch_reviews WHERE emp_code = ? AND att_date = ? LIMIT 1'
                        );
                        $isLocked = false;
                        if ($lockStmt) {
                            $lockStmt->bind_param('ss', $empCode, $attDate);
                            if ($lockStmt->execute()) {
                                $res = $lockStmt->get_result();
                                if ($res) {
                                    $rev = $res->fetch_assoc() ?: null;
                                    $res->free();
                                    if ($rev) {
                                        $submittedAt = trim((string) ($rev['timekeeper_submitted_at'] ?? ''));
                                        $campbossReason = trim((string) ($rev['campboss_reason_code'] ?? ''));
                                        $campbossReviewed = trim((string) ($rev['campboss_reviewed_at'] ?? ''));
                                        $isEscalated = (int) ($rev['is_escalated'] ?? 0);
                                        if ($submittedAt !== '' || $campbossReason !== '' || $campbossReviewed !== '' || $isEscalated === 1) {
                                            $isLocked = true;
                                        }
                                    }
                                }
                            }
                            $lockStmt->close();
                        }
                        if ($isLocked) {
                            set_flash('warning', 'This entry is already submitted to camp boss.');
                        } else {
                            // Must still be a no-punch entry.
                            $punchStmt = $bd->prepare(
                                'SELECT first_log, last_log FROM gcc_attendance_master.employee_daily_punch WHERE emp_code = ? AND punch_date = ? LIMIT 1'
                            );
                            $hasPunch = false;
                            if ($punchStmt) {
                                $punchStmt->bind_param('ss', $empCode, $attDate);
                                if ($punchStmt->execute()) {
                                    $res = $punchStmt->get_result();
                                    if ($res) {
                                        $p = $res->fetch_assoc() ?: null;
                                        $res->free();
                                        if ($p) {
                                            $firstLog = trim((string) ($p['first_log'] ?? ''));
                                            $lastLog = trim((string) ($p['last_log'] ?? ''));
                                            if ($firstLog !== '' || $lastLog !== '') {
                                                $hasPunch = true;
                                            }
                                        }
                                    }
                                }
                                $punchStmt->close();
                            }
                            if ($hasPunch) {
                                set_flash('warning', 'This employee has punch logs for the selected date.');
                            } else {
                                // Do not submit if an override exists.
                                if (!ensure_attendance_override_table($bd)) {
                                    set_flash('warning', 'Override table not available.');
                                } else {
                                    $ovStmt = $bd->prepare(
                                        'SELECT override_work_hours, override_work_code FROM gcc_attendance_master.employee_att_daily_overrides ' .
                                        'WHERE emp_code = ? AND att_date = ? LIMIT 1'
                                    );
                                    $hasOverride = false;
                                    if ($ovStmt) {
                                        $ovStmt->bind_param('ss', $empCode, $attDate);
                                        if ($ovStmt->execute()) {
                                            $res = $ovStmt->get_result();
                                            if ($res) {
                                                $ov = $res->fetch_assoc() ?: null;
                                                $res->free();
                                                if ($ov) {
                                                    $h = trim((string) ($ov['override_work_hours'] ?? ''));
                                                    $c = trim((string) ($ov['override_work_code'] ?? ''));
                                                    if ($h !== '' || $c !== '') {
                                                        $hasOverride = true;
                                                    }
                                                }
                                            }
                                        }
                                        $ovStmt->close();
                                    }
                                    if ($hasOverride) {
                                        set_flash('warning', 'This entry already has an override. Clear it before submitting to camp boss.');
                                    } else {
                                        $insertSql = 'INSERT INTO `gcc_attendance_master`.`attendance_no_punch_reviews` ' .
                                            '(emp_code, att_date, timekeeper_note, timekeeper_email, timekeeper_name, timekeeper_submitted_at) ' .
                                            'VALUES (?, ?, ?, ?, ?, ?) ' .
                                            'ON DUPLICATE KEY UPDATE ' .
                                            'timekeeper_note = VALUES(timekeeper_note), ' .
                                            'timekeeper_email = VALUES(timekeeper_email), ' .
                                            'timekeeper_name = VALUES(timekeeper_name), ' .
                                            'timekeeper_submitted_at = VALUES(timekeeper_submitted_at)';

                                        $timekeeperNote = null;
                                        $emailParam = $userEmail !== '' ? $userEmail : null;
                                        $nameParam = $userName !== '' ? $userName : null;
                                        $submittedAt = gmdate('Y-m-d H:i:s');

                                        $stmt = $bd->prepare($insertSql);
                                        if ($stmt) {
                                            $stmt->bind_param('ssssss', $empCode, $attDate, $timekeeperNote, $emailParam, $nameParam, $submittedAt);
                                            if ($stmt->execute()) {
                                                set_flash('success', 'Submitted to camp boss.');
                                            } else {
                                                set_flash('warning', 'Unable to submit entry to camp boss.');
                                            }
                                            $stmt->close();
                                        } else {
                                            set_flash('warning', 'Unable to submit entry to camp boss.');
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'save_overrides') {
        if (!ensure_attendance_override_table($bd)) {
            set_flash('warning', 'Override table not available.');
        } else {
            $empCodes = $_POST['emp_code'] ?? [];
            $attDates = $_POST['att_date'] ?? [];
            $hoursList = $_POST['work_hours'] ?? [];
            $codeList = $_POST['work_code'] ?? [];
            $reasonCodeList = $_POST['override_reason_code'] ?? [];
            $reasonNoteList = $_POST['override_reason_note'] ?? [];

            if (!is_array($empCodes)) {
                $empCodes = [$empCodes];
            }
            if (!is_array($attDates)) {
                $attDates = [$attDates];
            }
            if (!is_array($hoursList)) {
                $hoursList = [$hoursList];
            }
            if (!is_array($codeList)) {
                $codeList = [$codeList];
            }
            if (!is_array($reasonCodeList)) {
                $reasonCodeList = [$reasonCodeList];
            }
            if (!is_array($reasonNoteList)) {
                $reasonNoteList = [$reasonNoteList];
            }

            $max = max(
                count($empCodes),
                count($attDates),
                count($hoursList),
                count($codeList),
                count($reasonCodeList),
                count($reasonNoteList),
                1
            );
            $updated = 0;
            $deleted = 0;
            $skipped = 0;
            $errors = [];

            // Lock rows that were already submitted/reviewed/escalated (camp boss flow),
            // or overrides that were already approved (attendance approval flow).
            $locks = [];
            $approved = [];
            $uniqueEmp = [];
            for ($i = 0; $i < count($empCodes); $i++) {
                $emp = trim((string) ($empCodes[$i] ?? ''));
                $date = trim((string) ($attDates[$i] ?? ''));
                if ($emp === '' || $date === '') {
                    continue;
                }
                $uniqueEmp[$emp] = true;
            }
            $uniqueEmp = array_keys($uniqueEmp);
            if (!empty($uniqueEmp)) {
                $placeholders = implode(',', array_fill(0, count($uniqueEmp), '?'));
                $types2 = str_repeat('s', count($uniqueEmp)) . 's';
                $params2 = array_merge($uniqueEmp, [$selectedDate]);

                $reviewSql = 'SELECT emp_code, timekeeper_submitted_at, campboss_reason_code, campboss_reviewed_at, is_escalated ' .
                    'FROM gcc_attendance_master.attendance_no_punch_reviews ' .
                    'WHERE emp_code IN (' . $placeholders . ') AND att_date = ?';
                $stmt2 = $bd->prepare($reviewSql);
                if ($stmt2) {
                    bind_params($stmt2, $types2, $params2);
                    if ($stmt2->execute()) {
                        $res = $stmt2->get_result();
                        if ($res) {
                            while ($r = $res->fetch_assoc()) {
                                $emp = trim((string) ($r['emp_code'] ?? ''));
                                if ($emp === '') {
                                    continue;
                                }
                                $submittedAt = trim((string) ($r['timekeeper_submitted_at'] ?? ''));
                                $campbossReason = trim((string) ($r['campboss_reason_code'] ?? ''));
                                $campbossReviewed = trim((string) ($r['campboss_reviewed_at'] ?? ''));
                                $isEscalated = (int) ($r['is_escalated'] ?? 0);
                                if ($submittedAt !== '' || $campbossReason !== '' || $campbossReviewed !== '' || $isEscalated === 1) {
                                    $locks[$emp . '|' . $selectedDate] = true;
                                }
                            }
                            $res->free();
                        }
                    }
                    $stmt2->close();
                }

                $approvedSql = 'SELECT emp_code, override_is_approved FROM gcc_attendance_master.employee_att_daily_overrides ' .
                    'WHERE emp_code IN (' . $placeholders . ') AND att_date = ?';
                $stmt2 = $bd->prepare($approvedSql);
                if ($stmt2) {
                    bind_params($stmt2, $types2, $params2);
                    if ($stmt2->execute()) {
                        $res = $stmt2->get_result();
                        if ($res) {
                            while ($r = $res->fetch_assoc()) {
                                $emp = trim((string) ($r['emp_code'] ?? ''));
                                if ($emp === '') {
                                    continue;
                                }
                                if ((int) ($r['override_is_approved'] ?? 0) === 1) {
                                    $approved[$emp . '|' . $selectedDate] = true;
                                }
                            }
                            $res->free();
                        }
                    }
                    $stmt2->close();
                }
            }

            $insertSql = 'INSERT INTO `gcc_attendance_master`.`employee_att_daily_overrides` ' .
                '(emp_code, att_date, override_work_hours, override_work_code, override_reason_code, override_reason_note, override_change_date, ' .
                'override_changed_by_email, override_changed_by_name, override_is_approved, ' .
                'override_approved_by_email, override_approved_by_name, override_approved_date) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE ' .
                'override_work_hours = VALUES(override_work_hours), ' .
                'override_work_code = VALUES(override_work_code), ' .
                'override_reason_code = VALUES(override_reason_code), ' .
                'override_reason_note = VALUES(override_reason_note), ' .
                'override_change_date = VALUES(override_change_date), ' .
                'override_changed_by_email = VALUES(override_changed_by_email), ' .
                'override_changed_by_name = VALUES(override_changed_by_name), ' .
                'override_is_approved = 0, ' .
                'override_approved_by_email = NULL, ' .
                'override_approved_by_name = NULL, ' .
                'override_approved_date = NULL';

            $insertStmt = $bd->prepare($insertSql);
            $deleteStmt = $bd->prepare(
                'DELETE FROM `gcc_attendance_master`.`employee_att_daily_overrides` WHERE emp_code = ? AND att_date = ?'
            );

            $changeDate = gmdate('Y-m-d H:i:s');
            for ($i = 0; $i < $max; $i++) {
                $empCode = trim((string) ($empCodes[$i] ?? ''));
                $attDate = trim((string) ($attDates[$i] ?? ''));
                if ($empCode === '' || $attDate === '') {
                    continue;
                }

                $key = $empCode . '|' . $attDate;
                if (isset($locks[$key]) || isset($approved[$key])) {
                    $skipped++;
                    continue;
                }

                $hoursRaw = trim((string) ($hoursList[$i] ?? ''));
                $workCodeRaw = trim((string) ($codeList[$i] ?? ''));
                $workCode = normalize_work_type_code($workCodeRaw);
                $hours = null;

                $reasonCodeRaw = strtoupper(trim((string) ($reasonCodeList[$i] ?? '')));
                $reasonCode = $reasonCodeRaw !== '' ? $reasonCodeRaw : null;
                $reasonNote = trim((string) ($reasonNoteList[$i] ?? ''));
                if ($reasonNote !== '') {
                    $reasonNote = substr($reasonNote, 0, 255);
                } else {
                    $reasonNote = null;
                }

                if ($hoursRaw !== '') {
                    if (!is_numeric($hoursRaw)) {
                        $errors[] = 'Invalid hours for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                    $hoursFloat = (float) $hoursRaw;
                    if ($hoursFloat < 0 || $hoursFloat > 24) {
                        $errors[] = 'Hours must be between 0 and 24 for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                    $hours = number_format($hoursFloat, 2, '.', '');
                }

                // If work hours are overridden, a reason must be selected.
                if ($hours !== null && $reasonCode === null) {
                    $errors[] = 'Reason is required when overriding hours for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
                }

                if ($reasonCode !== null) {
                    if (empty($noPunchReasonOptions)) {
                        $errors[] = 'Reason list not available for validation.';
                        continue;
                    }
                    if (!isset($noPunchReasonOptions[$reasonCode])) {
                        $errors[] = 'Invalid reason "' . $reasonCode . '" for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                }

                if ($workCode !== null) {
                    if (empty($workTypeOptions)) {
                        $errors[] = 'Work code list not available for validation.';
                        continue;
                    }
                    if (!isset($workTypeOptions[$workCode])) {
                        $errors[] = 'Invalid work code "' . $workCode . '" for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                }

                if ($hours !== null && $workCode !== null) {
                    $errors[] = 'Choose only one override (hours OR work code) for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
                }

                if ($hours === null && $workCode === null) {
                    if ($deleteStmt) {
                        $deleteStmt->bind_param('ss', $empCode, $attDate);
                        if ($deleteStmt->execute()) {
                            $deleted++;
                        }
                    }
                    continue;
                }

                $approved = 0;
                $approvedByEmail = null;
                $approvedByName = null;
                $approvedDate = null;
                $emailParam = $userEmail !== '' ? $userEmail : null;
                $nameParam = $userName !== '' ? $userName : null;

                if ($insertStmt) {
                    $insertStmt->bind_param(
                        'sssssssssisss',
                        $empCode,
                        $attDate,
                        $hours,
                        $workCode,
                        $reasonCode,
                        $reasonNote,
                        $changeDate,
                        $emailParam,
                        $nameParam,
                        $approved,
                        $approvedByEmail,
                        $approvedByName,
                        $approvedDate
                    );
                    if ($insertStmt->execute()) {
                        $updated++;
                    } else {
                        $errors[] = 'Unable to save override for ' . $empCode . ' on ' . $attDate . '.';
                    }
                }
            }

            if ($insertStmt) {
                $insertStmt->close();
            }
            if ($deleteStmt) {
                $deleteStmt->close();
            }

            if (!empty($errors)) {
                set_flash('warning', implode(' ', $errors));
            } else {
                $message = 'Overrides saved.';
                if ($updated > 0) {
                    $message = 'Updated ' . $updated . ' override(s).';
                }
                if ($deleted > 0) {
                    $message .= ' Cleared ' . $deleted . ' override(s).';
                }
                if ($skipped > 0) {
                    $message .= ' Skipped ' . $skipped . ' locked row(s).';
                }
                set_flash('success', $message);
            }
        }
    } elseif ($action === 'submit_to_campboss') {
        if (!ensure_no_punch_review_table($bd)) {
            set_flash('warning', 'Unable to submit to camp boss.');
        } else {
            if (!empty($projectFilter)) {
                $projectFilter = array_values(array_intersect($projectFilter, $mappedProjects));
            }
            if (empty($projectFilter)) {
                $projectFilter = $mappedProjects;
            }

            if (empty($projectFilter)) {
                set_flash('warning', 'No mapped projects available.');
            } else {
                $params = [];
                $types = '';

                $insertSql = 'INSERT INTO `gcc_attendance_master`.`attendance_no_punch_reviews` ' .
                    '(emp_code, att_date, timekeeper_note, timekeeper_email, timekeeper_name, timekeeper_submitted_at) ' .
                    'SELECT hr.emp_code, ?, ?, ?, ?, ? ' .
                    'FROM gcc_attendance_master.hrmsvw_sync hr ' .
                    'LEFT JOIN gcc_attendance_master.employee_daily_punch dp ON dp.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci ' .
                    'AND dp.punch_date = ? ' .
                    'LEFT JOIN gcc_attendance_master.employee_att_daily_overrides o ON o.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci ' .
                    'AND o.att_date = ? ' .
                    'LEFT JOIN gcc_attendance_master.attendance_no_punch_reviews r ON r.emp_code COLLATE utf8mb4_general_ci = hr.emp_code COLLATE utf8mb4_general_ci ' .
                    'AND r.att_date = ? ' .
                    'WHERE hr.is_deleted = 0 AND hr.st_code = "A" ' .
                    'AND hr.jbno IN (' . implode(',', array_fill(0, count($projectFilter), '?')) . ') ' .
                    'AND (dp.emp_code IS NULL OR (dp.first_log IS NULL AND dp.last_log IS NULL)) ' .
                    'AND (o.override_work_hours IS NULL AND o.override_work_code IS NULL) ' .
                    'AND r.emp_code IS NULL';

                $timekeeperNote = null;
                $emailParam = $userEmail !== '' ? $userEmail : null;
                $nameParam = $userName !== '' ? $userName : null;
                $submittedAt = gmdate('Y-m-d H:i:s');

                $params[] = $selectedDate;
                $params[] = $timekeeperNote;
                $params[] = $emailParam;
                $params[] = $nameParam;
                $params[] = $submittedAt;
                $params[] = $selectedDate;
                $params[] = $selectedDate;
                $params[] = $selectedDate;
                $types .= 'ssssssss';

                foreach ($projectFilter as $code) {
                    $params[] = $code;
                    $types .= 's';
                }

                $stmt = $bd->prepare($insertSql);
                if ($stmt) {
                    bind_params($stmt, $types, $params);
                    if ($stmt->execute()) {
                        $count = $stmt->affected_rows;
                        set_flash('success', 'Submitted ' . max(0, $count) . ' employee(s) to camp boss.');
                    } else {
                        set_flash('warning', 'Unable to submit entries to camp boss.');
                    }
                    $stmt->close();
                } else {
                    set_flash('warning', 'Unable to submit entries to camp boss.');
                }
            }
        }
    }

    $redirectParams = [
        'date' => $selectedDate,
    ];
    if (!empty($projectFilter)) {
        $redirectParams['project_code'] = $projectFilter;
    }
    if ($searchInput !== '') {
        $redirectParams['search'] = $searchInput;
    }
    header('Location: ' . build_query_url($redirectParams));
    exit;
}

if (!$loadError && !$mappingRequired) {
    if (!empty($projectFilter)) {
        $projectFilter = array_values(array_intersect($projectFilter, $mappedProjects));
    }
    if (!empty($mappedProjects)) {
        $mappedSet = array_fill_keys($mappedProjects, true);
        $projectOptions = array_intersect_key($projectOptions, $mappedSet);
    }

    $filters = ['hr.is_deleted = 0', 'hr.st_code = "A"'];
    $params = [];
    $types = '';

    if (!empty($mappedProjects)) {
        $placeholders = implode(',', array_fill(0, count($mappedProjects), '?'));
        $filters[] = 'hr.jbno IN (' . $placeholders . ')';
        $params = array_merge($params, $mappedProjects);
        $types .= str_repeat('s', count($mappedProjects));
    }
    if (!empty($projectFilter)) {
        $placeholders = implode(',', array_fill(0, count($projectFilter), '?'));
        $filters[] = 'hr.jbno IN (' . $placeholders . ')';
        $params = array_merge($params, $projectFilter);
        $types .= str_repeat('s', count($projectFilter));
    }
    if (!empty($searchTerms)) {
        $likeParts = [];
        foreach ($searchTerms as $term) {
            $likeParts[] = '(hr.emp_code LIKE ? OR hr.emp_name LIKE ? OR hr.name LIKE ?)';
            $like = '%' . $term . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }
        $filters[] = '(' . implode(' AND ', $likeParts) . ')';
    }

    $sql = 'SELECT hr.emp_code, COALESCE(NULLIF(hr.emp_name, ""), NULLIF(hr.name, "")) AS emp_name, ' .
        'hr.desg_name, hr.dept_name, hr.jbno, hr.jbdesc ' .
        'FROM gcc_attendance_master.hrmsvw_sync hr ' .
        'WHERE ' . implode(' AND ', $filters) .
        ' ORDER BY CAST(hr.emp_code AS UNSIGNED), hr.emp_code';

    $employees = [];
    $stmt = $bd->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $employees[] = $row;
                }
                $result->free();
            }
        } else {
            $loadError = 'Unable to load employees.';
        }
        $stmt->close();
    } else {
        $loadError = 'Unable to prepare employee query.';
    }

    if (!$loadError && !empty($employees)) {
        $empCodes = [];
        foreach ($employees as $row) {
            $code = trim((string) ($row['emp_code'] ?? ''));
            if ($code !== '') {
                $empCodes[] = $code;
            }
        }
        $empCodes = array_values(array_unique($empCodes));

        $dailyPunch = [];
        $overrides = [];
        $reviews = [];

        if (!empty($empCodes)) {
            $placeholders = implode(',', array_fill(0, count($empCodes), '?'));
            $rangeTypes = str_repeat('s', count($empCodes)) . 's';
            $rangeParams = array_merge($empCodes, [$selectedDate]);

            $punchSql = 'SELECT emp_code, first_log, last_log ' .
                'FROM gcc_attendance_master.employee_daily_punch ' .
                'WHERE emp_code IN (' . $placeholders . ') AND punch_date = ?';
            $stmt = $bd->prepare($punchSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            if ($emp === '') {
                                continue;
                            }
                            $dailyPunch[$emp] = $row;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }

            $overrideSql = 'SELECT emp_code, override_work_hours, override_work_code, override_reason_code, override_reason_note, override_is_approved ' .
                'FROM gcc_attendance_master.employee_att_daily_overrides ' .
                'WHERE emp_code IN (' . $placeholders . ') AND att_date = ?';
            $stmt = $bd->prepare($overrideSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            if ($emp === '') {
                                continue;
                            }
                            $overrides[$emp] = $row;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }

            $reviewSql = 'SELECT emp_code, timekeeper_submitted_at, campboss_reason_code, campboss_reviewed_at, is_escalated ' .
                'FROM gcc_attendance_master.attendance_no_punch_reviews ' .
                'WHERE emp_code IN (' . $placeholders . ') AND att_date = ?';
            $stmt = $bd->prepare($reviewSql);
            if ($stmt) {
                bind_params($stmt, $rangeTypes, $rangeParams);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $emp = trim((string) ($row['emp_code'] ?? ''));
                            if ($emp === '') {
                                continue;
                            }
                            $reviews[$emp] = $row;
                        }
                        $result->free();
                    }
                }
                $stmt->close();
            }
        }

        foreach ($employees as $employee) {
            $empCode = trim((string) ($employee['emp_code'] ?? ''));
            if ($empCode === '') {
                continue;
            }
            $punchRow = $dailyPunch[$empCode] ?? null;
            $hasPunch = false;
            if ($punchRow) {
                $firstLog = trim((string) ($punchRow['first_log'] ?? ''));
                $lastLog = trim((string) ($punchRow['last_log'] ?? ''));
                if ($firstLog !== '' || $lastLog !== '') {
                    $hasPunch = true;
                }
            }
            if ($hasPunch) {
                continue;
            }

            $override = $overrides[$empCode] ?? [];
            $review = $reviews[$empCode] ?? [];

            $overrideHours = trim((string) ($override['override_work_hours'] ?? ''));
            $overrideCode = trim((string) ($override['override_work_code'] ?? ''));
            $overrideReasonCode = strtoupper(trim((string) ($override['override_reason_code'] ?? '')));
            $overrideReasonNote = trim((string) ($override['override_reason_note'] ?? ''));
            $overrideStatus = (int) ($override['override_is_approved'] ?? 0);

            $submittedAt = trim((string) ($review['timekeeper_submitted_at'] ?? ''));
            $campbossReason = trim((string) ($review['campboss_reason_code'] ?? ''));
            $campbossReviewed = trim((string) ($review['campboss_reviewed_at'] ?? ''));
            $isEscalated = (int) ($review['is_escalated'] ?? 0);

            if ($overrideHours === '' && $overrideCode === '' && $submittedAt === '') {
                $remainingCount++;
            }

            $rows[] = [
                'emp_code' => $empCode,
                'emp_name' => trim((string) ($employee['emp_name'] ?? '')),
                'designation' => trim((string) ($employee['desg_name'] ?? '')),
                'department' => trim((string) ($employee['dept_name'] ?? '')),
                'project_code' => trim((string) ($employee['jbno'] ?? '')),
                'project_name' => trim((string) ($employee['jbdesc'] ?? '')),
                'override_hours' => $overrideHours,
                'override_code' => $overrideCode,
                'override_reason_code' => $overrideReasonCode,
                'override_reason_note' => $overrideReasonNote,
                'override_status' => $overrideStatus,
                'submitted_at' => $submittedAt,
                'campboss_reason' => $campbossReason,
                'campboss_reviewed' => $campbossReviewed,
                'is_escalated' => $isEscalated,
            ];
        }
    }
}

include __DIR__ . '/../admin/include/layout_top.php';

?>

<style>
  .no-punch-card {
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
  }
  .no-punch-table th,
  .no-punch-table td {
    vertical-align: middle;
  }
  .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(15, 23, 42, 0.08);
  }
  .status-pill.is-pending { background: rgba(251, 191, 36, 0.2); color: #92400e; }
  .status-pill.is-approved { background: rgba(34, 197, 94, 0.2); color: #166534; }
  .status-pill.is-submitted { background: rgba(59, 130, 246, 0.18); color: #1d4ed8; }
  .status-pill.is-reviewed { background: rgba(14, 165, 233, 0.18); color: #0369a1; }
  .status-pill.is-escalated { background: rgba(239, 68, 68, 0.2); color: #b91c1c; }
  .no-punch-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>No Punch Daily</h1>
      </div>
      <div class="col-sm-6 text-sm-right"></div>
    </div>
    <?php $nav_mode = 'timekeeper'; include dirname(__DIR__) . '/admin/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($loadError): ?>
      <div class="alert alert-warning mb-3"><?= h($loadError) ?></div>
    <?php endif; ?>

    <?php if ($mappingRequired): ?>
      <div class="card no-punch-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Project access needed</h3>
        </div>
        <div class="card-body">
          <p class="text-muted mb-2">You do not have project access yet. Submit a request to the admin.</p>
          <a class="btn btn-primary" href="<?= h(admin_url('timekeeper_project_request.php')) ?>">Request access</a>
        </div>
      </div>
    <?php else: ?>
      <div class="card no-punch-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Filters</h3>
        </div>
        <div class="card-body">
          <form method="get" class="form-row">
            <div class="form-group col-md-3">
              <label for="date">Date (UAE)</label>
              <input id="date" name="date" type="date" class="form-control" value="<?= h($selectedDate) ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="project_code">Project</label>
              <select id="project_code" name="project_code[]" class="form-control js-searchable" multiple data-placeholder="All projects">
                <?php foreach ($projectOptions as $code => $name): ?>
                  <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                  <option value="<?= h($code) ?>" <?= in_array($code, $projectFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-3">
              <label for="search">Search</label>
              <input id="search" name="search" class="form-control" value="<?= h($searchInput) ?>" placeholder="Emp code or name">
            </div>
            <div class="form-group col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-primary btn-block">Apply</button>
            </div>
          </form>
          <div class="small text-muted">Remaining without overrides: <?= h((string) $remainingCount) ?></div>
        </div>
      </div>

      <div class="card no-punch-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">Employees with no punch</h3>
          <div class="no-punch-actions">
            <span class="text-muted small"><?= h(count($rows)) ?> records</span>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <table class="table table-bordered table-sm no-punch-table mb-0">
              <thead>
                <tr>
                  <th>Emp Code</th>
                  <th>Name</th>
                  <th>Designation</th>
                  <th>Department</th>
                  <th>Project</th>
                  <th>Override hours</th>
                  <th>Override code</th>
                  <th>Reason</th>
                  <th>Override status</th>
                  <th>Camp boss</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr>
                    <td colspan="10" class="text-center text-muted p-4">No no-punch employees for this date.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rows as $rowIndex => $row): ?>
                    <?php
                      $overrideStatus = (int) ($row['override_status'] ?? 0);
                      $overrideLabel = 'Not set';
                      $overrideClass = '';
                      if ($overrideStatus === 1) {
                          $overrideLabel = 'Approved';
                          $overrideClass = 'is-approved';
                      } elseif ($overrideStatus === 2) {
                          $overrideLabel = 'Rejected';
                          $overrideClass = 'is-escalated';
                      } elseif (($row['override_hours'] ?? '') !== '' || ($row['override_code'] ?? '') !== '') {
                          $overrideLabel = 'Pending';
                          $overrideClass = 'is-pending';
                      }

                      $campLabel = 'Not submitted';
                      $campClass = '';
                      if ((int) ($row['is_escalated'] ?? 0) === 1) {
                          $campLabel = 'Escalated';
                          $campClass = 'is-escalated';
                      } elseif (($row['campboss_reviewed'] ?? '') !== '' || ($row['campboss_reason'] ?? '') !== '') {
                          $campLabel = 'Reviewed';
                          $campClass = 'is-reviewed';
                      } elseif (($row['submitted_at'] ?? '') !== '') {
                          $campLabel = 'Submitted';
                          $campClass = 'is-submitted';
                      }

                      $isSubmitted = ($campLabel !== 'Not submitted');
                      $lockInputs = ($overrideStatus === 1) || $isSubmitted;
                      $canSubmitRow = (!$isSubmitted)
                        && !$lockInputs
                        && trim((string) ($row['override_hours'] ?? '')) === ''
                        && trim((string) ($row['override_code'] ?? '')) === '';
                    ?>
                    <tr>
                      <td>
                        <?= h($row['emp_code']) ?>
                        <input type="hidden" name="emp_code[]" value="<?= h($row['emp_code']) ?>">
                        <input type="hidden" name="att_date[]" value="<?= h($selectedDate) ?>">
                      </td>
                      <td><?= h($row['emp_name']) ?></td>
                      <td><?= h($row['designation']) ?></td>
                      <td><?= h($row['department']) ?></td>
                      <td><?= h($row['project_code']) ?></td>
                      <td>
                        <input type="number" step="0.01" min="0" max="24" class="form-control form-control-sm" name="work_hours[]" placeholder="8.00" value="<?= h($row['override_hours']) ?>" <?= $lockInputs ? 'readonly' : '' ?>>
                      </td>
                      <td>
                        <input
                          type="text"
                          class="form-control form-control-sm js-work-code"
                          name="work_code[]"
                          maxlength="10"
                          autocomplete="off"
                          placeholder="Work code"
                          value="<?= h($row['override_code'] ?? '') ?>"
                          <?= $lockInputs ? 'readonly' : '' ?>
                        >
                      </td>
                      <td>
                        <select
                          class="form-control form-control-sm js-override-reason"
                          name="override_reason_code[]"
                          <?= $lockInputs ? 'disabled' : '' ?>
                          title=""
                        >
                          <option value="">-- Select --</option>
                          <?php foreach ($noPunchReasonOptions as $code => $text): ?>
                            <?php $selected = strtoupper((string) ($row['override_reason_code'] ?? '')) === $code; ?>
                            <option value="<?= h($code) ?>" data-desc="<?= h($text) ?>" <?= $selected ? 'selected' : '' ?>>
                              <?= h($code . ' - ' . $text) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <?php if ($lockInputs): ?>
                          <input type="hidden" name="override_reason_code[]" value="<?= h($row['override_reason_code'] ?? '') ?>">
                        <?php endif; ?>
                        <input
                          type="text"
                          class="form-control form-control-sm mt-1"
                          name="override_reason_note[]"
                          maxlength="255"
                          placeholder="Note (optional)"
                          value="<?= h($row['override_reason_note'] ?? '') ?>"
                          <?= $lockInputs ? 'readonly' : '' ?>
                        >
                      </td>
                      <td>
                        <?php if ($isSubmitted): ?>
                          <span class="text-muted">-</span>
                        <?php else: ?>
                          <button type="submit" name="row_save" value="<?= (int) $rowIndex ?>" class="btn btn-sm btn-primary" <?= $lockInputs ? 'disabled' : '' ?>>Save</button>
                          <div class="mt-1">
                            <span class="status-pill <?= h($overrideClass) ?>"><?= h($overrideLabel) ?></span>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($isSubmitted): ?>
                          <span class="status-pill <?= h($campClass) ?>"><?= h($campLabel) ?></span>
                        <?php else: ?>
                          <button
                            type="submit"
                            name="row_submit"
                            value="<?= (int) $rowIndex ?>"
                            class="btn btn-sm btn-outline-secondary"
                            <?= $canSubmitRow ? '' : 'disabled' ?>
                            title="<?= $canSubmitRow ? '' : 'Clear overrides before submitting.' ?>"
                          >Submit</button>
                          <div class="mt-1">
                            <span class="status-pill <?= h($campClass) ?>"><?= h($campLabel) ?></span>
                          </div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            <div class="p-3 d-flex flex-wrap gap-2">
              <button type="submit" name="action" value="save_overrides" class="btn btn-primary">Save adjustments</button>
              <button type="submit" name="action" value="submit_to_campboss" class="btn btn-outline-secondary">Submit remaining to camp boss</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
  // Used by the shared work-code autocomplete in `admin/include/layout_bottom.php`.
  window.WORK_CODE_OPTIONS = <?= json_encode($workTypeOptions, JSON_UNESCAPED_SLASHES) ?>;

  (function () {
    function setSelectTitle(selectEl) {
      if (!selectEl) return;
      var opt = selectEl.options[selectEl.selectedIndex];
      if (!opt) return;
      var desc = opt.getAttribute('data-desc') || '';
      selectEl.title = desc;
    }

    function validateOverrideRow(tr) {
      if (!tr) return true;
      var hours = tr.querySelector('input[name=\"work_hours[]\"]');
      var code = tr.querySelector('input[name=\"work_code[]\"]');
      var reason = tr.querySelector('select[name=\"override_reason_code[]\"]');
      if (!hours || !code || !reason) return true;

      var hoursVal = (hours.value || '').trim();
      var codeVal = (code.value || '').trim();

      if (hoursVal !== '' && codeVal !== '') {
        alert('Choose only one override per row: work hours OR work code (not both).');
        code.focus();
        return false;
      }

      if (hoursVal !== '') {
        var n = Number(hoursVal);
        if (!isFinite(n)) {
          alert('Invalid hours. Please enter a number between 0 and 24.');
          hours.focus();
          return false;
        }
        if (n < 0 || n > 24) {
          alert('Hours must be between 0 and 24.');
          hours.focus();
          return false;
        }
        if ((reason.value || '').trim() === '') {
          alert('Reason is required when overriding hours. Please select a reason.');
          reason.focus();
          return false;
        }
      }

      return true;
    }

    function validateSubmitRow(tr) {
      if (!tr) return true;
      var hours = tr.querySelector('input[name=\"work_hours[]\"]');
      var code = tr.querySelector('input[name=\"work_code[]\"]');
      if (!hours || !code) return true;

      var hoursVal = (hours.value || '').trim();
      var codeVal = (code.value || '').trim();
      if (hoursVal !== '' || codeVal !== '') {
        alert('To submit to camp boss, clear override hours and override code first.');
        if (hoursVal !== '') {
          hours.focus();
        } else {
          code.focus();
        }
        return false;
      }

      return true;
    }

    var form = document.querySelector('form[method=\"post\"]');
    if (form) {
      form.addEventListener('submit', function (e) {
        var submitter = e.submitter || document.activeElement;
        if (!submitter) return;

        if (submitter.name === 'action' && submitter.value === 'save_overrides') {
          var rows = form.querySelectorAll('tbody tr');
          for (var i = 0; i < rows.length; i++) {
            if (!validateOverrideRow(rows[i])) {
              e.preventDefault();
              return;
            }
          }
          return;
        }

        if (submitter.name === 'row_save') {
          var tr = submitter.closest('tr');
          if (!validateOverrideRow(tr)) {
            e.preventDefault();
          }
          return;
        }

        if (submitter.name === 'row_submit') {
          var tr = submitter.closest('tr');
          if (!validateSubmitRow(tr)) {
            e.preventDefault();
          }
          return;
        }
      });
    }

    // UX: keep inputs mutually exclusive while editing.
    var dataRows = document.querySelectorAll('tbody tr');
    for (var k = 0; k < dataRows.length; k++) {
      var row = dataRows[k];
      var hoursInput = row.querySelector('input[name=\"work_hours[]\"]');
      var codeInput = row.querySelector('input[name=\"work_code[]\"]');
      if (!hoursInput || !codeInput) continue;
      (function (hoursEl, codeEl) {
        hoursEl.addEventListener('input', function () {
          if ((hoursEl.value || '').trim() !== '') {
            codeEl.value = '';
            codeEl.title = '';
          }
        });
        codeEl.addEventListener('input', function () {
          if ((codeEl.value || '').trim() !== '') {
            hoursEl.value = '';
          }
        });
      })(hoursInput, codeInput);
    }

    var reasonSelects = document.querySelectorAll('select.js-override-reason');
    for (var j = 0; j < reasonSelects.length; j++) {
      (function (sel) {
        setSelectTitle(sel);
        sel.addEventListener('change', function () { setSelectTitle(sel); });
      })(reasonSelects[j]);
    }

  })();
</script>

<?php include __DIR__ . '/../admin/include/layout_bottom.php'; ?>
