<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Backup directory
$backupDir = __DIR__ . '/../backups/';
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'create') {
    try {
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . $filename;
        
        // Get all tables
        $tables = [];
        $query = "SHOW TABLES";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $output = "-- Smart POS Backup\n";
        $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($results as $table) {
            // Get create table statement
            $query = "SHOW CREATE TABLE `$table`";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
            $output .= "\n\n" . $createTable['Create Table'] . ";\n\n";
            
            // Get table data
            $query = "SELECT * FROM `$table`";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($db) {
                        if ($value === null) return 'NULL';
                        return $db->quote($value);
                    }, $row);
                    
                    $output .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
                }
            }
        }
        
        $output .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        
        file_put_contents($filepath, $output);
        
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'message' => 'Backup created successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restore') {
    try {
        // Get latest backup file
        $files = glob($backupDir . '*.sql');
        if (empty($files)) {
            throw new Exception('No backup files found');
        }
        
        // Sort files by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $latestBackup = $files[0];
        $sql = file_get_contents($latestBackup);
        
        // Execute SQL
        $db->exec("SET FOREIGN_KEY_CHECKS=0");
        $db->exec($sql);
        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        
        echo json_encode([
            'success' => true,
            'message' => 'Backup restored successfully from ' . basename($latestBackup)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>