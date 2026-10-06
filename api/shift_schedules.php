<?php
// api/shift_schedules.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    switch($method) {
        case 'GET':
            if (isset($_GET['user_id'])) {
                $stmt = $conn->prepare("
                    SELECT * FROM shift_schedules 
                    WHERE user_id = ? AND is_active = 1
                ");
                $stmt->execute([$_GET['user_id']]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!$result) {
                    $stmt = $conn->prepare("
                        SELECT shift_start, shift_end, grace_minutes, work_hours_per_day
                        FROM users WHERE id = ?
                    ");
                    $stmt->execute([$_GET['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        $result = [[
                            'id' => 0,
                            'user_id' => $_GET['user_id'],
                            'shift_name' => 'Default Shift',
                            'shift_start' => $user['shift_start'] ?? '08:00:00',
                            'shift_end' => $user['shift_end'] ?? '17:00:00',
                            'grace_minutes' => $user['grace_minutes'] ?? 10,
                            'work_hours_per_day' => $user['work_hours_per_day'] ?? 8,
                            'is_active' => 1
                        ]];
                    }
                }
                
                echo json_encode(['success' => true, 'data' => $result]);
            } else {
                $stmt = $conn->prepare("
                    SELECT s.*, u.full_name, u.department 
                    FROM shift_schedules s
                    JOIN users u ON s.user_id = u.id
                    WHERE s.is_active = 1
                    ORDER BY u.department, u.full_name
                ");
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $result]);
            }
            break;
            
        case 'POST':
            $stmt = $conn->prepare("
                INSERT INTO shift_schedules 
                (user_id, shift_name, shift_start, shift_end, grace_minutes, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $input['user_id'],
                $input['shift_name'],
                $input['shift_start'],
                $input['shift_end'],
                $input['grace_minutes'] ?? 10,
                $input['is_active'] ?? 1
            ]);
            
            $stmt = $conn->prepare("
                UPDATE users 
                SET shift_start = ?, shift_end = ?, grace_minutes = ?, work_hours_per_day = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $input['shift_start'],
                $input['shift_end'],
                $input['grace_minutes'] ?? 10,
                $input['work_hours_per_day'] ?? 8,
                $input['user_id']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Shift schedule created successfully',
                'id' => $conn->lastInsertId()
            ]);
            break;
            
        case 'PUT':
            $stmt = $conn->prepare("
                UPDATE shift_schedules 
                SET shift_name = ?, shift_start = ?, shift_end = ?, grace_minutes = ?, is_active = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $input['shift_name'],
                $input['shift_start'],
                $input['shift_end'],
                $input['grace_minutes'] ?? 10,
                $input['is_active'] ?? 1,
                $input['id']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Shift schedule updated successfully'
            ]);
            break;
            
        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if ($id) {
                $stmt = $conn->prepare("UPDATE shift_schedules SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Shift schedule deactivated'
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID required']);
            }
            break;
    }
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}