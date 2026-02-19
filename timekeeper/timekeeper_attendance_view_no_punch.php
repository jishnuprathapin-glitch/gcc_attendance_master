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

function load_employee_type_map(mysqli $bd, array $empCodes): array {
    $typeMap = [];
    $cleanCodes = [];
    foreach ($empCodes as $empCode) {
        $empCode = trim((string) $empCode);
        if ($empCode === '') {
            continue;
        }
        $cleanCodes[$empCode] = true;
    }
    $cleanCodes = array_keys($cleanCodes);
    if (empty($cleanCodes)) {
        return $typeMap;
    }

    $placeholders = implode(',', array_fill(0, count($cleanCodes), '?'));
    $types = str_repeat('s', count($cleanCodes));
    $sql = 'SELECT emp_code, ty_cd FROM gcc_attendance_master.hrmsvw_sync WHERE emp_code IN (' . $placeholders . ')';
    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return $typeMap;
    }
    bind_params($stmt, $types, $cleanCodes);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $empCode = trim((string) ($row['emp_code'] ?? ''));
                if ($empCode === '') {
                    continue;
                }
                $typeMap[$empCode] = strtoupper(trim((string) ($row['ty_cd'] ?? '')));
            }
            $result->free();
        }
    }
    $stmt->close();
    return $typeMap;
}

function apply_reason_defaults(array $reasonMeta, bool $isStaff, ?string &$hours, ?string &$workCode): void {
    $defaultHours = derive_reason_default_hours($reasonMeta, $isStaff);
    if ($defaultHours !== null) {
        $hours = $defaultHours;
        $workCode = null;
        return;
    }

    $behavior = strtoupper(trim((string) ($reasonMeta['default_behavior'] ?? 'NONE')));
    if ($behavior === 'WORK_CODE') {
        $defaultWorkCode = normalize_work_type_code($reasonMeta['default_work_code'] ?? null);
        if ($defaultWorkCode !== null) {
            $workCode = $defaultWorkCode;
            $hours = null;
        }
    }
}

function is_timekeeper_sick_leave_reason(?string $reasonCode): bool {
    return strtoupper(trim((string) $reasonCode)) === 'SICK';
}

$uaeTz = new DateTimeZone('Asia/Dubai');
$todayUae = (new DateTimeImmutable('now', $uaeTz))->format('Y-m-d');

$selectedDate = normalize_date($_GET['date'] ?? '', $todayUae);
$projectFilter = normalize_multi_param($_GET['project_code'] ?? []);
$searchInput = trim((string) ($_GET['search'] ?? ''));
$searchTerms = normalize_search_terms($searchInput);
$overrideStatusFilter = strtolower(trim((string) ($_GET['override_status'] ?? 'all')));
$campbossStatusFilter = strtolower(trim((string) ($_GET['campboss_status'] ?? 'all')));
$validOverrideStatuses = ['all' => true, 'pending' => true, 'approved' => true, 'rejected' => true, 'not_set' => true];
$validCampbossStatuses = ['all' => true, 'submitted' => true, 'reviewed' => true, 'escalated' => true, 'not_submitted' => true];
if (!isset($validOverrideStatuses[$overrideStatusFilter])) {
    $overrideStatusFilter = 'all';
}
if (!isset($validCampbossStatuses[$campbossStatusFilter])) {
    $campbossStatusFilter = 'all';
}

$userId = trim((string) ($_SESSION['user_id'] ?? ''));
$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$loadError = null;
$mappingRequired = false;
$mappedProjects = [];
$projectOptions = [];
$workTypeOptions = [];
$noPunchReasonOptions = [];
$noPunchReasonMeta = [];
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
        ensure_attendance_medical_certificate_table($bd);
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

    if (!$loadError) {
        ensure_no_punch_reason_table($bd);
        $workTypeOptions = load_work_type_options($bd);
        $noPunchReasonMeta = load_no_punch_reason_options($bd, 'timekeeper');
        foreach ($noPunchReasonMeta as $reasonCode => $meta) {
            $noPunchReasonOptions[$reasonCode] = trim((string) ($meta['text'] ?? ''));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$mappingRequired) {
    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    $isAjaxRequest = (trim((string) ($_POST['ajax'] ?? '')) === '1') || ($requestedWith === 'xmlhttprequest');
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
        if (!ensure_attendance_override_table($bd) || !ensure_attendance_medical_certificate_table($bd)) {
            set_flash('warning', 'Override/medical certificate table not available.');
        } else {
            $empCodes = $_POST['emp_code'] ?? [];
            $attDates = $_POST['att_date'] ?? [];
            $hoursList = $_POST['work_hours'] ?? [];
            $codeList = $_POST['work_code'] ?? [];
            $reasonCodeList = $_POST['override_reason_code'] ?? [];
            $reasonNoteList = $_POST['override_reason_note'] ?? [];
            $medicalTargetIndex = is_scalar($_POST['medical_target_index'] ?? null)
                ? (int) $_POST['medical_target_index']
                : -1;
            $medicalPopupNote = trim((string) ($_POST['medical_popup_note'] ?? ''));
            $medicalUpload = $_FILES['medical_certificate_file'] ?? null;

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
                        'SELECT override_work_hours, override_work_code, override_is_approved ' .
                        'FROM gcc_attendance_master.employee_att_daily_overrides WHERE emp_code = ? AND att_date = ? LIMIT 1'
                    );
                    $isApproved = false;
                    $hasExistingHrOverride = false;
                    if ($approvedStmt) {
                        $approvedStmt->bind_param('ss', $empCode, $attDate);
                        if ($approvedStmt->execute()) {
                            $res = $approvedStmt->get_result();
                            if ($res) {
                                $ov = $res->fetch_assoc() ?: null;
                                $res->free();
                                if ($ov) {
                                    $existingHours = trim((string) ($ov['override_work_hours'] ?? ''));
                                    $existingCode = trim((string) ($ov['override_work_code'] ?? ''));
                                    if ($existingHours !== '' || $existingCode !== '') {
                                        $hasExistingHrOverride = true;
                                    }
                                    if ((int) ($ov['override_is_approved'] ?? 0) === 1) {
                                        $isApproved = true;
                                    }
                                }
                            }
                        }
                        $approvedStmt->close();
                    }

                    if ($isApproved) {
                        set_flash('warning', 'This override is already approved and cannot be edited.');
                    } elseif ($hasExistingHrOverride) {
                        set_flash('warning', 'This entry is already sent to HR and cannot be re-sent.');
                    } else {
                        $employeeTypeCode = null;
                        $typeStmt = $bd->prepare(
                            'SELECT ty_cd FROM gcc_attendance_master.hrmsvw_sync WHERE emp_code = ? LIMIT 1'
                        );
                        if ($typeStmt) {
                            $typeStmt->bind_param('s', $empCode);
                            if ($typeStmt->execute()) {
                                $typeResult = $typeStmt->get_result();
                                if ($typeResult) {
                                    $typeRow = $typeResult->fetch_assoc() ?: null;
                                    $typeResult->free();
                                    if ($typeRow) {
                                        $employeeTypeCode = strtoupper(trim((string) ($typeRow['ty_cd'] ?? '')));
                                    }
                                }
                            }
                            $typeStmt->close();
                        }

                        $hoursRaw = trim((string) ($hoursList[$i] ?? ''));
                        $workCodeRaw = trim((string) ($codeList[$i] ?? ''));
                        $workCode = normalize_work_type_code($workCodeRaw);
                        $hours = null;

                        $reasonCodeRaw = strtoupper(trim((string) ($reasonCodeList[$i] ?? '')));
                        $reasonCode = $reasonCodeRaw !== '' ? $reasonCodeRaw : null;
                        $reasonMeta = $reasonCode !== null ? ($noPunchReasonMeta[$reasonCode] ?? null) : null;
                        $reasonNote = trim((string) ($reasonNoteList[$i] ?? ''));
                        if ($reasonNote !== '') {
                            $reasonNote = substr($reasonNote, 0, 255);
                        } else {
                            $reasonNote = null;
                        }
                        $medicalNote = null;
                        $medicalCertificatePath = null;
                        $medicalCertificateName = null;
                        $isSickReason = is_timekeeper_sick_leave_reason($reasonCode);

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

                        if ($reasonMeta !== null) {
                            apply_reason_defaults($reasonMeta, is_staff_employee_type($employeeTypeCode), $hours, $workCode);
                        }

                        if ($isSickReason) {
                            if ($i !== $medicalTargetIndex) {
                                $errors[] = 'For SICK reason, use row "Send to HR" and attach medical certificate.';
                            }
                            $medicalNoteRaw = $medicalPopupNote !== '' ? $medicalPopupNote : trim((string) ($reasonNote ?? ''));
                            if ($medicalNoteRaw === '') {
                                $errors[] = 'Medical note is required for sick leave.';
                            } else {
                                $medicalNote = substr($medicalNoteRaw, 0, 500);
                                $reasonNote = substr($medicalNoteRaw, 0, 255);
                            }

                            if (empty($errors) && $reasonMeta !== null) {
                                $uploadResult = upload_attendance_medical_certificate(
                                    is_array($medicalUpload) ? $medicalUpload : [],
                                    $empCode,
                                    $attDate
                                );
                                if (!$uploadResult['ok']) {
                                    $errors[] = (string) ($uploadResult['error'] ?? 'Medical certificate upload failed.');
                                } else {
                                    $medicalCertificatePath = (string) ($uploadResult['path'] ?? '');
                                    $medicalCertificateName = (string) ($uploadResult['name'] ?? '');
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
                            if ($reasonMeta === null) {
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
                                if (!delete_attendance_medical_certificate($bd, $empCode, $attDate)) {
                                    set_flash('warning', 'Override cleared, but failed to clear medical certificate details.');
                                } else {
                                    set_flash('success', 'Override cleared.');
                                }
                            } else {
                                $changeDate = gmdate('Y-m-d H:i:s');
                                $approvedFlag = 0;
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
                                        $approvedFlag,
                                        $approvedByEmail,
                                        $approvedByName,
                                        $approvedDate
                                    );
                                    if ($insertStmt->execute()) {
                                        if ($isSickReason) {
                                            $savedMedical = upsert_attendance_medical_certificate(
                                                $bd,
                                                $empCode,
                                                $attDate,
                                                $medicalNote,
                                                (string) $medicalCertificatePath,
                                                $medicalCertificateName,
                                                'timekeeper',
                                                $emailParam,
                                                $nameParam,
                                                $changeDate
                                            );
                                            if (!$savedMedical) {
                                                set_flash('warning', 'Override saved, but failed to save medical certificate details.');
                                            } else {
                                                set_flash('success', 'Override saved.');
                                            }
                                        } else {
                                            if (!delete_attendance_medical_certificate($bd, $empCode, $attDate)) {
                                                set_flash('warning', 'Override saved, but failed to clear medical certificate details.');
                                            } else {
                                                set_flash('success', 'Override saved.');
                                            }
                                        }
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
    } elseif ($action === 'save_overrides' || $action === 'submit_to_hr') {
        $isSubmitToHrAction = ($action === 'submit_to_hr');
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
            $alreadySentToHr = [];
            $uniqueEmp = [];
            $employeeTypeMap = [];
            for ($i = 0; $i < count($empCodes); $i++) {
                $emp = trim((string) ($empCodes[$i] ?? ''));
                $date = trim((string) ($attDates[$i] ?? ''));
                if ($emp === '' || $date === '') {
                    continue;
                }
                $uniqueEmp[$emp] = true;
            }
            $uniqueEmp = array_keys($uniqueEmp);
            $employeeTypeMap = load_employee_type_map($bd, $uniqueEmp);
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

                $approvedSql = 'SELECT emp_code, override_work_hours, override_work_code, override_is_approved FROM gcc_attendance_master.employee_att_daily_overrides ' .
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
                                $hoursVal = trim((string) ($r['override_work_hours'] ?? ''));
                                $codeVal = trim((string) ($r['override_work_code'] ?? ''));
                                if ($hoursVal !== '' || $codeVal !== '') {
                                    $alreadySentToHr[$emp . '|' . $selectedDate] = true;
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
                if (isset($locks[$key]) || isset($approved[$key]) || isset($alreadySentToHr[$key])) {
                    $skipped++;
                    continue;
                }

                $hoursRaw = trim((string) ($hoursList[$i] ?? ''));
                $workCodeRaw = trim((string) ($codeList[$i] ?? ''));
                $workCode = normalize_work_type_code($workCodeRaw);
                $hours = null;

                $reasonCodeRaw = strtoupper(trim((string) ($reasonCodeList[$i] ?? '')));
                $reasonCode = $reasonCodeRaw !== '' ? $reasonCodeRaw : null;
                $reasonMeta = $reasonCode !== null ? ($noPunchReasonMeta[$reasonCode] ?? null) : null;
                $reasonNote = trim((string) ($reasonNoteList[$i] ?? ''));
                if ($reasonNote !== '') {
                    $reasonNote = substr($reasonNote, 0, 255);
                } else {
                    $reasonNote = null;
                }

                if (is_timekeeper_sick_leave_reason($reasonCode)) {
                    $errors[] = 'For SICK reason, use row "Send to HR" and attach medical certificate for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
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

                if ($reasonMeta !== null) {
                    $isStaff = is_staff_employee_type($employeeTypeMap[$empCode] ?? null);
                    apply_reason_defaults($reasonMeta, $isStaff, $hours, $workCode);
                }

                // If work hours are overridden, a reason must be selected.
                if ($hours !== null && $reasonCode === null) {
                    $errors[] = 'Reason is required when overriding hours for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
                }

                if ($reasonCode !== null) {
                    if ($reasonMeta === null) {
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
                    if (!$isSubmitToHrAction && $deleteStmt) {
                        $deleteStmt->bind_param('ss', $empCode, $attDate);
                        if ($deleteStmt->execute()) {
                            $deleted++;
                        }
                    }
                    continue;
                }

                $approvedFlag = 0;
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
                        $approvedFlag,
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
                if ($isSubmitToHrAction) {
                    $message = 'Sent ' . max(0, $updated) . ' employee(s) to HR.';
                    if ($skipped > 0) {
                        $message .= ' Skipped ' . $skipped . ' locked/already-sent row(s).';
                    }
                } else {
                    $message = 'Overrides saved.';
                    if ($updated > 0) {
                        $message = 'Updated ' . $updated . ' override(s).';
                    }
                    if ($deleted > 0) {
                        $message .= ' Cleared ' . $deleted . ' override(s).';
                    }
                    if ($skipped > 0) {
                        $message .= ' Skipped ' . $skipped . ' locked/already-sent row(s).';
                    }
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
    if ($overrideStatusFilter !== 'all') {
        $redirectParams['override_status'] = $overrideStatusFilter;
    }
    if ($campbossStatusFilter !== 'all') {
        $redirectParams['campboss_status'] = $campbossStatusFilter;
    }
    if ($isAjaxRequest && in_array($action, ['row_save', 'row_submit', 'save_overrides', 'submit_to_hr', 'submit_to_campboss'], true)) {
        $flashPayload = get_flash();
        $type = strtolower((string) ($flashPayload['type'] ?? 'warning'));
        $message = trim((string) ($flashPayload['message'] ?? 'Unable to process request.'));
        $ok = ($type === 'success');
        if ($message === '') {
            $message = $ok ? 'Completed.' : 'Unable to process request.';
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => $ok,
            'type' => $type,
            'message' => $message,
            'action' => $action,
            'rowIndex' => $action === 'row_save'
                ? (is_scalar($rowSave) ? (int) $rowSave : null)
                : (is_scalar($rowSubmit) ? (int) $rowSubmit : null),
        ], JSON_UNESCAPED_SLASHES);
        exit;
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
        'hr.desg_name, hr.dept_name, hr.jbno, hr.jbdesc, hr.ty_cd ' .
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
        $medicalCertificates = [];

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

            if (ensure_attendance_medical_certificate_table($bd)) {
                $medicalSql = 'SELECT emp_code, medical_note, file_path, file_name, updated_at ' .
                    'FROM gcc_attendance_master.attendance_medical_certificates ' .
                    'WHERE emp_code IN (' . $placeholders . ') AND att_date = ?';
                $stmt = $bd->prepare($medicalSql);
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
                                $medicalCertificates[$emp] = $row;
                            }
                            $result->free();
                        }
                    }
                    $stmt->close();
                }
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
            $medical = $medicalCertificates[$empCode] ?? [];

            $overrideHours = trim((string) ($override['override_work_hours'] ?? ''));
            $overrideCode = trim((string) ($override['override_work_code'] ?? ''));
            $overrideReasonCode = strtoupper(trim((string) ($override['override_reason_code'] ?? '')));
            $overrideReasonNote = trim((string) ($override['override_reason_note'] ?? ''));
            $overrideStatus = (int) ($override['override_is_approved'] ?? 0);

            $submittedAt = trim((string) ($review['timekeeper_submitted_at'] ?? ''));
            $campbossReason = trim((string) ($review['campboss_reason_code'] ?? ''));
            $campbossReviewed = trim((string) ($review['campboss_reviewed_at'] ?? ''));
            $isEscalated = (int) ($review['is_escalated'] ?? 0);
            $employeeTypeCode = strtoupper(trim((string) ($employee['ty_cd'] ?? '')));

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
                'employee_type_code' => $employeeTypeCode,
                'override_hours' => $overrideHours,
                'override_code' => $overrideCode,
                'override_reason_code' => $overrideReasonCode,
                'override_reason_note' => $overrideReasonNote,
                'override_status' => $overrideStatus,
                'submitted_at' => $submittedAt,
                'campboss_reason' => $campbossReason,
                'campboss_reviewed' => $campbossReviewed,
                'is_escalated' => $isEscalated,
                'medical_note' => trim((string) ($medical['medical_note'] ?? '')),
                'medical_certificate_path' => trim((string) ($medical['file_path'] ?? '')),
                'medical_certificate_name' => trim((string) ($medical['file_name'] ?? '')),
                'medical_certificate_uploaded_at' => trim((string) ($medical['updated_at'] ?? '')),
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
  .no-punch-table th:nth-child(9),
  .no-punch-table th:nth-child(10),
  .no-punch-table td:nth-child(9),
  .no-punch-table td:nth-child(10) {
    text-align: center;
  }
  .status-pill {
    position: relative;
    overflow: hidden;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    max-width: min(96vw, 360px);
    padding: 7px 14px;
    min-height: 34px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    font-size: 12px;
    font-weight: 700;
    line-height: 1.28;
    text-align: center;
    white-space: normal;
    letter-spacing: 0.01em;
    color: #0f172a;
    background-image: linear-gradient(140deg, rgba(241, 245, 249, 0.95), rgba(226, 232, 240, 0.85));
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.65);
    cursor: default;
    pointer-events: none;
  }
  .status-pill::before {
    content: '';
    width: 8px;
    height: 8px;
    flex: 0 0 8px;
    border-radius: 999px;
    background-color: currentColor;
    box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.08);
  }
  .status-pill::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.34), rgba(255, 255, 255, 0));
    opacity: 0.75;
  }
  .status-pill.is-pending {
    color: #92400e;
    border-color: rgba(245, 158, 11, 0.38);
    background-image: linear-gradient(140deg, rgba(254, 243, 199, 0.98), rgba(253, 230, 138, 0.82));
  }
  .status-pill.is-approved {
    color: #166534;
    border-color: rgba(34, 197, 94, 0.34);
    background-image: linear-gradient(140deg, rgba(220, 252, 231, 0.98), rgba(187, 247, 208, 0.82));
  }
  .status-pill.is-submitted {
    color: #1e40af;
    border-color: rgba(59, 130, 246, 0.35);
    background-image: linear-gradient(140deg, rgba(219, 234, 254, 0.98), rgba(191, 219, 254, 0.84));
    box-shadow: 0 5px 12px rgba(37, 99, 235, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.72);
  }
  .status-pill.is-submitted::before {
    background-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
  }
  .status-pill.is-reviewed {
    color: #0c4a6e;
    border-color: rgba(14, 165, 233, 0.35);
    background-image: linear-gradient(140deg, rgba(207, 250, 254, 0.98), rgba(186, 230, 253, 0.86));
  }
  .status-pill.is-escalated {
    color: #991b1b;
    border-color: rgba(239, 68, 68, 0.38);
    background-image: linear-gradient(140deg, rgba(254, 226, 226, 0.98), rgba(254, 202, 202, 0.82));
  }
  .no-punch-table button[name="row_save"],
  .no-punch-table button[name="row_submit"] {
    white-space: nowrap !important;
  }
  .btn.btn-primary.btn-campboss-peacock {
    position: relative;
    overflow: hidden;
    color: #ffffff !important;
    font-weight: 700;
    letter-spacing: 0.01em;
    text-shadow: 0 1px 1px rgba(15, 23, 42, 0.35);
    border-radius: 12px;
    border-color: transparent !important;
    background-image: linear-gradient(125deg, #0f766e 0%, #0891b2 46%, #2563eb 100%) !important;
    box-shadow: 0 10px 22px rgba(14, 116, 144, 0.34), inset 0 1px 0 rgba(255, 255, 255, 0.25);
    transition: transform 0.18s ease, box-shadow 0.22s ease, filter 0.22s ease;
  }
  .btn.btn-primary.btn-campboss-peacock::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(112deg, transparent 36%, rgba(255, 255, 255, 0.38) 50%, transparent 64%);
    transform: translateX(-135%);
    transition: transform 0.56s ease;
  }
  .btn.btn-primary.btn-campboss-peacock:hover {
    color: #ffffff !important;
    transform: translateY(-1px);
    filter: saturate(1.08);
    box-shadow: 0 14px 28px rgba(14, 116, 144, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.32);
  }
  .btn.btn-primary.btn-campboss-peacock:hover::before {
    transform: translateX(135%);
  }
  .btn.btn-primary.btn-campboss-peacock:focus,
  .btn.btn-primary.btn-campboss-peacock:focus-visible {
    color: #ffffff !important;
    box-shadow: 0 0 0 0.22rem rgba(56, 189, 248, 0.42), 0 14px 28px rgba(14, 116, 144, 0.42) !important;
  }
  .btn.btn-primary.btn-campboss-peacock:not(:disabled):not(.disabled):active,
  .btn.btn-primary.btn-campboss-peacock:not(:disabled):not(.disabled).active,
  .show > .btn.btn-primary.btn-campboss-peacock.dropdown-toggle {
    color: #ffffff !important;
    transform: translateY(0);
    background-image: linear-gradient(125deg, #0d5f59 0%, #0a7490 46%, #1e4fc9 100%) !important;
    box-shadow: 0 8px 18px rgba(14, 116, 144, 0.38), inset 0 1px 0 rgba(255, 255, 255, 0.18);
  }
  .btn.btn-primary.btn-campboss-peacock:disabled,
  .btn.btn-primary.btn-campboss-peacock.disabled {
    color: #ffffff !important;
    text-shadow: none;
    background-image: linear-gradient(125deg, #7ba6a1 0%, #77a8b7 46%, #7c99c8 100%) !important;
    border-color: transparent !important;
    box-shadow: none;
    opacity: 0.82;
  }
  .no-punch-toast {
    position: fixed;
    right: 18px;
    bottom: 18px;
    z-index: 1080;
    width: min(92vw, 460px);
    max-width: 460px;
    border-radius: 18px;
    border: 1px solid transparent;
    padding: 14px 16px;
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr);
    gap: 12px;
    align-items: flex-start;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
    letter-spacing: 0.01em;
    color: #0f172a;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.26), 0 6px 18px rgba(15, 23, 42, 0.15);
    transform: translateY(14px) scale(0.98);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.24s ease, transform 0.24s ease, box-shadow 0.24s ease;
    backdrop-filter: blur(8px) saturate(1.05);
    overflow: hidden;
    min-height: 0 !important;
    height: auto !important;
  }
  .no-punch-toast::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.32), transparent 58%);
    opacity: 0.8;
  }
  .no-punch-toast > * {
    position: relative;
    z-index: 1;
  }
  .no-punch-toast__icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: 800;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45);
  }
  .no-punch-toast__icon::before {
    content: '!';
    line-height: 1;
  }
  .no-punch-toast__content {
    min-width: 0;
  }
  .no-punch-toast__title {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 3px;
  }
  .no-punch-toast__message {
    font-size: 14px;
    font-weight: 600;
    line-height: 1.42;
    word-break: break-word;
  }
  .no-punch-toast.is-visible {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  .no-punch-toast.is-centered {
    left: 50%;
    top: 50%;
    right: auto;
    bottom: auto;
    width: min(90vw, 560px);
    max-width: 560px;
    transform: translate(-50%, -46%) scale(0.98);
  }
  .no-punch-toast.is-centered.is-visible {
    transform: translate(-50%, -50%) scale(1);
  }
  .no-punch-toast.is-success {
    color: #0f5132;
    border-color: rgba(22, 163, 74, 0.45);
    background-image: linear-gradient(140deg, rgba(220, 252, 231, 0.98), rgba(187, 247, 208, 0.94));
    box-shadow: 0 22px 44px rgba(21, 128, 61, 0.22), 0 6px 16px rgba(22, 101, 52, 0.18);
  }
  .no-punch-toast.is-success .no-punch-toast__icon {
    color: #166534;
    background: linear-gradient(145deg, rgba(240, 253, 244, 0.98), rgba(187, 247, 208, 0.86));
    border: 1px solid rgba(34, 197, 94, 0.34);
  }
  .no-punch-toast.is-success .no-punch-toast__icon::before {
    content: '\2713';
    font-size: 18px;
  }
  .no-punch-toast.is-success .no-punch-toast__title {
    color: #166534;
  }
  .no-punch-toast.is-error {
    color: #7f1d1d;
    border-color: rgba(239, 68, 68, 0.44);
    background-image: linear-gradient(145deg, rgba(255, 241, 242, 0.98), rgba(254, 226, 226, 0.95));
    box-shadow: 0 22px 44px rgba(185, 28, 28, 0.22), 0 6px 16px rgba(153, 27, 27, 0.18);
  }
  .no-punch-toast.is-error .no-punch-toast__icon {
    color: #991b1b;
    background: linear-gradient(145deg, rgba(254, 242, 242, 0.98), rgba(254, 202, 202, 0.9));
    border: 1px solid rgba(239, 68, 68, 0.38);
  }
  .no-punch-toast.is-error .no-punch-toast__title {
    color: #991b1b;
  }
  .no-punch-medical-modal {
    position: fixed;
    inset: 0;
    z-index: 2200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .no-punch-medical-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.56);
  }
  .no-punch-medical-modal__dialog {
    position: relative;
    width: min(560px, calc(100vw - 32px));
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    border-radius: 14px;
    background: #ffffff;
    border: 1px solid rgba(15, 23, 42, 0.1);
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.28);
    padding: 18px;
  }
  @media (max-width: 576px) {
    .no-punch-toast {
      grid-template-columns: 36px minmax(0, 1fr);
      gap: 10px;
      padding: 12px 12px;
      border-radius: 14px;
    }
    .no-punch-toast__icon {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      font-size: 18px;
    }
    .no-punch-toast__title {
      font-size: 10px;
    }
    .no-punch-toast__message {
      font-size: 13px;
    }
  }
  .no-punch-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
  }
  .no-punch-table-toolbar {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.95) 0%, rgba(241, 245, 249, 0.72) 100%);
  }
  .no-punch-table-toolbar .form-group {
    margin-bottom: 0;
  }
  .no-punch-table-toolbar .form-label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 0.35rem;
    letter-spacing: 0.01em;
  }
  .bulk-override-group {
    min-width: 360px;
  }
  .bulk-override-hours {
    max-width: 110px;
  }
  @media (max-width: 768px) {
    .no-punch-table-toolbar .form-group {
      margin-bottom: 0.55rem;
    }
    .no-punch-table-toolbar .form-group:last-child {
      margin-bottom: 0;
    }
  }
  @media (max-width: 576px) {
    .bulk-override-group {
      width: 100%;
      min-width: 0;
    }
    .bulk-override-hours {
      max-width: none;
    }
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

    <div id="noPunchAjaxMessage" class="alert d-none mb-3"></div>

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
          <form id="noPunchFilterForm" method="get" class="form-row">
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
          <div id="remainingNoPunchCount" class="small text-muted">Remaining without overrides: <?= h((string) $remainingCount) ?></div>
        </div>
      </div>

      <div class="card no-punch-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">Employees with no punch</h3>
          <div class="no-punch-actions">
            <div class="input-group input-group-sm bulk-override-group">
              <div class="input-group-prepend">
                <span class="input-group-text">Bulk</span>
              </div>
              <input
                id="bulkOverrideHours"
                type="number"
                step="0.01"
                min="0"
                max="24"
                class="form-control bulk-override-hours"
                inputmode="decimal"
                aria-label="Bulk override hours"
              >
              <select id="bulkOverrideReason" class="form-control" aria-label="Bulk override reason">
                <option value="">Reason</option>
                <?php foreach ($noPunchReasonOptions as $code => $text): ?>
                  <?php $meta = $noPunchReasonMeta[$code] ?? []; ?>
                  <option
                    value="<?= h($code) ?>"
                    data-default-behavior="<?= h($meta['default_behavior'] ?? 'NONE') ?>"
                    data-default-work-code="<?= h($meta['default_work_code'] ?? '') ?>"
                  ><?= h($code . ' - ' . $text) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="input-group-append">
                <button type="button" class="btn btn-outline-primary" id="bulkOverrideApply">Apply</button>
              </div>
            </div>
            <div class="custom-control custom-checkbox align-self-center">
              <input type="checkbox" class="custom-control-input" id="bulkOverrideOverwrite">
              <label class="custom-control-label text-muted small" for="bulkOverrideOverwrite">Overwrite</label>
            </div>
            <span id="noPunchRecordCount" class="text-muted small"><?= h(count($rows)) ?> records</span>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <div class="no-punch-table-toolbar">
            <div class="form-row">
              <div class="form-group col-lg-4 col-md-6">
                <label for="noPunchTableSearch" class="form-label">Search table</label>
                <input id="noPunchTableSearch" type="search" class="form-control form-control-sm" placeholder="Emp code, name, project, status">
              </div>
              <div class="form-group col-lg-3 col-md-3">
                <label for="noPunchOverrideFilter" class="form-label">Override status</label>
                <select id="noPunchOverrideFilter" class="form-control form-control-sm">
                  <option value="all" <?= $overrideStatusFilter === 'all' ? 'selected' : '' ?>>All</option>
                  <option value="pending" <?= $overrideStatusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                  <option value="approved" <?= $overrideStatusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                  <option value="rejected" <?= $overrideStatusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                  <option value="not_set" <?= $overrideStatusFilter === 'not_set' ? 'selected' : '' ?>>Not set</option>
                </select>
              </div>
              <div class="form-group col-lg-3 col-md-3">
                <label for="noPunchCampbossFilter" class="form-label">Camp boss status</label>
                <select id="noPunchCampbossFilter" class="form-control form-control-sm">
                  <option value="all" <?= $campbossStatusFilter === 'all' ? 'selected' : '' ?>>All</option>
                  <option value="submitted" <?= $campbossStatusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                  <option value="reviewed" <?= $campbossStatusFilter === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                  <option value="escalated" <?= $campbossStatusFilter === 'escalated' ? 'selected' : '' ?>>Escalated</option>
                  <option value="not_submitted" <?= $campbossStatusFilter === 'not_submitted' ? 'selected' : '' ?>>Not submitted</option>
                </select>
              </div>
              <div class="form-group col-lg-2 col-md-12 d-flex align-items-end">
                <button type="button" class="btn btn-outline-secondary btn-sm btn-block" id="noPunchTableFilterReset">Reset</button>
              </div>
            </div>
          </div>
          <form method="post" enctype="multipart/form-data">
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

                      $isCampbossWorkflow = ((int) ($row['is_escalated'] ?? 0) === 1)
                        || trim((string) ($row['campboss_reviewed'] ?? '')) !== ''
                        || trim((string) ($row['campboss_reason'] ?? '')) !== ''
                        || trim((string) ($row['submitted_at'] ?? '')) !== '';

                      $isHrWorkflow = ($overrideStatus === 1 || $overrideStatus === 2)
                        || trim((string) ($row['override_hours'] ?? '')) !== ''
                        || trim((string) ($row['override_code'] ?? '')) !== '';

                      // Backward-compatible naming: the row is "submitted" as soon as it enters the camp boss flow.
                      $isSubmitted = $isCampbossWorkflow;

                      $lockInputs = ($overrideStatus === 1) || $isCampbossWorkflow;
                      $canSubmitRow = (!$isCampbossWorkflow)
                        && !$lockInputs
                        && trim((string) ($row['override_hours'] ?? '')) === ''
                        && trim((string) ($row['override_code'] ?? '')) === '';
                      $medicalCertificatePath = str_replace('\\', '/', trim((string) ($row['medical_certificate_path'] ?? '')));
                      $medicalCertificateName = trim((string) ($row['medical_certificate_name'] ?? ''));
                      $medicalCertificateUrl = '';
                      if ($medicalCertificatePath !== '') {
                          $medicalCertificateUrl = attendance_app_base() . '/' . ltrim($medicalCertificatePath, '/');
                      }
                    ?>
                    <tr data-row-index="<?= (int) $rowIndex ?>" data-employee-type="<?= h($row['employee_type_code'] ?? '') ?>">
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
                        <input type="number" step="0.01" min="0" max="24" class="form-control form-control-sm" name="work_hours[]" value="<?= h($row['override_hours']) ?>" <?= $lockInputs ? 'readonly' : '' ?>>
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
                            <?php $meta = $noPunchReasonMeta[$code] ?? []; ?>
                            <option
                              value="<?= h($code) ?>"
                              data-desc="<?= h($text) ?>"
                              data-default-behavior="<?= h($meta['default_behavior'] ?? 'NONE') ?>"
                              data-default-work-code="<?= h($meta['default_work_code'] ?? '') ?>"
                              <?= $selected ? 'selected' : '' ?>
                            >
                              <?= h($code . ' - ' . $text) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <?php if ($lockInputs): ?>
                          <input type="hidden" name="override_reason_code[]" value="<?= h($row['override_reason_code'] ?? '') ?>">
                        <?php endif; ?>
                        <input
                          type="text"
                          class="form-control form-control-sm mt-1 js-override-reason-note"
                          name="override_reason_note[]"
                          maxlength="255"
                          placeholder="Note (optional)"
                          value="<?= h($row['override_reason_note'] ?? '') ?>"
                          <?= $lockInputs ? 'readonly' : '' ?>
                        >
                        <?php if ($medicalCertificateUrl !== ''): ?>
                          <div class="small mt-1">
                            <a href="<?= h($medicalCertificateUrl) ?>" target="_blank" rel="noopener">
                              Medical certificate: <?= h($medicalCertificateName !== '' ? $medicalCertificateName : basename($medicalCertificatePath)) ?>
                            </a>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!$isCampbossWorkflow): ?>
                          <?php if (!$isHrWorkflow): ?>
                            <button type="submit" name="row_save" value="<?= (int) $rowIndex ?>" class="btn btn-primary btn-block" <?= $lockInputs ? 'disabled' : '' ?>>Send to HR</button>
                          <?php endif; ?>
                          <?php if ($isHrWorkflow): ?>
                            <span class="status-pill <?= h($overrideClass) ?>"><?= h($overrideLabel) ?></span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($isCampbossWorkflow): ?>
                          <span class="status-pill <?= h($campClass) ?>"><?= h($campLabel) ?></span>
                        <?php elseif ($canSubmitRow): ?>
                          <button
                            type="submit"
                            name="row_submit"
                            value="<?= (int) $rowIndex ?>"
                            class="btn btn-primary btn-block btn-campboss-peacock"
                            title=""
                          >Ask Camp Boss</button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
            <div class="p-3 d-flex flex-wrap gap-2">
              <button type="submit" name="action" value="save_overrides" class="btn btn-primary">Save adjustments</button>
              <button type="submit" name="action" value="submit_to_hr" class="btn btn-outline-primary">Send remaining to HR</button>
              <button type="submit" name="action" value="submit_to_campboss" class="btn btn-outline-secondary">Submit remaining to camp boss</button>
            </div>
          </form>
        </div>
      </div>

      <div class="no-punch-medical-modal d-none js-medical-modal" role="dialog" aria-modal="true" aria-labelledby="timekeeperMedicalModalTitle">
        <div class="no-punch-medical-modal__backdrop js-medical-modal-cancel"></div>
        <div class="no-punch-medical-modal__dialog" role="document">
          <h3 class="h5 mb-2" id="timekeeperMedicalModalTitle">Sick Leave Details</h3>
          <p class="text-muted small mb-3">Add medical note and upload medical certificate before sending this row to HR.</p>
          <div class="form-group mb-3">
            <label for="timekeeperMedicalPopupNote">Medical note</label>
            <textarea id="timekeeperMedicalPopupNote" class="form-control js-medical-note-input" rows="4" maxlength="500" placeholder="Enter sick leave note"></textarea>
            <div class="small text-danger mt-1 d-none js-medical-note-error">Medical note is required.</div>
          </div>
          <div class="form-group mb-3">
            <label for="timekeeperMedicalPopupFile">Medical certificate</label>
            <input id="timekeeperMedicalPopupFile" type="file" class="form-control-file js-medical-file-input" accept=".pdf,.jpg,.jpeg,.png">
            <div class="small text-muted mt-1">Accepted: PDF, JPG, JPEG, PNG (max 5 MB)</div>
            <div class="small text-danger mt-1 d-none js-medical-file-error">Medical certificate file is required.</div>
          </div>
          <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-outline-secondary js-medical-modal-cancel">Cancel</button>
            <button type="button" class="btn btn-primary ml-2 js-medical-modal-confirm">Continue</button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
  // Used by the shared work-code autocomplete in `admin/include/layout_bottom.php`.
  window.WORK_CODE_OPTIONS = <?= json_encode($workTypeOptions, JSON_UNESCAPED_SLASHES) ?>;
  window.NO_PUNCH_REASON_META = <?= json_encode($noPunchReasonMeta, JSON_UNESCAPED_SLASHES) ?>;

  (function () {
    var reasonMeta = window.NO_PUNCH_REASON_META || {};

    function getReasonDefaults(reasonCode) {
      var key = String(reasonCode || '').trim().toUpperCase();
      if (key === '' || !Object.prototype.hasOwnProperty.call(reasonMeta, key)) {
        return null;
      }
      return reasonMeta[key] || null;
    }

    function isStaffRow(tr) {
      if (!tr) return false;
      return String(tr.getAttribute('data-employee-type') || '').trim().toUpperCase() === '01';
    }

    function applyReasonDefaultsToRow(tr, reasonSelect) {
      if (!tr || !reasonSelect) return;
      var hours = tr.querySelector('input[name="work_hours[]"]');
      var code = tr.querySelector('input[name="work_code[]"]');
      if (!hours || !code) return;
      if (hours.readOnly || hours.disabled || code.readOnly || code.disabled) return;

      var reasonCode = String(reasonSelect.value || '').trim().toUpperCase();
      if (reasonCode === '') return;

      var meta = getReasonDefaults(reasonCode);
      if (!meta) return;
      var behavior = String(meta.default_behavior || 'NONE').trim().toUpperCase();
      var defaultCode = String(meta.default_work_code || '').trim().toUpperCase();
      var fullDay = isStaffRow(tr) ? 8 : 10;

      if (behavior === 'FULL_DAY') {
        hours.value = fullDay.toFixed(2);
        code.value = '';
      } else if (behavior === 'FULL_DAY_PLUS_1H') {
        hours.value = (fullDay + 1).toFixed(2);
        code.value = '';
      } else if (behavior === 'WORK_CODE' && defaultCode !== '') {
        code.value = defaultCode;
        hours.value = '';
      }
    }

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
        showValidationError('Choose only one override per row: work hours OR work code (not both).');
        code.focus();
        return false;
      }

      if (hoursVal !== '') {
        var n = Number(hoursVal);
        if (!isFinite(n)) {
          showValidationError('Invalid hours. Please enter a number between 0 and 24.');
          hours.focus();
          return false;
        }
        if (n < 0 || n > 24) {
          showValidationError('Hours must be between 0 and 24.');
          hours.focus();
          return false;
        }
        if ((reason.value || '').trim() === '') {
          showValidationError('Reason is required when overriding hours. Please select a reason.');
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
        showValidationError('Before submitting to camp boss, clear override hours and override code.');
        if (hoursVal !== '') {
          hours.focus();
        } else {
          code.focus();
        }
        return false;
      }

      return true;
    }

    var filterForm = document.getElementById('noPunchFilterForm');
    var form = document.querySelector('form[method="post"]');
    var ajaxMessage = document.getElementById('noPunchAjaxMessage');
    var tableSearchInput = document.getElementById('noPunchTableSearch');
    var tableOverrideFilter = document.getElementById('noPunchOverrideFilter');
    var tableCampbossFilter = document.getElementById('noPunchCampbossFilter');
    var tableFilterResetBtn = document.getElementById('noPunchTableFilterReset');
    var toastHideTimer = null;
    var medicalModal = document.querySelector('.js-medical-modal');
    var medicalNoteInput = medicalModal ? medicalModal.querySelector('.js-medical-note-input') : null;
    var medicalFileInput = medicalModal ? medicalModal.querySelector('.js-medical-file-input') : null;
    var medicalNoteError = medicalModal ? medicalModal.querySelector('.js-medical-note-error') : null;
    var medicalFileError = medicalModal ? medicalModal.querySelector('.js-medical-file-error') : null;
    var medicalConfirmButton = medicalModal ? medicalModal.querySelector('.js-medical-modal-confirm') : null;
    var medicalCancelButtons = medicalModal ? medicalModal.querySelectorAll('.js-medical-modal-cancel') : [];
    var medicalModalState = null;

    function ensureNoPunchToastMarkup(el) {
      if (!el) return;
      if (el.querySelector('.no-punch-toast__message')) return;

      el.textContent = '';

      var icon = document.createElement('span');
      icon.className = 'no-punch-toast__icon';
      icon.setAttribute('aria-hidden', 'true');

      var content = document.createElement('div');
      content.className = 'no-punch-toast__content';

      var title = document.createElement('div');
      title.className = 'no-punch-toast__title';

      var message = document.createElement('div');
      message.className = 'no-punch-toast__message';

      content.appendChild(title);
      content.appendChild(message);
      el.appendChild(icon);
      el.appendChild(content);
    }

    function getNoPunchToastEl() {
      var el = document.getElementById('noPunchToast');
      if (el) {
        ensureNoPunchToastMarkup(el);
        return el;
      }
      el = document.createElement('div');
      el.id = 'noPunchToast';
      el.className = 'no-punch-toast';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('aria-atomic', 'true');
      ensureNoPunchToastMarkup(el);
      document.body.appendChild(el);
      return el;
    }

    function positionNoPunchToast(toast, anchorEl) {
      if (!toast) return;

      var fallbackRight = 18;
      var fallbackBottom = 18;
      var viewportPad = 8;
      var anchorGap = 10;

      if (!anchorEl || !anchorEl.isConnected || typeof anchorEl.getBoundingClientRect !== 'function') {
        toast.style.left = '';
        toast.style.top = '';
        toast.style.right = fallbackRight + 'px';
        toast.style.bottom = fallbackBottom + 'px';
        return;
      }

      var rect = anchorEl.getBoundingClientRect();
      var viewportWidth = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
      var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
      var toastWidth = Math.ceil(toast.offsetWidth || 0);
      var toastHeight = Math.ceil(toast.offsetHeight || 0);

      if (toastWidth <= 0 || toastHeight <= 0) {
        toast.style.left = '';
        toast.style.top = '';
        toast.style.right = fallbackRight + 'px';
        toast.style.bottom = fallbackBottom + 'px';
        return;
      }

      var left = rect.left + (rect.width / 2) - (toastWidth / 2);
      var maxLeft = Math.max(viewportPad, viewportWidth - toastWidth - viewportPad);
      left = Math.max(viewportPad, Math.min(left, maxLeft));

      var topBelow = rect.bottom + anchorGap;
      var topAbove = rect.top - toastHeight - anchorGap;
      var maxTop = Math.max(viewportPad, viewportHeight - toastHeight - viewportPad);
      var top = topBelow;
      if (topBelow + toastHeight > viewportHeight - viewportPad) {
        top = topAbove >= viewportPad ? topAbove : maxTop;
      }
      top = Math.max(viewportPad, Math.min(top, maxTop));

      toast.style.left = Math.round(left) + 'px';
      toast.style.top = Math.round(top) + 'px';
      toast.style.right = 'auto';
      toast.style.bottom = 'auto';
    }

    function showAjaxMessage(message, isError, anchorEl, options) {
      var text = (message || '').trim();
      if (text === '') {
        text = isError ? 'Unable to process request.' : 'Done.';
      }
      var opts = options || {};
      var centered = true;
      var dismissMs = Number(opts.dismissMs || 1600);
      var heading = (typeof opts.title === 'string' ? opts.title : '').trim();
      if (heading === '') {
        heading = isError ? 'Action Required' : 'Updated';
      }
      if (!isFinite(dismissMs) || dismissMs <= 0) {
        dismissMs = 1600;
      }

      var toast = getNoPunchToastEl();
      toast.classList.remove('is-success', 'is-error', 'is-visible', 'is-centered');
      toast.classList.add(isError ? 'is-error' : 'is-success');
      if (centered) {
        toast.classList.add('is-centered');
        toast.style.left = '';
        toast.style.top = '';
        toast.style.right = '';
        toast.style.bottom = '';
      }
      var titleEl = toast.querySelector('.no-punch-toast__title');
      var messageEl = toast.querySelector('.no-punch-toast__message');
      if (titleEl) {
        titleEl.textContent = heading;
      }
      if (messageEl) {
        messageEl.textContent = text;
      } else {
        toast.textContent = text;
      }

      if (toastHideTimer) {
        clearTimeout(toastHideTimer);
      }

      requestAnimationFrame(function () {
        toast.classList.add('is-visible');
      });

      toastHideTimer = setTimeout(function () {
        toast.classList.remove('is-visible');
      }, dismissMs);

      if (ajaxMessage) {
        ajaxMessage.classList.add('d-none');
      }
    }

    function showValidationError(message) {
      showAjaxMessage(message, true, null, { centered: true, dismissMs: 3200, title: 'Submission Blocked' });
    }

    function isSickReason(code) {
      return String(code || '').trim().toUpperCase() === 'SICK';
    }

    function hideMedicalModal() {
      if (!medicalModal) return;
      medicalModal.classList.add('d-none');
      medicalModalState = null;
      if (medicalNoteInput) {
        medicalNoteInput.value = '';
      }
      if (medicalFileInput) {
        medicalFileInput.value = '';
      }
      if (medicalNoteError) {
        medicalNoteError.classList.add('d-none');
      }
      if (medicalFileError) {
        medicalFileError.classList.add('d-none');
      }
    }

    function openMedicalModal(row, submitter) {
      if (!medicalModal || !medicalNoteInput || !medicalFileInput || !row || !submitter) {
        return false;
      }
      var rowIndex = String(submitter.value || '').trim();
      if (rowIndex === '') {
        return false;
      }
      var rowReasonNote = row.querySelector('input.js-override-reason-note');
      medicalModalState = {
        row: row,
        submitter: submitter,
        rowIndex: rowIndex
      };
      medicalNoteInput.value = rowReasonNote ? String(rowReasonNote.value || '').trim() : '';
      medicalFileInput.value = '';
      if (medicalNoteError) {
        medicalNoteError.classList.add('d-none');
      }
      if (medicalFileError) {
        medicalFileError.textContent = 'Medical certificate file is required.';
        medicalFileError.classList.add('d-none');
      }
      medicalModal.classList.remove('d-none');
      medicalNoteInput.focus();
      return true;
    }

    function findEditableSickReasonRow(rows) {
      if (!rows) return null;
      for (var i = 0; i < rows.length; i++) {
        var tr = rows[i];
        var reason = tr.querySelector('select[name="override_reason_code[]"]');
        if (!reason || reason.disabled) {
          continue;
        }
        if (isSickReason(reason.value || '')) {
          return tr;
        }
      }
      return null;
    }

    function rowHasHrOverride(tr) {
      if (!tr) return false;
      var hours = tr.querySelector('input[name="work_hours[]"]');
      var code = tr.querySelector('input[name="work_code[]"]');
      var hoursVal = hours ? (hours.value || '').trim() : '';
      var codeVal = code ? (code.value || '').trim() : '';
      return hoursVal !== '' || codeVal !== '';
    }

    function clearRowSearchCache(tr) {
      if (!tr) return;
      tr.removeAttribute('data-search-text');
    }

    function ensureDisabledSelectMirror(selectEl) {
      if (!selectEl || !selectEl.name || !selectEl.parentElement) return;
      var existing = selectEl.parentElement.querySelector('input[type="hidden"][data-generated-mirror="1"][name="' + selectEl.name + '"]');
      if (existing) {
        existing.value = selectEl.value || '';
        return;
      }
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = selectEl.name;
      hidden.value = selectEl.value || '';
      hidden.setAttribute('data-generated-mirror', '1');
      selectEl.parentElement.insertBefore(hidden, selectEl.nextSibling);
    }

    function removeDisabledSelectMirror(selectEl) {
      if (!selectEl || !selectEl.name || !selectEl.parentElement) return;
      var generated = selectEl.parentElement.querySelectorAll('input[type="hidden"][data-generated-mirror="1"][name="' + selectEl.name + '"]');
      for (var i = 0; i < generated.length; i++) {
        generated[i].remove();
      }
    }

    function setRowLockState(tr, locked) {
      if (!tr) return;
      var hours = tr.querySelector('input[name="work_hours[]"]');
      var code = tr.querySelector('input[name="work_code[]"]');
      var reason = tr.querySelector('select[name="override_reason_code[]"]');
      var note = tr.querySelector('input[name="override_reason_note[]"]');

      if (hours) {
        hours.readOnly = !!locked;
      }
      if (code) {
        code.readOnly = !!locked;
      }
      if (reason) {
        reason.disabled = !!locked;
        if (locked) {
          ensureDisabledSelectMirror(reason);
        } else {
          removeDisabledSelectMirror(reason);
        }
      }
      if (note) {
        note.readOnly = !!locked;
      }
    }

    function renderRowForCampbossWorkflow(tr) {
      if (!tr) return;
      var cells = tr.querySelectorAll('td');
      if (!cells || cells.length < 10) return;
      cells[8].innerHTML = '';
      cells[9].innerHTML = '<span class="status-pill is-submitted">Submitted</span>';
      setRowLockState(tr, true);
      clearRowSearchCache(tr);
    }

    function renderRowForHrWorkflow(tr, rowIndex) {
      if (!tr) return;
      var cells = tr.querySelectorAll('td');
      if (!cells || cells.length < 10) return;

      var hasOverride = rowHasHrOverride(tr);
      var overrideHtml = hasOverride
        ? '<span class="status-pill is-pending">Pending</span>'
        : '<button type="submit" name="row_save" value="' + rowIndex + '" class="btn btn-primary btn-block">Send to HR</button>';
      cells[8].innerHTML = overrideHtml;

      if (hasOverride) {
        cells[9].innerHTML = '';
      } else {
        cells[9].innerHTML = '<button type="submit" name="row_submit" value="' + rowIndex + '" class="btn btn-primary btn-block btn-campboss-peacock" title="">Ask Camp Boss</button>';
      }

      setRowLockState(tr, false);
      clearRowSearchCache(tr);
    }

    function canDoAjaxSubmission() {
      return typeof window.FormData === 'function' &&
        (typeof window.fetch === 'function' || typeof window.XMLHttpRequest === 'function');
    }

    function parseJsonSafe(text) {
      try {
        return JSON.parse(text);
      } catch (e) {
        return null;
      }
    }

    function postRowAjax(formData, onDone) {
      if (typeof window.fetch === 'function') {
        fetch(window.location.href, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
          .then(function (response) {
            return response.text();
          })
          .then(function (text) {
            onDone(parseJsonSafe(text));
          })
          .catch(function () {
            onDone(null);
          });
        return;
      }

      if (typeof window.XMLHttpRequest === 'function') {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) return;
          onDone(parseJsonSafe(xhr.responseText || ''));
        };
        xhr.onerror = function () {
          onDone(null);
        };
        xhr.send(formData);
        return;
      }

      onDone(null);
    }

    function getPageHtmlAjax(url, onDone) {
      if (typeof window.fetch === 'function') {
        fetch(url, {
          method: 'GET',
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
          .then(function (response) {
            return response.text();
          })
          .then(function (html) {
            onDone(html);
          })
          .catch(function () {
            onDone(null);
          });
        return;
      }

      if (typeof window.XMLHttpRequest === 'function') {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) return;
          if (xhr.status >= 200 && xhr.status < 400) {
            onDone(xhr.responseText || '');
            return;
          }
          onDone(null);
        };
        xhr.onerror = function () {
          onDone(null);
        };
        xhr.send();
        return;
      }

      onDone(null);
    }

    function resolveRowIndex(tr) {
      if (!tr) return '';
      var fromData = (tr.getAttribute('data-row-index') || '').trim();
      if (fromData !== '') {
        return fromData;
      }
      var saveBtn = tr.querySelector('button[name="row_save"]');
      if (saveBtn && String(saveBtn.value || '').trim() !== '') {
        return String(saveBtn.value || '').trim();
      }
      var campBtn = tr.querySelector('button[name="row_submit"]');
      return campBtn ? String(campBtn.value || '').trim() : '';
    }

    function updateRemainingCountHint() {
      var hint = document.getElementById('remainingNoPunchCount');
      if (!hint || !form) return;
      var rows = form.querySelectorAll('tbody tr');
      var remaining = 0;
      for (var i = 0; i < rows.length; i++) {
        var submitBtn = rows[i].querySelector('button[name="row_submit"]');
        if (submitBtn && !submitBtn.disabled) {
          remaining++;
        }
      }
      hint.textContent = 'Remaining without overrides: ' + remaining;
    }

    function getNoPunchDataRows() {
      if (!form) return [];
      return form.querySelectorAll('tbody tr[data-row-index]');
    }

    function getNoPunchRowSearchText(tr) {
      if (!tr) return '';
      var cached = tr.getAttribute('data-search-text');
      if (cached !== null) {
        return cached;
      }

      var cells = tr.querySelectorAll('td');
      var text = '';
      for (var i = 0; i < cells.length; i++) {
        text += ' ' + ((cells[i].textContent || '').trim());
      }
      text = text.replace(/\s+/g, ' ').trim().toLowerCase();
      tr.setAttribute('data-search-text', text);
      return text;
    }

    function getOverrideStatusForFilter(tr) {
      if (!tr) return 'not_set';
      var cells = tr.querySelectorAll('td');
      if (!cells || cells.length < 9) return 'not_set';
      var pill = cells[8].querySelector('.status-pill');
      if (!pill) return 'not_set';
      if (pill.classList.contains('is-approved')) return 'approved';
      if (pill.classList.contains('is-pending')) return 'pending';
      if (pill.classList.contains('is-escalated')) return 'rejected';
      return 'not_set';
    }

    function getCampbossStatusForFilter(tr) {
      if (!tr) return 'not_submitted';
      var cells = tr.querySelectorAll('td');
      if (!cells || cells.length < 10) return 'not_submitted';
      var pill = cells[9].querySelector('.status-pill');
      if (!pill) return 'not_submitted';
      if (pill.classList.contains('is-escalated')) return 'escalated';
      if (pill.classList.contains('is-reviewed')) return 'reviewed';
      if (pill.classList.contains('is-submitted')) return 'submitted';
      return 'not_submitted';
    }

    function syncRecordCount(visibleRows, totalRows) {
      var recordCountEl = document.getElementById('noPunchRecordCount');
      if (!recordCountEl) return;
      if (totalRows <= 0) {
        recordCountEl.textContent = '0 records';
        return;
      }
      if (visibleRows === totalRows) {
        recordCountEl.textContent = totalRows + ' records';
        return;
      }
      recordCountEl.textContent = visibleRows + ' of ' + totalRows + ' records';
    }

    function syncTableFilterEmptyState(visibleRows, totalRows) {
      if (!form) return;
      var tbody = form.querySelector('tbody');
      if (!tbody) return;

      var emptyRow = tbody.querySelector('tr[data-filter-empty="1"]');
      if (totalRows > 0 && visibleRows === 0) {
        if (!emptyRow) {
          emptyRow = document.createElement('tr');
          emptyRow.setAttribute('data-filter-empty', '1');
          emptyRow.innerHTML = '<td colspan="10" class="text-center text-muted p-4">No rows match current table filters.</td>';
          tbody.appendChild(emptyRow);
        }
        return;
      }

      if (emptyRow) {
        emptyRow.remove();
      }
    }

    function applyTableFilters() {
      if (!form) return;
      var rows = getNoPunchDataRows();
      var totalRows = rows.length;
      if (totalRows === 0) {
        syncRecordCount(0, 0);
        syncTableFilterEmptyState(0, 0);
        return;
      }

      var searchText = tableSearchInput ? String(tableSearchInput.value || '').trim().toLowerCase() : '';
      var overrideValue = tableOverrideFilter ? String(tableOverrideFilter.value || 'all') : 'all';
      var campbossValue = tableCampbossFilter ? String(tableCampbossFilter.value || 'all') : 'all';

      var visibleRows = 0;
      for (var i = 0; i < rows.length; i++) {
        var tr = rows[i];
        var matchSearch = searchText === '' || getNoPunchRowSearchText(tr).indexOf(searchText) !== -1;
        var matchOverride = overrideValue === 'all' || getOverrideStatusForFilter(tr) === overrideValue;
        var matchCampboss = campbossValue === 'all' || getCampbossStatusForFilter(tr) === campbossValue;
        var show = matchSearch && matchOverride && matchCampboss;
        tr.style.display = show ? '' : 'none';
        if (show) {
          visibleRows++;
        }
      }

      syncTableFilterEmptyState(visibleRows, totalRows);
      syncRecordCount(visibleRows, totalRows);
    }

    function wireTableFilters() {
      if (tableSearchInput && tableSearchInput.dataset.filterBound !== '1') {
        tableSearchInput.dataset.filterBound = '1';
        tableSearchInput.addEventListener('input', function () {
          applyTableFilters();
        });
      }

      if (tableOverrideFilter && tableOverrideFilter.dataset.filterBound !== '1') {
        tableOverrideFilter.dataset.filterBound = '1';
        tableOverrideFilter.addEventListener('change', function () {
          applyTableFilters();
        });
      }

      if (tableCampbossFilter && tableCampbossFilter.dataset.filterBound !== '1') {
        tableCampbossFilter.dataset.filterBound = '1';
        tableCampbossFilter.addEventListener('change', function () {
          applyTableFilters();
        });
      }

      if (tableFilterResetBtn && tableFilterResetBtn.dataset.filterBound !== '1') {
        tableFilterResetBtn.dataset.filterBound = '1';
        tableFilterResetBtn.addEventListener('click', function () {
          if (tableSearchInput) tableSearchInput.value = '';
          if (tableOverrideFilter) tableOverrideFilter.value = 'all';
          if (tableCampbossFilter) tableCampbossFilter.value = 'all';
          applyTableFilters();
        });
      }

      applyTableFilters();
    }

    function submitFilterAjax(submitter) {
      if (!filterForm) return;
      var params = new URLSearchParams(new FormData(filterForm));
      var query = params.toString();
      var url = window.location.pathname + (query ? ('?' + query) : '');

      if (submitter) {
        submitter.disabled = true;
      }

      getPageHtmlAjax(url, function (html) {
        if (!html) {
          showAjaxMessage('Unable to apply filters right now.', true, submitter);
          if (submitter) {
            submitter.disabled = false;
          }
          return;
        }

        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');

        var nextBody = doc.querySelector('.no-punch-table tbody');
        var curBody = document.querySelector('.no-punch-table tbody');
        if (nextBody && curBody) {
          curBody.innerHTML = nextBody.innerHTML;
        }

        var nextRemaining = doc.getElementById('remainingNoPunchCount');
        var curRemaining = document.getElementById('remainingNoPunchCount');
        if (nextRemaining && curRemaining) {
          curRemaining.textContent = nextRemaining.textContent;
        }

        var nextRecordCount = doc.getElementById('noPunchRecordCount');
        var curRecordCount = document.getElementById('noPunchRecordCount');
        if (nextRecordCount && curRecordCount) {
          curRecordCount.textContent = nextRecordCount.textContent;
        }

        var nextCsrf = doc.querySelector('form[method="post"] input[name="csrf"]');
        var curCsrf = document.querySelector('form[method="post"] input[name="csrf"]');
        if (nextCsrf && curCsrf) {
          curCsrf.value = nextCsrf.value || '';
        }

        wireRowInputMutualExclusion();
        wireReasonSelectTitles();
        updateRemainingCountHint();
        wireTableFilters();
        window.history.replaceState({}, '', url);
        showAjaxMessage('Filters applied.', false, submitter);

        if (submitter) {
          submitter.disabled = false;
        }
      });
    }

    function refreshRowsAfterBulkSave() {
      if (!form) return;
      var rows = form.querySelectorAll('tbody tr');
      for (var i = 0; i < rows.length; i++) {
        var tr = rows[i];
        var rowHours = tr.querySelector('input[name="work_hours[]"]');
        var rowCode = tr.querySelector('input[name="work_code[]"]');
        if (!rowHours || !rowCode) continue;
        if (rowHours.readOnly || rowHours.disabled || rowCode.readOnly || rowCode.disabled) {
          continue;
        }
        var rowIndex = resolveRowIndex(tr);
        if (rowIndex === '') continue;
        renderRowForHrWorkflow(tr, rowIndex);
      }
      updateRemainingCountHint();
      applyTableFilters();
    }

    function refreshRowsAfterBulkCampbossSubmit() {
      if (!form) return;
      var rows = form.querySelectorAll('tbody tr');
      for (var i = 0; i < rows.length; i++) {
        var tr = rows[i];
        var submitBtn = tr.querySelector('button[name="row_submit"]');
        if (!submitBtn || submitBtn.disabled) {
          continue;
        }
        renderRowForCampbossWorkflow(tr);
      }
      updateRemainingCountHint();
      applyTableFilters();
    }

    function submitRowActionAjax(submitter, extraFields) {
      if (!form || !submitter) return;
      var tr = submitter.closest('tr');
      if (!tr) return;

      var rowIndex = String(submitter.value || '').trim();
      var formData = new FormData(form);
      formData.append('ajax', '1');
      formData.append(submitter.name, rowIndex);
      if (Array.isArray(extraFields)) {
        for (var fieldIndex = 0; fieldIndex < extraFields.length; fieldIndex++) {
          var field = extraFields[fieldIndex] || {};
          var fieldName = String(field.name || '').trim();
          if (fieldName === '') continue;
          if (field.mode === 'append') {
            formData.append(fieldName, field.value);
          } else {
            formData.set(fieldName, field.value);
          }
        }
      }

      submitter.disabled = true;

      postRowAjax(formData, function (data) {
        if (!data || data.ok !== true) {
          showAjaxMessage((data && data.message) ? data.message : 'Unable to process request.', true, submitter);
          submitter.disabled = false;
          return;
        }

        showAjaxMessage(data.message || 'Done.', false, submitter);

        if (submitter.name === 'row_submit') {
          renderRowForCampbossWorkflow(tr);
          updateRemainingCountHint();
          applyTableFilters();
          submitter.disabled = false;
          return;
        }

        renderRowForHrWorkflow(tr, rowIndex);
        updateRemainingCountHint();
        applyTableFilters();
        submitter.disabled = false;
      });

      setTimeout(function () {
        var liveBtn = tr.querySelector('button[name="' + submitter.name + '"][value="' + rowIndex + '"]');
        if (liveBtn) {
          liveBtn.disabled = false;
        }
      }, 0);
    }

    function submitBulkActionAjax(submitter) {
      if (!form || !submitter) return;
      var actionValue = String(submitter.value || '').trim();
      if (actionValue === '') return;

      var formData = new FormData(form);
      formData.append('ajax', '1');
      if (submitter.name) {
        formData.append(submitter.name, actionValue);
      }

      submitter.disabled = true;

      postRowAjax(formData, function (data) {
        var ok = !!(data && data.ok === true);
        var message = (data && data.message) ? data.message : (ok ? 'Done.' : 'Unable to process request.');
        showAjaxMessage(message, !ok, submitter);

        if (ok) {
          if (actionValue === 'save_overrides' || actionValue === 'submit_to_hr') {
            refreshRowsAfterBulkSave();
          } else if (actionValue === 'submit_to_campboss') {
            refreshRowsAfterBulkCampbossSubmit();
          }
        }

        submitter.disabled = false;
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        var submitter = e.submitter || document.activeElement;
        if (!submitter) return;

        if (submitter.name === 'action' && submitter.value === 'save_overrides') {
          var rows = form.querySelectorAll('tbody tr');
          var sickSaveRow = findEditableSickReasonRow(rows);
          if (sickSaveRow) {
            var sickSaveReason = sickSaveRow.querySelector('select[name="override_reason_code[]"]');
            showValidationError('For SICK reason, use row "Send to HR" so note and certificate can be attached.');
            if (sickSaveReason) {
              sickSaveReason.focus();
            }
            e.preventDefault();
            return;
          }
          for (var i = 0; i < rows.length; i++) {
            if (!validateOverrideRow(rows[i])) {
              e.preventDefault();
              return;
            }
          }
          if (!canDoAjaxSubmission()) {
            e.preventDefault();
            showAjaxMessage('Inline submit is not supported in this browser.', true, submitter);
            return;
          }
          e.preventDefault();
          submitBulkActionAjax(submitter);
          return;
        }

        if (submitter.name === 'action' && submitter.value === 'submit_to_hr') {
          var hrRows = form.querySelectorAll('tbody tr');
          var sickHrRow = findEditableSickReasonRow(hrRows);
          if (sickHrRow) {
            var sickHrReason = sickHrRow.querySelector('select[name="override_reason_code[]"]');
            showValidationError('For SICK reason, use row "Send to HR" so note and certificate can be attached.');
            if (sickHrReason) {
              sickHrReason.focus();
            }
            e.preventDefault();
            return;
          }
          for (var j = 0; j < hrRows.length; j++) {
            if (!validateOverrideRow(hrRows[j])) {
              e.preventDefault();
              return;
            }
          }
          if (!canDoAjaxSubmission()) {
            e.preventDefault();
            showAjaxMessage('Inline submit is not supported in this browser.', true, submitter);
            return;
          }
          e.preventDefault();
          submitBulkActionAjax(submitter);
          return;
        }

        if (submitter.name === 'action' && submitter.value === 'submit_to_campboss') {
          if (!canDoAjaxSubmission()) {
            e.preventDefault();
            showAjaxMessage('Inline submit is not supported in this browser.', true, submitter);
            return;
          }
          e.preventDefault();
          submitBulkActionAjax(submitter);
          return;
        }

        if (submitter.name === 'row_save') {
          var saveRow = submitter.closest('tr');
          var saveReason = saveRow ? saveRow.querySelector('select[name="override_reason_code[]"]') : null;
          var isSickRowSave = saveReason ? isSickReason(saveReason.value || '') : false;
          if (!validateOverrideRow(saveRow)) {
            e.preventDefault();
            return;
          }
          if (!canDoAjaxSubmission()) {
            e.preventDefault();
            showAjaxMessage('Inline submit is not supported in this browser.', true, submitter);
            return;
          }
          e.preventDefault();
          if (isSickRowSave) {
            if (!openMedicalModal(saveRow, submitter)) {
              showAjaxMessage('Unable to open sick leave details popup.', true, submitter);
            }
            return;
          }
          submitRowActionAjax(submitter, null);
          return;
        }

        if (submitter.name === 'row_submit') {
          var submitRow = submitter.closest('tr');
          if (!validateSubmitRow(submitRow)) {
            e.preventDefault();
            return;
          }
          if (!canDoAjaxSubmission()) {
            e.preventDefault();
            showAjaxMessage('Inline submit is not supported in this browser.', true, submitter);
            return;
          }
          e.preventDefault();
          submitRowActionAjax(submitter, null);
          return;
        }
      });
    }
    if (filterForm) {
      filterForm.addEventListener('submit', function (e) {
        var submitter = e.submitter || filterForm.querySelector('button[type="submit"]');
        if (!canDoAjaxSubmission()) {
          e.preventDefault();
          showAjaxMessage('Inline submit is not supported in this browser.', true, submitter);
          return;
        }
        e.preventDefault();
        submitFilterAjax(submitter);
      });
    }

    function wireRowInputMutualExclusion() {
      var dataRows = document.querySelectorAll('tbody tr');
      for (var k = 0; k < dataRows.length; k++) {
        var row = dataRows[k];
        var hoursInput = row.querySelector('input[name="work_hours[]"]');
        var codeInput = row.querySelector('input[name="work_code[]"]');
        if (!hoursInput || !codeInput) continue;
        if (hoursInput.dataset.noPunchBound === '1') {
          continue;
        }
        hoursInput.dataset.noPunchBound = '1';
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
    }

    function wireReasonSelectTitles() {
      var reasonSelects = document.querySelectorAll('select.js-override-reason');
      for (var j = 0; j < reasonSelects.length; j++) {
        (function (sel) {
          var tr = sel.closest('tr');
          setSelectTitle(sel);
          if (sel.dataset.reasonBound === '1') {
            return;
          }
          sel.dataset.reasonBound = '1';
          sel.addEventListener('change', function () {
            setSelectTitle(sel);
            applyReasonDefaultsToRow(tr, sel);
          });
        })(reasonSelects[j]);
      }
    }

    wireRowInputMutualExclusion();
    wireReasonSelectTitles();
    wireTableFilters();

    function applyBulkOverride() {
      var hoursEl = document.getElementById('bulkOverrideHours');
      var reasonEl = document.getElementById('bulkOverrideReason');
      var overwriteEl = document.getElementById('bulkOverrideOverwrite');
      if (!hoursEl || !reasonEl) return;

      var hoursVal = (hoursEl.value || '').trim();
      var reasonVal = (reasonEl.value || '').trim();
      var overwrite = overwriteEl && overwriteEl.checked;

      if (hoursVal === '') {
        showValidationError('Enter bulk override hours.');
        hoursEl.focus();
        return;
      }

      var n = Number(hoursVal);
      if (!isFinite(n) || n < 0 || n > 24) {
        showValidationError('Hours must be a number between 0 and 24.');
        hoursEl.focus();
        return;
      }

      if (reasonVal === '') {
        showValidationError('Select a reason for bulk hour overrides.');
        reasonEl.focus();
        return;
      }

      // Normalize to 2 decimals for consistency with server-side formatting.
      var normalizedHours = n.toFixed(2);

      var rows = document.querySelectorAll('tbody tr');
      var applied = 0;
      for (var i = 0; i < rows.length; i++) {
        var tr = rows[i];
        var rowHours = tr.querySelector('input[name=\"work_hours[]\"]');
        var rowCode = tr.querySelector('input[name=\"work_code[]\"]');
        var rowReason = tr.querySelector('select[name=\"override_reason_code[]\"]');
        if (!rowHours || !rowCode || !rowReason) continue;

        if (rowHours.readOnly || rowHours.disabled || rowCode.readOnly || rowCode.disabled || rowReason.disabled) {
          continue;
        }

        if (!overwrite) {
          var existingHours = (rowHours.value || '').trim();
          var existingCode = (rowCode.value || '').trim();
          var existingReason = (rowReason.value || '').trim();
          if (existingHours !== '' || existingCode !== '' || existingReason !== '') {
            continue;
          }
        }

        rowHours.value = normalizedHours;
        rowCode.value = '';
        rowReason.value = reasonVal;
        setSelectTitle(rowReason);
        applied++;
      }

      if (applied === 0) {
        showValidationError('No editable rows found to apply the bulk override.');
      }
    }

    var bulkApplyBtn = document.getElementById('bulkOverrideApply');
    if (bulkApplyBtn) {
      bulkApplyBtn.addEventListener('click', function () {
        applyBulkOverride();
      });
    }

    if (medicalFileInput && medicalFileInput.dataset.bound !== '1') {
      medicalFileInput.dataset.bound = '1';
      medicalFileInput.addEventListener('change', function () {
        if (medicalFileError) {
          medicalFileError.classList.add('d-none');
        }
      });
    }
    if (medicalNoteInput && medicalNoteInput.dataset.bound !== '1') {
      medicalNoteInput.dataset.bound = '1';
      medicalNoteInput.addEventListener('input', function () {
        if (medicalNoteError) {
          medicalNoteError.classList.add('d-none');
        }
      });
    }
    for (var modalCancelIdx = 0; modalCancelIdx < medicalCancelButtons.length; modalCancelIdx++) {
      var cancelButton = medicalCancelButtons[modalCancelIdx];
      if (cancelButton.dataset.bound === '1') {
        continue;
      }
      cancelButton.dataset.bound = '1';
      cancelButton.addEventListener('click', function () {
        hideMedicalModal();
      });
    }
    if (medicalModal && medicalModal.dataset.bound !== '1') {
      medicalModal.dataset.bound = '1';
      medicalModal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          hideMedicalModal();
        }
      });
    }
    if (medicalConfirmButton && medicalConfirmButton.dataset.bound !== '1') {
      medicalConfirmButton.dataset.bound = '1';
      medicalConfirmButton.addEventListener('click', function () {
        if (!medicalModalState) {
          hideMedicalModal();
          return;
        }
        var noteText = medicalNoteInput ? String(medicalNoteInput.value || '').trim() : '';
        var fileObject = medicalFileInput && medicalFileInput.files ? medicalFileInput.files[0] : null;

        if (noteText === '') {
          if (medicalNoteError) {
            medicalNoteError.textContent = 'Medical note is required.';
            medicalNoteError.classList.remove('d-none');
          }
          if (medicalNoteInput) {
            medicalNoteInput.focus();
          }
          return;
        }
        if (!fileObject) {
          if (medicalFileError) {
            medicalFileError.textContent = 'Medical certificate file is required.';
            medicalFileError.classList.remove('d-none');
          }
          if (medicalFileInput) {
            medicalFileInput.focus();
          }
          return;
        }
        if (Number(fileObject.size || 0) > (5 * 1024 * 1024)) {
          if (medicalFileError) {
            medicalFileError.textContent = 'Medical certificate must be 5 MB or smaller.';
            medicalFileError.classList.remove('d-none');
          }
          if (medicalFileInput) {
            medicalFileInput.focus();
          }
          return;
        }

        var state = medicalModalState;
        var rowReasonNote = state.row ? state.row.querySelector('input.js-override-reason-note') : null;
        if (rowReasonNote) {
          rowReasonNote.value = noteText;
        }
        hideMedicalModal();
        submitRowActionAjax(state.submitter, [
          { name: 'medical_target_index', value: String(state.rowIndex) },
          { name: 'medical_popup_note', value: noteText },
          { name: 'medical_certificate_file', value: fileObject, mode: 'append' }
        ]);
      });
    }

  })();
</script>

<?php include __DIR__ . '/../admin/include/layout_bottom.php'; ?>
