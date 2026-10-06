<?php
// ============================================================
// 📁 File: api/jobs.php
// 💼 Job posts + public careers page + applications
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit(); }

require_once __DIR__ . '/../config/database.php';

$db     = new Database();
$conn   = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($method) {

        // ============================================================
        // GET
        // ============================================================
        case 'GET':

            // ---------- ?action=public&slug=... (NO AUTH) ----------
            if (($_GET['action'] ?? '') === 'public' && !empty($_GET['slug'])) {
                $stmt = $conn->prepare("
                    SELECT id, title, slug, description, requirements, department,
                           salary_range, status, created_at, views_count
                    FROM job_posts
                    WHERE slug = ? AND status = 'open'
                    LIMIT 1
                ");
                $stmt->execute([$_GET['slug']]);
                $job = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$job) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Job not found or closed']);
                    exit();
                }

                // Increment view count
                $conn->prepare("UPDATE job_posts SET views_count = views_count + 1 WHERE id = ?")
                     ->execute([$job['id']]);
                $job['views_count'] = (int)$job['views_count'] + 1;

                echo json_encode(['success' => true, 'data' => $job]);
                exit();
            }

            // ---------- ?action=public_list (NO AUTH) ----------
            if (($_GET['action'] ?? '') === 'public_list') {
                $stmt = $conn->query("
                    SELECT id, title, slug, department, salary_range, created_at
                    FROM job_posts
                    WHERE status = 'open'
                    ORDER BY created_at DESC
                    LIMIT 50
                ");
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit();
            }

            // ---------- ?action=applications&job_id=N (HR only) ----------
            if (($_GET['action'] ?? '') === 'applications' && !empty($_GET['job_id'])) {
                $stmt = $conn->prepare("
                    SELECT * FROM job_applications
                    WHERE job_post_id = ?
                    ORDER BY created_at DESC
                ");
                $stmt->execute([(int)$_GET['job_id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                exit();
            }

            // ---------- GET ?id=N ----------
            if (!empty($_GET['id'])) {
                $stmt = $conn->prepare("SELECT * FROM job_posts WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$_GET['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found']); exit(); }
                echo json_encode(['success' => true, 'data' => $row]);
                exit();
            }

            // ---------- List (HR) ----------
            $where = []; $params = [];
            if (!empty($_GET['status']))     { $where[] = "status = ?";     $params[] = $_GET['status']; }
            if (!empty($_GET['department'])) { $where[] = "department = ?"; $params[] = $_GET['department']; }

            $sql = "SELECT * FROM job_posts";
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY created_at DESC LIMIT 200';

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ============================================================
        // POST — create job OR submit application (public)
        // ============================================================
        case 'POST':

            // ---------- Public application submission ----------
            if (($_GET['action'] ?? '') === 'apply') {
                $required = ['job_post_id', 'applicant_name', 'applicant_email'];
                foreach ($required as $f) {
                    if (empty($input[$f])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Missing: $f"]);
                        exit();
                    }
                }

                if (!filter_var($input['applicant_email'], FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid email']);
                    exit();
                }

                // Verify job exists and is open
                $chk = $conn->prepare("SELECT id FROM job_posts WHERE id = ? AND status = 'open' LIMIT 1");
                $chk->execute([(int)$input['job_post_id']]);
                if (!$chk->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Job is closed or does not exist']);
                    exit();
                }

                $stmt = $conn->prepare("
                    INSERT INTO job_applications
                        (job_post_id, applicant_name, applicant_email, applicant_phone,
                         resume_url, cover_letter, source, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, 'careers_page', ?, ?)
                ");
                $stmt->execute([
                    (int)$input['job_post_id'],
                    trim($input['applicant_name']),
                    trim($input['applicant_email']),
                    !empty($input['applicant_phone']) ? trim($input['applicant_phone']) : null,
                    !empty($input['resume_url']) ? $input['resume_url'] : null,
                    !empty($input['cover_letter']) ? $input['cover_letter'] : null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);

                // Notify HR users
                if (function_exists('notifyRole')) {
                    notifyRole(
                        $conn,
                        ['hr','super_admin','admin'],
                        '📩 New job application',
                        trim($input['applicant_name']) . ' applied for a position.',
                        'info',
                        'info'
                    );
                }

                echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
                exit();
            }

            // ---------- HR create job ----------
            $required = ['title', 'description'];
            foreach ($required as $f) {
                if (empty($input[$f])) throw new InvalidArgumentException("Missing: $f");
            }

            $slug = !empty($input['slug'])
                ? preg_replace('/[^a-z0-9-]/', '', strtolower($input['slug']))
                : preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', $input['title'])));

            // Ensure uniqueness (append -2, -3, ... if needed)
            $base = $slug; $i = 1;
            while (true) {
                $chk = $conn->prepare("SELECT id FROM job_posts WHERE slug = ? LIMIT 1");
                $chk->execute([$slug]);
                if (!$chk->fetch()) break;
                $i++;
                $slug = $base . '-' . $i;
            }

            $stmt = $conn->prepare("
                INSERT INTO job_posts
                    (title, slug, description, requirements, department, salary_range, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim($input['title']),
                $slug,
                $input['description'],
                $input['requirements'] ?? null,
                $input['department'] ?? null,
                $input['salary_range'] ?? null,
                $input['status'] ?? 'open',
                !empty($input['created_by']) ? (int)$input['created_by'] : null,
            ]);

            echo json_encode([
                'success' => true,
                'id'      => (int)$conn->lastInsertId(),
                'slug'    => $slug,
            ]);
            break;

        // ============================================================
        // PUT — update job OR track LinkedIn share OR update application
        // ============================================================
        case 'PUT':

            // ---------- Track LinkedIn share (public) ----------
            if (($_GET['action'] ?? '') === 'track_share' && !empty($_GET['id'])) {
                $conn->prepare("
                    UPDATE job_posts
                    SET linkedin_share_count = linkedin_share_count + 1,
                        linkedin_posted_at = COALESCE(linkedin_posted_at, NOW())
                    WHERE id = ?
                ")->execute([(int)$_GET['id']]);
                echo json_encode(['success' => true]);
                exit();
            }

            // ---------- Update application status (HR) ----------
            if (($_GET['action'] ?? '') === 'application_status' && !empty($_GET['id'])) {
                $stmt = $conn->prepare("UPDATE job_applications SET status = ?, notes = ? WHERE id = ?");
                $stmt->execute([
                    $input['status'] ?? 'new',
                    $input['notes'] ?? null,
                    (int)$_GET['id'],
                ]);
                echo json_encode(['success' => true]);
                exit();
            }

            // ---------- HR update job ----------
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$id) throw new InvalidArgumentException('ID required');

            $allowed = ['title','slug','description','requirements','department','salary_range','status'];
            $fields = []; $params = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $input)) {
                    $fields[] = "$f = ?";
                    $params[] = $input[$f];
                }
            }
            if (!$fields) throw new InvalidArgumentException('No fields to update');
            $params[] = $id;
            $conn->prepare("UPDATE job_posts SET " . implode(', ', $fields) . " WHERE id = ?")
                 ->execute($params);
            echo json_encode(['success' => true]);
            break;

        // ============================================================
        // DELETE
        // ============================================================
        case 'DELETE':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$id) throw new InvalidArgumentException('ID required');
            $conn->prepare("DELETE FROM job_posts WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}