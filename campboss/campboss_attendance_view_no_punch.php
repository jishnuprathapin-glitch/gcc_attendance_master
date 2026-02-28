<?php

require dirname(__DIR__) . '/admin/include/bootstrap.php';

$page_title = 'Camp Boss No Punch Review';

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

function load_campboss_camps(mysqli $bd, string $userId): array {
    if ($userId === '') {
        return [];
    }
    $camps = [];
    $stmt = $bd->prepare(
        'SELECT camp_code FROM gcc_attendance_master.campboss_camp_map WHERE user_id = ? ORDER BY camp_code'
    );
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $code = strtoupper(trim((string) ($row['camp_code'] ?? '')));
                    if ($code !== '') {
                        $camps[] = $code;
                    }
                }
                $result->free();
            }
        }
        $stmt->close();
    }
    return $camps;
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

function load_campboss_employee_context(mysqli $bd, array $empCodes): array {
    $context = [];
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
        return $context;
    }

    $placeholders = implode(',', array_fill(0, count($cleanCodes), '?'));
    $types = str_repeat('s', count($cleanCodes));
    $sql = 'SELECT emp_code, jbno, ty_cd FROM gcc_attendance_master.hrmsvw_sync WHERE emp_code IN (' . $placeholders . ')';
    $stmt = $bd->prepare($sql);
    if (!$stmt) {
        return $context;
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
                $context[$empCode] = [
                    'project_code' => trim((string) ($row['jbno'] ?? '')),
                    'employee_type_code' => strtoupper(trim((string) ($row['ty_cd'] ?? ''))),
                ];
            }
            $result->free();
        }
    }
    $stmt->close();
    return $context;
}

function derive_campboss_override_values(string $workHoursAction, bool $isStaff, array $reasonMeta): array {
    $defaultHours = derive_reason_default_hours($reasonMeta, $isStaff);
    if ($defaultHours !== null) {
        return [$defaultHours, null];
    }

    $behavior = strtoupper(trim((string) ($reasonMeta['default_behavior'] ?? 'NONE')));
    if ($behavior === 'WORK_CODE') {
        $defaultWorkCode = normalize_work_type_code($reasonMeta['default_work_code'] ?? null);
        if ($defaultWorkCode !== null) {
            return [null, $defaultWorkCode];
        }
    }

    $fullDay = $isStaff ? 8.0 : 10.0;
    $halfDay = $isStaff ? 4.0 : 5.0;
    $action = strtoupper(trim($workHoursAction));
    if ($action === 'FULL_DAY') {
        return [number_format($fullDay, 2, '.', ''), null];
    }
    if ($action === 'HALF_DAY') {
        return [number_format($halfDay, 2, '.', ''), null];
    }
    if ($action === 'NO_HOURS') {
        return ['0.00', null];
    }

    return [null, null];
}

function resolve_campboss_reason_mode(string $reasonCode, ?array $reasonMeta = null): string {
    $code = strtoupper(trim((string) $reasonCode));
    $behavior = strtoupper(trim((string) ($reasonMeta['default_behavior'] ?? 'NONE')));
    $defaultWorkCode = normalize_work_type_code($reasonMeta['default_work_code'] ?? null);
    if ($code === 'EMP_ABSCONDING') {
        return 'ESCALATE_ONLY';
    }
    if ($behavior === 'WORK_CODE' && $defaultWorkCode !== null) {
        return 'SAVE_ONLY';
    }
    if (in_array($code, ['EMP_NO_SHOW', 'EMP_RESIGNED', 'NOT_IN_CAMP'], true)) {
        return 'ESCALATE_ONLY';
    }
    if (in_array($code, ['MED', 'VISA_MED', 'SICK', 'VISA', 'VISA_OTH', 'BIO_VISA', 'VISA_BIO', 'VISA_TAWJEEH'], true)) {
        return 'SAVE_ONLY_FULL_DAY';
    }
    if ($code === 'OTH') {
        return 'BOTH';
    }
    if ($reasonMeta && ((bool) ($reasonMeta['auto_escalate'] ?? false))) {
        return 'ESCALATE_ONLY';
    }
    return 'SAVE_ONLY';
}

function is_campboss_sick_leave_reason(string $reasonCode): bool {
    return strtoupper(trim($reasonCode)) === 'SICK';
}

function upload_campboss_medical_certificate(array $uploadFile, string $empCode, string $attDate): array {
    return upload_attendance_medical_certificate($uploadFile, $empCode, $attDate);
}

function is_ajax_post_request(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }
    if (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1') {
        return true;
    }
    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    return $requestedWith === 'xmlhttprequest';
}

function send_json_response(array $payload, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$uaeTz = new DateTimeZone('Asia/Dubai');
$todayUae = (new DateTimeImmutable('now', $uaeTz))->format('Y-m-d');

$selectedDate = normalize_date($_GET['date'] ?? '', $todayUae);
$campFilter = normalize_multi_param($_GET['camp_code'] ?? ($_GET['project_code'] ?? []));
$campFilter = array_values(array_filter(array_map(static function ($code): string {
    return strtoupper(trim((string) $code));
}, $campFilter), static function ($code): bool {
    return $code !== '';
}));
$reviewStatusFilter = strtolower(trim((string) ($_GET['review_status'] ?? '')));
$validReviewStatuses = ['' => true, 'pending' => true, 'reviewed' => true, 'escalated' => true];
if (!isset($validReviewStatuses[$reviewStatusFilter])) {
    $reviewStatusFilter = '';
}

$userId = trim((string) ($_SESSION['user_id'] ?? ''));
$userName = trim((string) ($_SESSION['user_name'] ?? ''));
$userEmail = trim((string) ($_SESSION['user_email'] ?? ''));

$loadError = null;
$mappingRequired = false;
$mappedCamps = [];
$campOptions = [];
$filterCampOptions = [];
$reasonOptions = [];
$workTypeOptions = [];
$rows = [];
$rowsByProject = [];
$flash = get_flash();
$reviewedCount = 0;
$escalatedCount = 0;

if (!isset($bd) || !($bd instanceof mysqli)) {
    $loadError = 'Database connection not available.';
} else {
    if (!ensure_campboss_camp_map_table($bd)) {
        $loadError = 'Unable to load camp boss camp access.';
    } else {
        $mappedCamps = load_campboss_camps($bd, $userId);
        if (empty($mappedCamps)) {
            $mappingRequired = true;
        }
    }

    if (!$loadError) {
        ensure_no_punch_review_table($bd);
        ensure_no_punch_reason_table($bd);
        ensure_attendance_medical_certificate_table($bd);
    }

    $campResult = $bd->query(
        'SELECT camp_code, camp_name FROM gcc_attendance_master.hrms_camp_sync WHERE is_deleted = 0 ORDER BY camp_code'
    );
    if ($campResult) {
        while ($row = $campResult->fetch_assoc()) {
            $code = strtoupper(trim((string) ($row['camp_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $campOptions[$code] = trim((string) ($row['camp_name'] ?? ''));
        }
        $campResult->free();
    }

    $reasonOptions = load_no_punch_reason_options($bd, 'campboss');

    $workTypeOptions = load_work_type_options($bd);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$mappingRequired) {
    $isAjaxRequest = is_ajax_post_request();
    $action = $_POST['action'] ?? '';
    $rowSaveReview = $_POST['row_save_review'] ?? null;
    $rowEscalateHr = $_POST['row_escalate_hr'] ?? null;
    $responseType = 'warning';
    $responseMessage = 'No action requested.';
    $responseErrors = [];
    $processedRows = [];
    $updated = 0;

    if ($rowSaveReview !== null) {
        $action = 'row_save_review';
    } elseif ($rowEscalateHr !== null) {
        $action = 'row_escalate_hr';
    }

    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $responseType = 'warning';
        $responseMessage = 'Invalid request token. Please try again.';
        $responseErrors[] = $responseMessage;
    } elseif (in_array($action, ['save_reviews', 'row_save_review', 'row_escalate_hr'], true)) {
        if (!ensure_no_punch_review_table($bd)) {
            $responseType = 'warning';
            $responseMessage = 'Review table not available.';
            $responseErrors[] = $responseMessage;
        } else {
            $empCodes = $_POST['emp_code'] ?? [];
            $attDates = $_POST['att_date'] ?? [];
            $reasonCodes = $_POST['reason_code'] ?? [];
            $notes = $_POST['campboss_note'] ?? [];
            $submittedFlags = $_POST['is_submitted'] ?? [];

            if (!is_array($empCodes)) {
                $empCodes = [$empCodes];
            }
            if (!is_array($attDates)) {
                $attDates = [$attDates];
            }
            if (!is_array($reasonCodes)) {
                $reasonCodes = [$reasonCodes];
            }
            if (!is_array($notes)) {
                $notes = [$notes];
            }
            if (!is_array($submittedFlags)) {
                $submittedFlags = [$submittedFlags];
            }

            $workHourActions = $_POST['work_hours_action'] ?? [];
            $transferCampCodes = $_POST['transfer_to_camp_code'] ?? [];
            $medicalTargetIndex = is_scalar($_POST['medical_target_index'] ?? null)
                ? (int) $_POST['medical_target_index']
                : -1;
            $medicalPopupNote = trim((string) ($_POST['medical_popup_note'] ?? ''));
            $medicalUpload = $_FILES['medical_certificate_file'] ?? null;
            if (!is_array($workHourActions)) {
                $workHourActions = [$workHourActions];
            }
            if (!is_array($transferCampCodes)) {
                $transferCampCodes = [$transferCampCodes];
            }

            $max = max(count($empCodes), count($attDates), count($reasonCodes), count($notes), count($submittedFlags), count($workHourActions), count($transferCampCodes), 1);
            $changeDate = gmdate('Y-m-d H:i:s');
            $employeeContext = load_campboss_employee_context($bd, $empCodes);
            $singleRowAction = in_array($action, ['row_save_review', 'row_escalate_hr'], true);
            $forceEscalateToHr = ($action === 'row_escalate_hr');
            $targetIndexes = [];
            if ($singleRowAction) {
                $selectedIndex = $action === 'row_save_review'
                    ? (is_scalar($rowSaveReview) ? (int) $rowSaveReview : -1)
                    : (is_scalar($rowEscalateHr) ? (int) $rowEscalateHr : -1);
                if ($selectedIndex >= 0 && $selectedIndex < $max) {
                    $targetIndexes[] = $selectedIndex;
                }
            } else {
                for ($i = 0; $i < $max; $i++) {
                    $targetIndexes[] = $i;
                }
            }

            $reviewSql = 'INSERT INTO `gcc_attendance_master`.`attendance_no_punch_reviews` ' .
                '(emp_code, att_date, campboss_reason_code, campboss_note, campboss_email, campboss_name, campboss_reviewed_at, is_escalated, escalated_at, transfer_to_camp_code, transfer_to_camp_name, auto_escalated_reason) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE ' .
                'campboss_reason_code = VALUES(campboss_reason_code), ' .
                'campboss_note = VALUES(campboss_note), ' .
                'campboss_email = VALUES(campboss_email), ' .
                'campboss_name = VALUES(campboss_name), ' .
                'campboss_reviewed_at = VALUES(campboss_reviewed_at), ' .
                'is_escalated = VALUES(is_escalated), ' .
                'escalated_at = VALUES(escalated_at), ' .
                'transfer_to_camp_code = VALUES(transfer_to_camp_code), ' .
                'transfer_to_camp_name = VALUES(transfer_to_camp_name), ' .
                'auto_escalated_reason = VALUES(auto_escalated_reason)';
            $reviewStmt = $bd->prepare($reviewSql);

            $overrideSql = 'INSERT INTO `gcc_attendance_master`.`employee_att_daily_overrides` ' .
                '(emp_code, att_date, override_work_hours, override_work_code, override_reason_code, override_reason_note, override_change_date, ' .
                'override_changed_by_email, override_changed_by_name, override_is_approved, override_approved_by_email, override_approved_by_name, override_approved_date) ' .
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
            $overrideStmt = $bd->prepare($overrideSql);
            $deleteOverrideStmt = $bd->prepare(
                'DELETE FROM `gcc_attendance_master`.`employee_att_daily_overrides` WHERE emp_code = ? AND att_date = ?'
            );

            $errors = [];
            $validWorkHourActions = ['FULL_DAY' => true, 'HALF_DAY' => true, 'NO_HOURS' => true];
            if ($singleRowAction && empty($targetIndexes)) {
                $errors[] = 'Invalid row selection.';
            }

            foreach ($targetIndexes as $i) {
                $empCode = trim((string) ($empCodes[$i] ?? ''));
                $attDate = trim((string) ($attDates[$i] ?? ''));
                if ($empCode === '' || $attDate === '') {
                    continue;
                }
                $isAlreadySubmitted = (string) ($submittedFlags[$i] ?? '0') === '1';
                if (!$singleRowAction && $isAlreadySubmitted) {
                    continue;
                }

                $reasonCode = strtoupper(trim((string) ($reasonCodes[$i] ?? '')));
                if ($reasonCode === '') {
                    if ($singleRowAction) {
                        $errors[] = 'Select a reason for ' . $empCode . ' on ' . $attDate . '.';
                    }
                    continue;
                }
                $reasonMeta = $reasonOptions[$reasonCode] ?? null;
                if (!$reasonMeta) {
                    $errors[] = 'Invalid reason "' . $reasonCode . '" for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
                }

                $note = trim((string) ($notes[$i] ?? ''));
                if ($note !== '') {
                    $note = substr($note, 0, 255);
                } else {
                    $note = null;
                }

                $medicalNote = null;
                $medicalCertificatePath = null;
                $medicalCertificateName = null;
                if (is_campboss_sick_leave_reason($reasonCode)) {
                    if (!$singleRowAction || $action !== 'row_save_review' || $i !== $medicalTargetIndex) {
                        $errors[] = 'Use row "Save Reason" for sick leave with medical certificate attachment for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }

                    $medicalNoteRaw = $medicalPopupNote !== '' ? $medicalPopupNote : trim((string) ($notes[$i] ?? ''));
                    if ($medicalNoteRaw === '') {
                        $errors[] = 'Medical note is required for sick leave for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                    $medicalNote = substr($medicalNoteRaw, 0, 500);
                    $note = substr($medicalNoteRaw, 0, 255);

                    $uploadResult = upload_campboss_medical_certificate(
                        is_array($medicalUpload) ? $medicalUpload : [],
                        $empCode,
                        $attDate
                    );
                    if (!$uploadResult['ok']) {
                        $errors[] = (string) ($uploadResult['error'] ?? 'Medical certificate upload failed.') . ' [' . $empCode . ' / ' . $attDate . ']';
                        continue;
                    }
                    $medicalCertificatePath = (string) ($uploadResult['path'] ?? '');
                    $medicalCertificateName = (string) ($uploadResult['name'] ?? '');
                }

                $workHoursAction = strtoupper(trim((string) ($workHourActions[$i] ?? '')));
                if ($workHoursAction !== '' && !isset($validWorkHourActions[$workHoursAction])) {
                    $workHoursAction = '';
                }

                $reasonMode = resolve_campboss_reason_mode($reasonCode, $reasonMeta);
                if ($singleRowAction && $action === 'row_save_review' && $reasonMode === 'ESCALATE_ONLY') {
                    $errors[] = 'Reason ' . $reasonCode . ' requires escalation to HR for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
                }
                if ($singleRowAction && $action === 'row_escalate_hr' && !in_array($reasonMode, ['ESCALATE_ONLY', 'BOTH'], true)) {
                    $errors[] = 'Reason ' . $reasonCode . ' should be saved (not escalated) for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
                }

                $requiresTransfer = ((bool) ($reasonMeta['requires_transfer_project'] ?? false));
                $reviewAction = 'REPORT_TO_HR';
                if ($reasonMode === 'ESCALATE_ONLY' || ($forceEscalateToHr && $reasonMode === 'BOTH')) {
                    $reviewAction = 'ESCALATE';
                }
                if ($reviewAction === 'ESCALATE') {
                    $workHoursAction = 'NO_HOURS';
                } elseif ($reasonMode === 'SAVE_ONLY_FULL_DAY') {
                    $workHoursAction = 'FULL_DAY';
                }

                $transferToCampCode = null;
                $transferToCampName = null;
                $transferInput = strtoupper(trim((string) ($transferCampCodes[$i] ?? '')));
                if ($requiresTransfer) {
                    if ($transferInput === '') {
                        $errors[] = 'Transfer destination is required for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                    if (!isset($campOptions[$transferInput])) {
                        $errors[] = 'Invalid transfer camp "' . $transferInput . '" for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                    $transferToCampCode = $transferInput;
                    $transferToCampName = trim((string) ($campOptions[$transferInput] ?? ''));
                }

                $empContext = $employeeContext[$empCode] ?? [];
                $isStaff = is_staff_employee_type($empContext['employee_type_code'] ?? null);
                [$overrideHours, $overrideCode] = [null, null];
                if ($reviewAction === 'REPORT_TO_HR') {
                    [$overrideHours, $overrideCode] = derive_campboss_override_values($workHoursAction, $isStaff, $reasonMeta);

                    if ($overrideHours !== null && $overrideHours !== '') {
                        if (!is_numeric($overrideHours)) {
                            $errors[] = 'Invalid work hour output for ' . $empCode . ' on ' . $attDate . '.';
                            continue;
                        }
                        $hoursFloat = (float) $overrideHours;
                        if ($hoursFloat < 0 || $hoursFloat > 24) {
                            $errors[] = 'Hours must be between 0 and 24 for ' . $empCode . ' on ' . $attDate . '.';
                            continue;
                        }
                        $overrideHours = number_format($hoursFloat, 2, '.', '');
                    } else {
                        $overrideHours = null;
                    }

                    if ($overrideCode !== null) {
                        if (empty($workTypeOptions) || !isset($workTypeOptions[$overrideCode])) {
                            $errors[] = 'Reason ' . $reasonCode . ' has invalid work code "' . $overrideCode . '".';
                            continue;
                        }
                    }

                    if ($overrideHours === null && $overrideCode === null) {
                        $errors[] = 'Select a valid work hour action for ' . $empCode . ' on ' . $attDate . '.';
                        continue;
                    }
                }

                $isEscalated = $reviewAction === 'ESCALATE' ? 1 : 0;
                $escalatedAt = $isEscalated === 1 ? $changeDate : null;
                $autoEscalatedReason = $reasonMode === 'ESCALATE_ONLY' ? 1 : 0;

                $emailParam = $userEmail !== '' ? $userEmail : null;
                $nameParam = $userName !== '' ? $userName : null;
                $reasonParam = $reasonCode;
                $noteParam = $note;
                $reviewedAt = $changeDate;

                if (!$reviewStmt) {
                    $errors[] = 'Unable to prepare review statement.';
                    break;
                }
                $reviewStmt->bind_param(
                    'sssssssisssi',
                    $empCode,
                    $attDate,
                    $reasonParam,
                    $noteParam,
                    $emailParam,
                    $nameParam,
                    $reviewedAt,
                    $isEscalated,
                    $escalatedAt,
                    $transferToCampCode,
                    $transferToCampName,
                    $autoEscalatedReason
                );
                if (!$reviewStmt->execute()) {
                    $errors[] = 'Unable to save review for ' . $empCode . ' on ' . $attDate . '.';
                    continue;
                }
                $updated++;
                $rowHadError = false;
                $rowOverrideHours = null;
                $rowOverrideCode = null;

                if ($reviewAction === 'REPORT_TO_HR') {
                    if (!$overrideStmt) {
                        $errors[] = 'Unable to prepare override statement.';
                        $rowHadError = true;
                        continue;
                    }
                    $approved = 0;
                    $approvedByEmail = null;
                    $approvedByName = null;
                    $approvedDate = null;
                    $overrideReasonCode = $reasonCode;
                    $overrideReasonNote = $note;
                    $overrideStmt->bind_param(
                        'sssssssssisss',
                        $empCode,
                        $attDate,
                        $overrideHours,
                        $overrideCode,
                        $overrideReasonCode,
                        $overrideReasonNote,
                        $changeDate,
                        $emailParam,
                        $nameParam,
                        $approved,
                        $approvedByEmail,
                        $approvedByName,
                        $approvedDate
                    );
                    if (!$overrideStmt->execute()) {
                        $errors[] = 'Unable to save override for ' . $empCode . ' on ' . $attDate . '.';
                        $rowHadError = true;
                    } else {
                        $rowOverrideHours = $overrideHours;
                        $rowOverrideCode = $overrideCode;
                    }
                } else {
                    if ($deleteOverrideStmt) {
                        $deleteOverrideStmt->bind_param('ss', $empCode, $attDate);
                        if (!$deleteOverrideStmt->execute()) {
                            $errors[] = 'Unable to clear override for escalated row ' . $empCode . ' on ' . $attDate . '.';
                            $rowHadError = true;
                        }
                    }
                }

                if (!$rowHadError) {
                    if (is_campboss_sick_leave_reason($reasonCode)) {
                        $savedMedical = upsert_attendance_medical_certificate(
                            $bd,
                            $empCode,
                            $attDate,
                            $medicalNote,
                            (string) $medicalCertificatePath,
                            $medicalCertificateName,
                            'campboss',
                            $emailParam,
                            $nameParam,
                            $changeDate
                        );
                        if (!$savedMedical) {
                            $errors[] = 'Unable to save medical certificate details for ' . $empCode . ' on ' . $attDate . '.';
                            $rowHadError = true;
                        }
                    } else {
                        $clearedMedical = delete_attendance_medical_certificate($bd, $empCode, $attDate);
                        if (!$clearedMedical) {
                            $errors[] = 'Unable to clear medical certificate details for ' . $empCode . ' on ' . $attDate . '.';
                            $rowHadError = true;
                        }
                    }
                }

                if (!$rowHadError) {
                    $processedRows[] = [
                        'index' => $i,
                        'status_label' => $isEscalated === 1 ? 'Escalated' : 'Reviewed',
                        'status_class' => $isEscalated === 1 ? 'is-escalated' : 'is-reviewed',
                        'review_action' => $reviewAction,
                        'override_hours' => $rowOverrideHours,
                        'override_code' => $rowOverrideCode,
                    ];
                }
            }

            if ($reviewStmt) {
                $reviewStmt->close();
            }
            if ($overrideStmt) {
                $overrideStmt->close();
            }
            if ($deleteOverrideStmt) {
                $deleteOverrideStmt->close();
            }

            if (!empty($errors)) {
                $responseType = 'warning';
                $responseErrors = $errors;
                if ($updated > 0) {
                    $responseMessage = 'Processed ' . $updated . ' row(s) with errors. ' . implode(' ', $errors);
                } else {
                    $responseMessage = implode(' ', $errors);
                }
            } else {
                $responseType = 'success';
                if ($action === 'row_save_review') {
                    $responseMessage = 'Review saved.';
                } elseif ($action === 'row_escalate_hr') {
                    $responseMessage = 'Escalated to HR.';
                } else {
                    $responseMessage = 'Saved ' . $updated . ' review(s).';
                }
            }
        }
    } else {
        $responseType = 'warning';
        $responseMessage = 'Unsupported action.';
        $responseErrors[] = $responseMessage;
    }

    if ($isAjaxRequest) {
        send_json_response([
            'ok' => empty($responseErrors),
            'type' => $responseType,
            'message' => $responseMessage,
            'errors' => $responseErrors,
            'updated' => $updated,
            'action' => $action,
            'processed_rows' => $processedRows,
            'csrf' => csrf_token(),
        ]);
    }

    set_flash($responseType, $responseMessage);

    $redirectParams = ['date' => $selectedDate];
    if (!empty($campFilter)) {
        $redirectParams['camp_code'] = $campFilter;
    }
    if ($reviewStatusFilter !== '') {
        $redirectParams['review_status'] = $reviewStatusFilter;
    }
    $url = admin_url('campboss_attendance_view_no_punch.php');
    if (!empty($redirectParams)) {
        $url .= '?' . http_build_query($redirectParams);
    }
    header('Location: ' . $url);
    exit;
}

if (!$loadError && !$mappingRequired) {
    if (!empty($campFilter)) {
        $campFilter = array_values(array_intersect($campFilter, $mappedCamps));
    }
    if (!empty($mappedCamps)) {
        $mappedSet = array_fill_keys($mappedCamps, true);
        $filterCampOptions = array_intersect_key($campOptions, $mappedSet);
    } else {
        $filterCampOptions = [];
    }

    $effectiveCampExpr = 'COALESCE(NULLIF(r.transfer_to_camp_code, ""), NULLIF(m.emp_camp_loc, ""))';
    $filters = ['r.att_date = ?', 'h.is_deleted = 0', 'h.st_code = "A"'];
    $params = [$selectedDate];
    $types = 's';

    if (!empty($mappedCamps)) {
        $filters[] = $effectiveCampExpr . ' IN (' . implode(',', array_fill(0, count($mappedCamps), '?')) . ')';
        $params = array_merge($params, $mappedCamps);
        $types .= str_repeat('s', count($mappedCamps));
    }
    if (!empty($campFilter)) {
        $filters[] = $effectiveCampExpr . ' IN (' . implode(',', array_fill(0, count($campFilter), '?')) . ')';
        $params = array_merge($params, $campFilter);
        $types .= str_repeat('s', count($campFilter));
    }
    if ($reviewStatusFilter === 'pending') {
        $filters[] = 'COALESCE(r.is_escalated, 0) = 0';
        $filters[] = 'TRIM(COALESCE(r.campboss_reason_code, "")) = ""';
        $filters[] = 'TRIM(COALESCE(r.campboss_reviewed_at, "")) = ""';
    } elseif ($reviewStatusFilter === 'reviewed') {
        $filters[] = 'COALESCE(r.is_escalated, 0) = 0';
        $filters[] = '(TRIM(COALESCE(r.campboss_reason_code, "")) <> "" OR TRIM(COALESCE(r.campboss_reviewed_at, "")) <> "")';
    } elseif ($reviewStatusFilter === 'escalated') {
        $filters[] = 'COALESCE(r.is_escalated, 0) = 1';
    }

    $sql = 'SELECT r.emp_code, r.att_date, r.timekeeper_submitted_at, r.campboss_reason_code, ' .
        'r.campboss_note, mc.medical_note, mc.file_path AS medical_certificate_path, mc.file_name AS medical_certificate_name, mc.updated_at AS medical_certificate_uploaded_at, ' .
        'r.campboss_reviewed_at, r.is_escalated, r.transfer_to_camp_code, r.transfer_to_camp_name, r.auto_escalated_reason, ' .
        'h.emp_name, h.desg_name, h.dept_name, h.jbno, h.jbdesc, h.ty_cd, d.Projectcode_utime AS utime_project, ' .
        'm.emp_camp_loc, c.camp_name AS emp_camp_name, ' .
        $effectiveCampExpr . ' AS effective_camp_code, ' .
        'o.override_work_hours, o.override_work_code, o.override_is_approved ' .
        'FROM gcc_attendance_master.attendance_no_punch_reviews r ' .
        'LEFT JOIN gcc_attendance_master.hrmsvw_sync h ON h.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily d ON d.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci AND d.att_date = r.att_date ' .
        'LEFT JOIN gcc_attendance_master.hrms_hrmemp_camp_mapping m ON m.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci AND m.is_deleted = 0 ' .
        'LEFT JOIN gcc_attendance_master.hrms_camp_sync c ON c.camp_code COLLATE utf8mb4_general_ci = m.emp_camp_loc COLLATE utf8mb4_general_ci AND c.is_deleted = 0 ' .
        'LEFT JOIN gcc_attendance_master.employee_att_daily_overrides o ON o.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci ' .
        'AND o.att_date = r.att_date ' .
        'LEFT JOIN gcc_attendance_master.attendance_medical_certificates mc ON mc.emp_code COLLATE utf8mb4_general_ci = r.emp_code COLLATE utf8mb4_general_ci ' .
        'AND mc.att_date = r.att_date ' .
        'WHERE ' . implode(' AND ', $filters) .
        ' ORDER BY r.timekeeper_submitted_at DESC, r.emp_code ASC';
    $stmt = $bd->prepare($sql);
    if ($stmt) {
        bind_params($stmt, $types, $params);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                    if (!empty($row['campboss_reason_code'])) {
                        $reviewedCount++;
                    }
                    if ((int) ($row['is_escalated'] ?? 0) === 1) {
                        $escalatedCount++;
                    }
                }
                $result->free();
            }
        } else {
            $loadError = 'Unable to load camp boss reviews.';
        }
        $stmt->close();
    } else {
        $loadError = 'Unable to prepare camp boss query.';
    }

    if (!$loadError && !empty($rows)) {
        foreach ($rows as $rowIndex => $row) {
            $projectCode = strtoupper(trim((string) ($row['jbno'] ?? '')));
            $projectName = trim((string) ($row['jbdesc'] ?? ''));
            $projectKey = $projectCode !== '' ? $projectCode : '__UNASSIGNED__';

            if (!isset($rowsByProject[$projectKey])) {
                $projectLabel = $projectCode !== '' ? $projectCode : 'Unassigned project';
                if ($projectCode !== '' && $projectName !== '') {
                    $projectLabel .= ' - ' . $projectName;
                } elseif ($projectCode === '' && $projectName !== '') {
                    $projectLabel = $projectName;
                }
                $rowsByProject[$projectKey] = [
                    'project_code' => $projectCode,
                    'project_name' => $projectName,
                    'label' => $projectLabel,
                    'rows' => [],
                ];
            }

            $rowsByProject[$projectKey]['rows'][$rowIndex] = $row;
        }
    }
}

include __DIR__ . '/../admin/include/layout_top.php';

?>

<style>
  .campboss-card {
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
  }
  .campboss-table th,
  .campboss-table td {
    vertical-align: middle;
  }
  .campboss-table th:nth-child(8),
  .campboss-table td:nth-child(8) {
    min-width: 180px;
  }
  .campboss-table th:nth-child(9),
  .campboss-table td:nth-child(9),
  .campboss-table th:nth-child(11),
  .campboss-table td:nth-child(11) {
    text-align: center;
  }
  .campboss-status-actions {
    min-width: 190px;
  }
  .campboss-action-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .campboss-project-group {
    border-top: 1px solid rgba(15, 23, 42, 0.08);
  }
  .campboss-project-group:first-child {
    border-top: 0;
  }
  .campboss-project-group__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px;
    background: linear-gradient(90deg, rgba(15, 23, 42, 0.04), rgba(14, 165, 233, 0.06));
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
  }
  .campboss-project-group__title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
  }
  .campboss-project-group__meta {
    font-size: 12px;
    color: #475569;
    margin: 0;
  }
  .campboss-project-empty {
    padding: 20px 12px;
    text-align: center;
    color: #64748b;
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
  .status-pill.is-approved { background: rgba(34, 197, 94, 0.2); color: #166534; }
  .status-pill.is-pending { background: rgba(251, 191, 36, 0.2); color: #92400e; }
  .status-pill.is-reviewed { background: rgba(14, 165, 233, 0.2); color: #0c4a6e; }
  .status-pill.is-escalated { background: rgba(239, 68, 68, 0.2); color: #b91c1c; }
  .campboss-table tr.is-saving {
    opacity: 0.68;
    transition: opacity 0.2s ease;
  }
  .campboss-toast-layer {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 2000;
  }
  .campboss-toast {
    width: min(520px, calc(100vw - 32px));
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.24);
    border: 1px solid rgba(15, 23, 42, 0.12);
    background: #ffffff;
    color: #0f172a;
    font-weight: 600;
    text-align: center;
    opacity: 0;
    transform: translateY(18px) scale(0.96);
    transition: opacity 0.28s ease, transform 0.28s ease;
  }
  .campboss-toast.is-show {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  .campboss-toast.is-hide {
    opacity: 0;
    transform: translateY(-14px) scale(0.96);
  }
  .campboss-toast.is-success {
    background: linear-gradient(145deg, #dcfce7, #f0fdf4);
    border-color: rgba(22, 101, 52, 0.25);
    color: #166534;
  }
  .campboss-toast.is-warning {
    background: linear-gradient(145deg, #fef9c3, #fffbeb);
    border-color: rgba(146, 64, 14, 0.25);
    color: #92400e;
  }
  .campboss-toast.is-danger {
    background: linear-gradient(145deg, #fee2e2, #fef2f2);
    border-color: rgba(185, 28, 28, 0.25);
    color: #b91c1c;
  }
  .campboss-toast.is-info {
    background: linear-gradient(145deg, #dbeafe, #eff6ff);
    border-color: rgba(30, 64, 175, 0.25);
    color: #1e40af;
  }
</style>

<section class="content-header">
  <div class="container-fluid">
    <div class="row mb-2">
      <div class="col-sm-6">
        <h1>Camp Boss No Punch Review</h1>
      </div>
      <div class="col-sm-6 text-sm-right"></div>
    </div>
    <?php $nav_mode = 'campboss'; include dirname(__DIR__) . '/admin/include/admin_nav.php'; ?>
  </div>
</section>

<section class="content">
  <div class="container-fluid">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?> js-server-flash" data-flash-type="<?= h($flash['type']) ?>" data-flash-message="<?= h($flash['message']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($loadError): ?>
      <div class="alert alert-warning mb-3"><?= h($loadError) ?></div>
    <?php endif; ?>

    <?php if ($mappingRequired): ?>
      <div class="card campboss-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Camp access needed</h3>
        </div>
        <div class="card-body">
          <p class="text-muted mb-0">No camp boss camp access is configured. Contact the admin to add your camps.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="card campboss-card mb-3">
        <div class="card-header">
          <h3 class="card-title">Filters</h3>
        </div>
        <div class="card-body">
          <form method="get" class="form-row js-campboss-filter-form">
            <div class="form-group col-md-3">
              <label for="date">Date (UAE)</label>
              <input id="date" name="date" type="date" class="form-control" value="<?= h($selectedDate) ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="camp_code">Camp</label>
              <select id="camp_code" name="camp_code[]" class="form-control js-searchable" multiple data-placeholder="All camps">
                <?php foreach ($filterCampOptions as $code => $name): ?>
                  <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                  <option value="<?= h($code) ?>" <?= in_array($code, $campFilter, true) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-3">
              <label for="review_status">Review status</label>
              <select id="review_status" name="review_status" class="form-control">
                <option value="" <?= $reviewStatusFilter === '' ? 'selected' : '' ?>>All</option>
                <option value="pending" <?= $reviewStatusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="reviewed" <?= $reviewStatusFilter === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                <option value="escalated" <?= $reviewStatusFilter === 'escalated' ? 'selected' : '' ?>>Escalated</option>
              </select>
            </div>
            <div class="form-group col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-primary btn-block">Apply</button>
            </div>
          </form>
          <div class="small text-muted js-review-summary">Reviewed: <span class="js-reviewed-count"><?= h((string) $reviewedCount) ?></span> | Escalated: <span class="js-escalated-count"><?= h((string) $escalatedCount) ?></span></div>
        </div>
      </div>

      <?php if (empty($reasonOptions)): ?>
        <div class="alert alert-warning">No camp boss reasons configured. Populate attendance_no_punch_reasons to enable selections.</div>
      <?php endif; ?>

      <div class="card campboss-card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title">No punch submissions</h3>
          <span class="text-muted small js-record-count"><?= h(count($rows)) ?> record(s)</span>
        </div>
        <div class="card-body p-0">
          <form method="post" class="js-campboss-review-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="js-campboss-group-container">
              <?php if (empty($rowsByProject)): ?>
                <div class="campboss-project-empty">No submissions for this date.</div>
              <?php else: ?>
                <?php foreach ($rowsByProject as $projectGroup): ?>
                  <div class="campboss-project-group">
                    <div class="campboss-project-group__header">
                      <div>
                        <p class="campboss-project-group__title mb-0"><?= h($projectGroup['label'] ?? 'Project') ?></p>
                        <?php if (trim((string) ($projectGroup['project_code'] ?? '')) === '' && trim((string) ($projectGroup['project_name'] ?? '')) === ''): ?>
                          <p class="campboss-project-group__meta mb-0">Employee project not available in HRMS sync.</p>
                        <?php endif; ?>
                      </div>
                      <span class="text-muted small"><?= h((string) count($projectGroup['rows'] ?? [])) ?> record(s)</span>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-bordered table-sm campboss-table mb-0">
                        <thead>
                          <tr>
                            <th>Emp Code</th>
                            <th>Name</th>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>Camp</th>
                            <th>UTime Project</th>
                            <th>Reason</th>
                            <th>Work Hour Action</th>
                            <th>Work Code</th>
                            <th>Note</th>
                            <th>Status / Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach (($projectGroup['rows'] ?? []) as $rowIndex => $row): ?>
                    <?php
                      $reasonCode = strtoupper(trim((string) ($row['campboss_reason_code'] ?? '')));
                      $isSubmitted = trim((string) ($row['campboss_reviewed_at'] ?? '')) !== '' || $reasonCode !== '';
                      $overrideStatus = (int) ($row['override_is_approved'] ?? 0);
                      $statusLabel = 'Pending';
                      $statusClass = 'is-pending';
                      if ((int) ($row['is_escalated'] ?? 0) === 1) {
                          $statusLabel = 'Escalated';
                          $statusClass = 'is-escalated';
                      } elseif ($overrideStatus === 1) {
                          $statusLabel = 'Approved';
                          $statusClass = 'is-approved';
                      } elseif ($isSubmitted) {
                          $statusLabel = 'Reviewed';
                          $statusClass = 'is-reviewed';
                      }
                      $reasonMeta = $reasonCode !== '' ? ($reasonOptions[$reasonCode] ?? null) : null;
                      $reasonMode = resolve_campboss_reason_mode($reasonCode, $reasonMeta);
                      $requiresTransfer = ((bool) ($reasonMeta['requires_transfer_project'] ?? false));
                      $canSaveReason = !$isSubmitted && in_array($reasonMode, ['SAVE_ONLY', 'SAVE_ONLY_FULL_DAY', 'BOTH'], true);
                      $canEscalateHr = !$isSubmitted && in_array($reasonMode, ['ESCALATE_ONLY', 'BOTH'], true);
                      $lockWorkAction = in_array($reasonMode, ['ESCALATE_ONLY', 'SAVE_ONLY_FULL_DAY'], true);
                      $rowTransferCode = strtoupper(trim((string) ($row['transfer_to_camp_code'] ?? '')));
                      $existingHours = trim((string) ($row['override_work_hours'] ?? ''));
                      $existingCode = strtoupper(trim((string) ($row['override_work_code'] ?? '')));
                      $medicalNoteValue = trim((string) ($row['medical_note'] ?? ''));
                      $rowNoteValue = trim((string) ($row['campboss_note'] ?? ''));
                      if ($rowNoteValue === '' && $medicalNoteValue !== '') {
                          $rowNoteValue = $medicalNoteValue;
                      }
                      $medicalCertificatePath = str_replace('\\', '/', trim((string) ($row['medical_certificate_path'] ?? '')));
                      $medicalCertificateName = trim((string) ($row['medical_certificate_name'] ?? ''));
                      $medicalCertificateUrl = '';
                      if ($medicalCertificatePath !== '') {
                          $medicalCertificateUrl = attendance_app_base() . '/' . ltrim($medicalCertificatePath, '/');
                      }
                      $workHoursAction = '';
                      if ($existingHours !== '') {
                          $hoursVal = (float) $existingHours;
                          $isStaff = is_staff_employee_type($row['ty_cd'] ?? null);
                          $halfDayHours = $isStaff ? 4.0 : 5.0;
                          if (abs($hoursVal) < 0.0001) {
                              $workHoursAction = 'NO_HOURS';
                          } elseif (abs($hoursVal - $halfDayHours) < 0.0001) {
                              $workHoursAction = 'HALF_DAY';
                          } else {
                              $workHoursAction = 'FULL_DAY';
                          }
                      } elseif ($reasonMode === 'ESCALATE_ONLY') {
                          $workHoursAction = 'NO_HOURS';
                      } elseif ($reasonMode === 'SAVE_ONLY_FULL_DAY') {
                          $workHoursAction = 'FULL_DAY';
                      } elseif ($reasonMode === 'SAVE_ONLY' && $reasonMeta) {
                          $behavior = strtoupper(trim((string) ($reasonMeta['default_behavior'] ?? 'NONE')));
                          if ($behavior === 'FULL_DAY' || $behavior === 'FULL_DAY_PLUS_1H') {
                              $workHoursAction = 'FULL_DAY';
                          } elseif ($behavior === 'HALF_DAY') {
                              $workHoursAction = 'HALF_DAY';
                          } elseif ($behavior === 'NONE') {
                              $workHoursAction = 'NO_HOURS';
                          }
                      }
                      $campCode = strtoupper(trim((string) ($row['emp_camp_loc'] ?? '')));
                      $campName = trim((string) ($row['emp_camp_name'] ?? ''));
                      $campLabel = $campCode !== '' ? $campCode : '-';
                      if ($campCode !== '' && $campName !== '') {
                          $campLabel .= ' (' . $campName . ')';
                      }
                      if ($rowTransferCode !== '') {
                          $transferName = trim((string) ($row['transfer_to_camp_name'] ?? ''));
                          $campLabel .= ' -> ' . $rowTransferCode;
                          if ($transferName !== '') {
                              $campLabel .= ' (' . $transferName . ')';
                          }
                      }
                      $utimeProjectLabel = strtoupper(trim((string) ($row['utime_project'] ?? '')));
                      if ($utimeProjectLabel === '') {
                          $utimeProjectLabel = '-';
                      }
                    ?>
                    <tr data-row-index="<?= (int) $rowIndex ?>" data-submitted="<?= $isSubmitted ? '1' : '0' ?>" data-employee-type="<?= h($row['ty_cd'] ?? '') ?>">
                      <td>
                        <?= h($row['emp_code'] ?? '') ?>
                        <input type="hidden" name="emp_code[]" value="<?= h($row['emp_code'] ?? '') ?>">
                        <input type="hidden" name="att_date[]" value="<?= h($selectedDate) ?>">
                        <input type="hidden" name="is_submitted[]" value="<?= $isSubmitted ? '1' : '0' ?>">
                      </td>
                      <td><?= h($row['emp_name'] ?? '') ?></td>
                      <td><?= h($row['desg_name'] ?? '') ?></td>
                      <td><?= h($row['dept_name'] ?? '') ?></td>
                      <td><?= h($campLabel) ?></td>
                      <td><?= h($utimeProjectLabel) ?></td>
                      <td>
                        <select class="form-control form-control-sm js-reason-code" name="reason_code[]">
                          <option value="">Select</option>
                          <?php foreach ($reasonOptions as $code => $meta): ?>
                            <?php $label = $meta['text'] !== '' ? ($code . ' - ' . $meta['text']) : $code; ?>
                            <option
                              value="<?= h($code) ?>"
                              data-auto-escalate="<?= ((bool) ($meta['auto_escalate'] ?? false)) ? '1' : '0' ?>"
                              data-requires-transfer="<?= ((bool) ($meta['requires_transfer_project'] ?? false)) ? '1' : '0' ?>"
                              data-default-behavior="<?= h($meta['default_behavior'] ?? 'NONE') ?>"
                              data-reason-mode="<?= h(resolve_campboss_reason_mode($code, $meta)) ?>"
                              <?= $code === $reasonCode ? 'selected' : '' ?>
                            ><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <div class="mt-2 js-transfer-wrap <?= $requiresTransfer ? '' : 'd-none' ?>">
                          <label class="small text-muted mb-1 d-block">Transfer camp</label>
                          <select class="form-control form-control-sm js-transfer-camp" name="transfer_to_camp_code[]">
                            <option value="">Select camp</option>
                            <?php foreach ($campOptions as $code => $name): ?>
                              <?php $label = $name !== '' ? ($code . ' - ' . $name) : $code; ?>
                              <option value="<?= h($code) ?>" <?= $code === $rowTransferCode ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <div class="small text-danger mt-1 js-transfer-required <?= $requiresTransfer ? '' : 'd-none' ?>">Required for this reason</div>
                        </div>
                        <?php if ($reasonMode === 'ESCALATE_ONLY'): ?>
                          <div class="small text-danger mt-1">Escalates automatically for this reason.</div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <select class="form-control form-control-sm js-work-hours-action" name="work_hours_action[]" <?= $lockWorkAction ? 'disabled' : '' ?>>
                          <option value="">Select</option>
                          <option value="FULL_DAY" <?= $workHoursAction === 'FULL_DAY' ? 'selected' : '' ?>>Full day</option>
                          <option value="HALF_DAY" <?= $workHoursAction === 'HALF_DAY' ? 'selected' : '' ?>>Half day</option>
                          <option value="NO_HOURS" <?= $workHoursAction === 'NO_HOURS' ? 'selected' : '' ?>>No hours</option>
                        </select>
                      </td>
                      <td class="js-current-work-code">
                        <?= h($existingCode !== '' ? $existingCode : '-') ?>
                      </td>
                      <td>
                        <input class="form-control form-control-sm js-campboss-note" name="campboss_note[]" value="<?= h($rowNoteValue) ?>">
                        <?php if (!$isSubmitted): ?>
                          <div class="mt-2">
                            <input type="file" class="form-control-file js-inline-medical-file" accept=".pdf,.jpg,.jpeg,.png">
                            <div class="small text-muted mt-1">Medical certificate for SICK (max 5 MB)</div>
                          </div>
                        <?php endif; ?>
                        <?php if ($medicalCertificateUrl !== ''): ?>
                          <div class="small mt-1">
                            <a href="<?= h($medicalCertificateUrl) ?>" target="_blank" rel="noopener">
                              Medical certificate: <?= h($medicalCertificateName !== '' ? $medicalCertificateName : basename($medicalCertificatePath)) ?>
                            </a>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td class="text-nowrap campboss-status-actions">
                        <span class="status-pill js-status-pill <?= h($statusClass) ?> <?= $isSubmitted ? '' : 'd-none' ?>"><?= h($statusLabel) ?></span>
                        <?php if (!$isSubmitted): ?>
                          <div class="campboss-action-group js-action-group">
                          <button
                            type="submit"
                            name="row_save_review"
                            value="<?= (int) $rowIndex ?>"
                            class="btn btn-sm btn-primary btn-block js-row-save-review"
                            <?= $canSaveReason ? '' : 'disabled' ?>
                          >Save Reason</button>
                          <button
                            type="submit"
                            name="row_escalate_hr"
                            value="<?= (int) $rowIndex ?>"
                            class="btn btn-sm btn-outline-danger btn-block js-row-escalate-hr mt-1"
                            <?= $canEscalateHr ? '' : 'disabled' ?>
                          >Escalate to HR</button>
                          </div>
                        <?php endif; ?>
                      </td>
                    </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <div class="p-3">
              <button type="submit" name="action" value="save_reviews" class="btn btn-primary">Save reviews</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>
  </div>
</section>

<script>
  window.CAMPBOSS_REASON_META = <?= json_encode($reasonOptions, JSON_UNESCAPED_SLASHES) ?>;

  (function () {
    var reasonMeta = window.CAMPBOSS_REASON_META || {};
    var filterForm = document.querySelector('form.js-campboss-filter-form');
    var reviewForm = document.querySelector('form.js-campboss-review-form');
    if (!reviewForm) {
      return;
    }

    var toastLayer = document.createElement('div');
    toastLayer.className = 'campboss-toast-layer';
    document.body.appendChild(toastLayer);

    function normalizeToastType(type) {
      var value = String(type || '').trim().toLowerCase();
      if (value === 'error') return 'danger';
      if (value === 'warning' || value === 'danger' || value === 'success' || value === 'info') {
        return value;
      }
      return 'info';
    }

    function showCenterToast(message, type, durationMs) {
      var text = String(message || '').trim();
      if (text === '') return;
      var toast = document.createElement('div');
      var resolvedType = normalizeToastType(type);
      toast.className = 'campboss-toast is-' + resolvedType;
      toast.textContent = text;
      toastLayer.appendChild(toast);

      requestAnimationFrame(function () {
        toast.classList.add('is-show');
      });

      var duration = Number(durationMs);
      if (!Number.isFinite(duration) || duration <= 0) {
        duration = resolvedType === 'success' ? 2200 : 3200;
      }

      window.setTimeout(function () {
        toast.classList.remove('is-show');
        toast.classList.add('is-hide');
        window.setTimeout(function () {
          if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
          }
        }, 320);
      }, duration);
    }

    function isSickReason(code) {
      return String(code || '').trim().toUpperCase() === 'SICK';
    }

    var serverFlash = document.querySelector('.js-server-flash');
    if (serverFlash) {
      var flashType = serverFlash.getAttribute('data-flash-type') || 'info';
      var flashMessage = serverFlash.getAttribute('data-flash-message') || serverFlash.textContent || '';
      serverFlash.classList.add('d-none');
      showCenterToast(flashMessage, flashType, 2800);
    }

    function getReasonMeta(code) {
      var key = String(code || '').trim().toUpperCase();
      if (key === '' || !Object.prototype.hasOwnProperty.call(reasonMeta, key)) {
        return null;
      }
      return reasonMeta[key] || null;
    }

    function resolveReasonModeFromCode(code, meta) {
      var normalizedCode = String(code || '').trim().toUpperCase();
      var defaultBehavior = meta ? String(meta.default_behavior || 'NONE').trim().toUpperCase() : 'NONE';
      var defaultWorkCode = meta ? String(meta.default_work_code || '').trim().toUpperCase() : '';
      if (normalizedCode === 'EMP_ABSCONDING') {
        return 'ESCALATE_ONLY';
      }
      if (defaultBehavior === 'WORK_CODE' && defaultWorkCode !== '') {
        return 'SAVE_ONLY';
      }
      if (normalizedCode === 'EMP_NO_SHOW' || normalizedCode === 'EMP_RESIGNED' || normalizedCode === 'NOT_IN_CAMP') {
        return 'ESCALATE_ONLY';
      }
      if (
        normalizedCode === 'MED'
        || normalizedCode === 'VISA_MED'
        || normalizedCode === 'SICK'
        || normalizedCode === 'VISA'
        || normalizedCode === 'VISA_OTH'
        || normalizedCode === 'BIO_VISA'
        || normalizedCode === 'VISA_BIO'
        || normalizedCode === 'VISA_TAWJEEH'
      ) {
        return 'SAVE_ONLY_FULL_DAY';
      }
      if (normalizedCode === 'OTH') {
        return 'BOTH';
      }
      if (meta && !!meta.auto_escalate) {
        return 'ESCALATE_ONLY';
      }
      return 'SAVE_ONLY';
    }

    function getSelectedReasonMode(reasonSel, meta) {
      if (!reasonSel) {
        return resolveReasonModeFromCode('', meta);
      }
      var selectedOption = reasonSel.options && reasonSel.selectedIndex >= 0 ? reasonSel.options[reasonSel.selectedIndex] : null;
      var optionMode = selectedOption ? String(selectedOption.getAttribute('data-reason-mode') || '').trim().toUpperCase() : '';
      if (optionMode !== '') {
        return optionMode;
      }
      return resolveReasonModeFromCode(reasonSel.value, meta);
    }

    function setElementVisible(element, show) {
      if (!element) return;
      element.classList.toggle('d-none', !show);
    }

    function setButtonVisible(button, show) {
      setElementVisible(button, show);
    }

    function removeMirrors(container, fieldName) {
      if (!container) return;
      var mirrors = container.querySelectorAll('input[type="hidden"][data-generated-mirror="1"][name="' + fieldName + '"]');
      for (var i = 0; i < mirrors.length; i++) {
        mirrors[i].remove();
      }
    }

    function ensureMirror(container, fieldName, value) {
      if (!container) return;
      removeMirrors(container, fieldName);
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = fieldName;
      input.value = value;
      input.setAttribute('data-generated-mirror', '1');
      container.appendChild(input);
    }

    function resolveDefaultWorkAction(meta) {
      if (!meta) return '';
      var behavior = String(meta.default_behavior || 'NONE').trim().toUpperCase();
      if (behavior === 'FULL_DAY' || behavior === 'FULL_DAY_PLUS_1H') {
        return 'FULL_DAY';
      }
      if (behavior === 'HALF_DAY') {
        return 'HALF_DAY';
      }
      return '';
    }

    function setFormBusy(isBusy) {
      reviewForm.dataset.submitting = isBusy ? '1' : '0';
      var buttons = reviewForm.querySelectorAll('button[type="submit"]');
      for (var i = 0; i < buttons.length; i++) {
        buttons[i].disabled = !!isBusy;
      }
    }

    function setRowsBusy(rows, isBusy) {
      if (!rows || !rows.length) return;
      for (var i = 0; i < rows.length; i++) {
        rows[i].classList.toggle('is-saving', !!isBusy);
      }
    }

    function updateWorkCodeCell(cell, overrideCode) {
      if (!cell) return;
      while (cell.firstChild) {
        cell.removeChild(cell.firstChild);
      }
      var codeText = overrideCode === null || typeof overrideCode === 'undefined'
        ? ''
        : String(overrideCode).trim();
      cell.appendChild(document.createTextNode(codeText !== '' ? codeText : '-'));
    }

    function updateStatusPill(pill, statusLabel, statusClass) {
      if (!pill) return;
      pill.classList.remove('is-pending', 'is-escalated', 'is-approved', 'is-reviewed');
      if (statusClass) {
        pill.classList.add(String(statusClass));
      }
      pill.textContent = String(statusLabel || 'Pending');
    }

    function refreshSummaryCounts() {
      var summaryReviewed = document.querySelector('.js-reviewed-count');
      var summaryEscalated = document.querySelector('.js-escalated-count');
      if (!summaryReviewed || !summaryEscalated) return;
      var rows = reviewForm.querySelectorAll('tbody tr[data-row-index]');
      var reviewed = 0;
      var escalated = 0;
      for (var i = 0; i < rows.length; i++) {
        var reasonSel = rows[i].querySelector('select.js-reason-code');
        var statusPill = rows[i].querySelector('.js-status-pill');
        if (reasonSel && String(reasonSel.value || '').trim() !== '') {
          reviewed++;
        }
        if (statusPill && statusPill.classList.contains('is-escalated')) {
          escalated++;
        }
      }
      summaryReviewed.textContent = String(reviewed);
      summaryEscalated.textContent = String(escalated);
    }

    function applyProcessedRows(processedRows) {
      if (!Array.isArray(processedRows)) return;
      for (var i = 0; i < processedRows.length; i++) {
        var item = processedRows[i] || {};
        if (typeof item.index === 'undefined' || item.index === null) {
          continue;
        }
        var row = reviewForm.querySelector('tr[data-row-index="' + String(item.index) + '"]');
        if (!row) {
          continue;
        }
        var statusPill = row.querySelector('.js-status-pill');
        updateStatusPill(statusPill, item.status_label || 'Pending', item.status_class || 'is-pending');
        var workCodeCell = row.querySelector('.js-current-work-code');
        updateWorkCodeCell(workCodeCell, item.override_code);
        row.setAttribute('data-submitted', '1');
        syncRowState(row, false);
      }
      refreshSummaryCounts();
    }

    function submitReviewRequest(submitter, rowsToMarkBusy, extraFields) {
      setFormBusy(true);
      setRowsBusy(rowsToMarkBusy, true);

      var formData = new FormData(reviewForm);
      formData.set('ajax', '1');
      if (submitter && submitter.name) {
        formData.append(submitter.name, submitter.value || '');
      }
      if (Array.isArray(extraFields)) {
        for (var i = 0; i < extraFields.length; i++) {
          var field = extraFields[i] || {};
          var fieldName = String(field.name || '').trim();
          if (fieldName === '') continue;
          if (field.mode === 'append') {
            formData.append(fieldName, field.value);
          } else {
            formData.set(fieldName, field.value);
          }
        }
      }

      fetch(window.location.href, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData,
        credentials: 'same-origin'
      })
        .then(function (response) {
          return response.json().catch(function () {
            return {
              ok: false,
              type: 'danger',
              message: 'Invalid response from server.'
            };
          });
        })
        .then(function (data) {
          var payload = data && typeof data === 'object' ? data : {};
          if (payload.csrf) {
            var csrfInput = reviewForm.querySelector('input[name="csrf"]');
            if (csrfInput) {
              csrfInput.value = String(payload.csrf);
            }
          }
          applyProcessedRows(payload.processed_rows);
          var toastType = payload.type || (payload.ok ? 'success' : 'warning');
          var toastMessage = payload.message || (payload.ok ? 'Saved.' : 'Unable to save.');
          showCenterToast(toastMessage, toastType);
        })
        .catch(function () {
          showCenterToast('Request failed. Please check your connection and try again.', 'danger');
        })
        .finally(function () {
          setRowsBusy(rowsToMarkBusy, false);
          setFormBusy(false);
        });
    }

    function syncRowState(row, forceBehaviorDefault) {
      if (!row) return;
      var reasonSel = row.querySelector('select.js-reason-code');
      var workSel = row.querySelector('select.js-work-hours-action');
      var transferSel = row.querySelector('select.js-transfer-camp');
      var transferWrap = row.querySelector('.js-transfer-wrap');
      var transferRequiredHint = row.querySelector('.js-transfer-required');
      var statusPill = row.querySelector('.js-status-pill');
      var actionGroup = row.querySelector('.js-action-group');
      var saveButton = row.querySelector('.js-row-save-review');
      var escalateButton = row.querySelector('.js-row-escalate-hr');
      var workCodeCell = row.querySelector('.js-current-work-code');
      if (!reasonSel || !workSel || !transferSel) return;
      var isSubmitted = String(row.getAttribute('data-submitted') || '0') === '1';

      var meta = getReasonMeta(reasonSel.value);
      var reasonMode = getSelectedReasonMode(reasonSel, meta);
      var normalizedReasonCode = String(reasonSel.value || '').trim().toUpperCase();
      var requiresTransfer = !!(meta && meta.requires_transfer_project);
      var recommendedAction = resolveDefaultWorkAction(meta);
      var behavior = meta ? String(meta.default_behavior || 'NONE').trim().toUpperCase() : 'NONE';
      var defaultWorkCode = meta ? String(meta.default_work_code || '').trim().toUpperCase() : '';
      var hasDefaultWorkCode = defaultWorkCode !== '';
      var previewWorkCode = '';
      var hasTransferValue = String(transferSel.value || '').trim() !== '';

      var canSave = !isSubmitted && (reasonMode === 'SAVE_ONLY' || reasonMode === 'SAVE_ONLY_FULL_DAY' || reasonMode === 'BOTH');
      var canEscalate = !isSubmitted && (reasonMode === 'ESCALATE_ONLY' || reasonMode === 'BOTH');
      var showRowButtons = !isSubmitted;
      setElementVisible(actionGroup, showRowButtons);
      setElementVisible(statusPill, !showRowButtons);
      setButtonVisible(saveButton, showRowButtons);
      setButtonVisible(escalateButton, showRowButtons);
      if (saveButton) {
        saveButton.disabled = !canSave;
      }
      if (escalateButton) {
        escalateButton.disabled = !canEscalate;
      }
      if (escalateButton) {
        escalateButton.classList.toggle('mt-1', showRowButtons);
      }

      if (reasonMode === 'ESCALATE_ONLY') {
        workSel.value = 'NO_HOURS';
        workSel.disabled = true;
        ensureMirror(row, 'work_hours_action[]', 'NO_HOURS');
        if (normalizedReasonCode === 'EMP_ABSCONDING' && hasDefaultWorkCode) {
          previewWorkCode = defaultWorkCode;
        }
      } else if (reasonMode === 'SAVE_ONLY_FULL_DAY') {
        workSel.value = 'FULL_DAY';
        workSel.disabled = true;
        ensureMirror(row, 'work_hours_action[]', 'FULL_DAY');
      } else if (behavior === 'WORK_CODE' && hasDefaultWorkCode) {
        workSel.value = 'NO_HOURS';
        workSel.disabled = true;
        ensureMirror(row, 'work_hours_action[]', 'NO_HOURS');
        previewWorkCode = defaultWorkCode;
      } else {
        workSel.disabled = false;
        removeMirrors(row, 'work_hours_action[]');

        if (reasonMode === 'BOTH') {
          if (forceBehaviorDefault && recommendedAction === '') {
            workSel.value = '';
          } else if (recommendedAction !== '' && (forceBehaviorDefault || workSel.value === '')) {
            workSel.value = recommendedAction;
          }
        } else if (forceBehaviorDefault) {
          workSel.value = recommendedAction;
        } else if (workSel.value === '') {
          workSel.value = recommendedAction;
        }
      }

      if (!isSubmitted) {
        updateWorkCodeCell(workCodeCell, previewWorkCode);
      }

      if (requiresTransfer) {
        if (transferWrap) {
          transferWrap.classList.remove('d-none');
        }
        if (transferRequiredHint) {
          if (hasTransferValue) {
            transferRequiredHint.classList.add('d-none');
          } else {
            transferRequiredHint.classList.remove('d-none');
          }
        }
        transferSel.setAttribute('required', 'required');
        if (hasTransferValue) {
          transferSel.classList.remove('is-invalid');
        }
      } else {
        if (transferWrap) {
          transferWrap.classList.add('d-none');
        }
        if (transferRequiredHint) {
          transferRequiredHint.classList.add('d-none');
        }
        transferSel.removeAttribute('required');
        transferSel.classList.remove('is-invalid');
      }
    }

    function bindRow(row) {
      var reasonSel = row.querySelector('select.js-reason-code');
      var transferSel = row.querySelector('select.js-transfer-camp');
      if (reasonSel && reasonSel.dataset.bound !== '1') {
        reasonSel.dataset.bound = '1';
        reasonSel.addEventListener('change', function () {
          syncRowState(row, true);
        });
      }
      if (transferSel && transferSel.dataset.bound !== '1') {
        transferSel.dataset.bound = '1';
        transferSel.addEventListener('change', function () {
          transferSel.classList.remove('is-invalid');
          syncRowState(row, false);
        });
      }
      syncRowState(row, false);
    }

    var rows = reviewForm.querySelectorAll('tr[data-row-index]');
    for (var i = 0; i < rows.length; i++) {
      bindRow(rows[i]);
    }
    refreshSummaryCounts();

    if (filterForm && filterForm.dataset.bound !== '1') {
      filterForm.dataset.bound = '1';
      filterForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (filterForm.dataset.loading === '1') {
          return;
        }
        filterForm.dataset.loading = '1';
        var applyButton = filterForm.querySelector('button[type="submit"]');
        if (applyButton) {
          applyButton.disabled = true;
        }

        var params = new URLSearchParams();
        var filterData = new FormData(filterForm);
        filterData.forEach(function (value, key) {
          var text = String(value == null ? '' : value).trim();
          if (text !== '') {
            params.append(key, value);
          }
        });

        var targetUrl = window.location.pathname + (params.toString() !== '' ? ('?' + params.toString()) : '');
        fetch(targetUrl, {
          method: 'GET',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin'
        })
          .then(function (response) {
            return response.text();
          })
          .then(function (html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var nextGroupContainer = doc.querySelector('form.js-campboss-review-form .js-campboss-group-container');
            var currentGroupContainer = reviewForm.querySelector('.js-campboss-group-container');
            if (!nextGroupContainer || !currentGroupContainer) {
              throw new Error('Unable to refresh data.');
            }
            currentGroupContainer.innerHTML = nextGroupContainer.innerHTML;

            var nextRecordCount = doc.querySelector('.js-record-count');
            var currentRecordCount = document.querySelector('.js-record-count');
            if (nextRecordCount && currentRecordCount) {
              currentRecordCount.textContent = nextRecordCount.textContent;
            }
            var nextReviewed = doc.querySelector('.js-reviewed-count');
            var currentReviewed = document.querySelector('.js-reviewed-count');
            if (nextReviewed && currentReviewed) {
              currentReviewed.textContent = nextReviewed.textContent;
            }
            var nextEscalated = doc.querySelector('.js-escalated-count');
            var currentEscalated = document.querySelector('.js-escalated-count');
            if (nextEscalated && currentEscalated) {
              currentEscalated.textContent = nextEscalated.textContent;
            }

            var updatedRows = reviewForm.querySelectorAll('tr[data-row-index]');
            for (var i = 0; i < updatedRows.length; i++) {
              bindRow(updatedRows[i]);
            }
            refreshSummaryCounts();
            window.history.replaceState({}, '', targetUrl);
            showCenterToast('Filters applied.', 'success', 1600);
          })
          .catch(function () {
            showCenterToast('Unable to apply filters right now.', 'danger');
          })
          .finally(function () {
            filterForm.dataset.loading = '0';
            if (applyButton) {
              applyButton.disabled = false;
            }
          });
      });
    }

    reviewForm.addEventListener('submit', function (event) {
      var submitter = event.submitter || document.activeElement;
      if (!submitter || !submitter.name) {
        submitter = reviewForm.querySelector('button[name="action"][value="save_reviews"]');
      }
      if (!submitter) {
        return;
      }
      event.preventDefault();
      if (reviewForm.dataset.submitting === '1') {
        return;
      }

      var isRowSave = !!(submitter && submitter.name === 'row_save_review');
      var isRowEscalate = !!(submitter && submitter.name === 'row_escalate_hr');
      var rowsToValidate = [];
      if (isRowSave || isRowEscalate) {
        var singleRow = submitter ? submitter.closest('tr') : null;
        if (singleRow) {
          rowsToValidate = [singleRow];
        }
      } else {
        rowsToValidate = reviewForm.querySelectorAll('tr[data-row-index]');
      }

      var sickRowPayload = null;
      for (var i = 0; i < rowsToValidate.length; i++) {
        var row = rowsToValidate[i];
        var reasonSel = row.querySelector('select.js-reason-code');
        var workSel = row.querySelector('select.js-work-hours-action');
        var transferSel = row.querySelector('select.js-transfer-camp');
        var transferRequiredHint = row.querySelector('.js-transfer-required');
        if (!reasonSel || !workSel || !transferSel) {
          continue;
        }
        var isSubmittedRow = String(row.getAttribute('data-submitted') || '0') === '1';
        if (!isRowSave && !isRowEscalate && isSubmittedRow) {
          continue;
        }
        var reasonCode = String(reasonSel.value || '').trim();
        if (reasonCode === '') {
          if (isRowSave || isRowEscalate) {
            showCenterToast('Select a reason before submitting this row.', 'warning');
            reasonSel.focus();
            return;
          }
          continue;
        }
        if (!isRowSave && isSickReason(reasonCode) && !isSubmittedRow) {
          showCenterToast('For SICK reason, use row "Save Reason" so note and certificate can be attached.', 'warning');
          reasonSel.focus();
          return;
        }

        var meta = getReasonMeta(reasonCode);
        var reasonMode = getSelectedReasonMode(reasonSel, meta);
        var workAction = String(workSel.value || '').trim().toUpperCase();
        var requiresTransfer = !!(meta && meta.requires_transfer_project);
        var behavior = meta ? String(meta.default_behavior || 'NONE').trim().toUpperCase() : 'NONE';
        var defaultWorkCode = meta ? String(meta.default_work_code || '').trim().toUpperCase() : '';
        var hasReasonDefault = behavior === 'FULL_DAY'
          || behavior === 'HALF_DAY'
          || behavior === 'FULL_DAY_PLUS_1H'
          || (behavior === 'WORK_CODE' && defaultWorkCode !== '');

        if (isRowSave && reasonMode === 'ESCALATE_ONLY') {
          showCenterToast('This reason can only be escalated to HR.', 'warning');
          var rowEscalateButton = row.querySelector('.js-row-escalate-hr');
          if (rowEscalateButton) {
            rowEscalateButton.focus();
          } else {
            reasonSel.focus();
          }
          return;
        }

        if (isRowEscalate && !(reasonMode === 'ESCALATE_ONLY' || reasonMode === 'BOTH')) {
          showCenterToast('This reason should be saved, not escalated.', 'warning');
          var rowSaveButton = row.querySelector('.js-row-save-review');
          if (rowSaveButton) {
            rowSaveButton.focus();
          } else {
            reasonSel.focus();
          }
          return;
        }

        if (requiresTransfer && String(transferSel.value || '').trim() === '') {
          transferSel.classList.add('is-invalid');
          if (transferRequiredHint) {
            transferRequiredHint.classList.remove('d-none');
          }
          showCenterToast('Transfer camp is required for selected transfer reason.', 'warning');
          transferSel.focus();
          return;
        }

        var requiresWorkAction = false;
        if (!isRowEscalate) {
          if (reasonMode === 'BOTH') {
            requiresWorkAction = true;
          } else if (reasonMode === 'SAVE_ONLY') {
            requiresWorkAction = !hasReasonDefault;
          }
        }

        if (requiresWorkAction && workAction === '') {
          showCenterToast('Select a Work Hour Action for this reason.', 'warning');
          workSel.focus();
          return;
        }

        if (isRowSave && isSickReason(reasonCode)) {
          var rowIndex = Number(row.getAttribute('data-row-index'));
          var rowNoteInput = row.querySelector('input.js-campboss-note');
          var inlineMedicalFile = row.querySelector('.js-inline-medical-file');
          var noteText = rowNoteInput ? String(rowNoteInput.value || '').trim() : '';
          var fileObject = inlineMedicalFile && inlineMedicalFile.files ? inlineMedicalFile.files[0] : null;

          if (!Number.isFinite(rowIndex) || rowIndex < 0) {
            showCenterToast('Invalid row selected for sick leave upload.', 'warning');
            return;
          }
          if (noteText === '') {
            showCenterToast('Medical note is required for SICK reason.', 'warning');
            if (rowNoteInput) {
              rowNoteInput.focus();
            }
            return;
          }
          if (!fileObject) {
            showCenterToast('Attach medical certificate for SICK reason.', 'warning');
            if (inlineMedicalFile) {
              inlineMedicalFile.focus();
            }
            return;
          }
          if (Number(fileObject.size || 0) > (5 * 1024 * 1024)) {
            showCenterToast('Medical certificate must be 5 MB or smaller.', 'warning');
            if (inlineMedicalFile) {
              inlineMedicalFile.focus();
            }
            return;
          }

          sickRowPayload = {
            rowIndex: rowIndex,
            noteText: noteText,
            fileObject: fileObject
          };
        }
      }

      var rowsToMarkBusy = rowsToValidate && rowsToValidate.length
        ? rowsToValidate
        : reviewForm.querySelectorAll('tbody tr[data-row-index]');

      if (sickRowPayload) {
        submitReviewRequest(submitter, rowsToMarkBusy, [
          { name: 'medical_target_index', value: String(sickRowPayload.rowIndex) },
          { name: 'medical_popup_note', value: sickRowPayload.noteText },
          { name: 'medical_certificate_file', value: sickRowPayload.fileObject, mode: 'append' }
        ]);
        return;
      }

      submitReviewRequest(submitter, rowsToMarkBusy, null);
    });
  })();
</script>

<?php include __DIR__ . '/../admin/include/layout_bottom.php'; ?>

