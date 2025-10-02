<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'raffle_system');

// Create connection
function getConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USERNAME,
            DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// Function to upload and process Excel/CSV file with duplicate checking
function processExcelFile($filePath) {
    try {
        $pdo = getConnection();
        
        // Prepare statements
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM participants WHERE number = ?");
        $insertStmt = $pdo->prepare("INSERT INTO participants (number, name, barangay) VALUES (?, ?, ?)");
        
        $newRecords = 0;
        $duplicates = 0;
        $errors = 0;
        
        // Check file extension
        $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($fileExtension !== 'csv') {
            return ['success' => false, 'error' => 'Please upload a CSV file. Convert your Excel file to CSV format first.'];
        }
        
        // Open CSV file
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            $rowCount = 0;
            
            while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $rowCount++;
                
                // Skip header row (first row)
                if ($rowCount == 1) {
                    continue;
                }
                
                // Check if all required fields are present and not empty
                if (count($row) >= 3 && !empty(trim($row[0])) && !empty(trim($row[1])) && !empty(trim($row[2]))) {
                    $number = trim($row[0]);
                    $name = trim($row[1]);
                    $barangay = trim($row[2]);
                    
                    // Check if number already exists
                    $checkStmt->execute([$number]);
                    $exists = $checkStmt->fetchColumn();
                    
                    if ($exists == 0) {
                        try {
                            $insertStmt->execute([$number, $name, $barangay]);
                            $newRecords++;
                        } catch (PDOException $e) {
                            $errors++;
                        }
                    } else {
                        $duplicates++;
                    }
                } else {
                    // Only count as error if the row has some data (not completely empty)
                    if (!empty(trim(implode('', $row)))) {
                        $errors++;
                    }
                }
            }
            fclose($handle);
        } else {
            return ['success' => false, 'error' => 'Could not open file for reading. Please check file permissions.'];
        }
        
        return [
            'success' => true,
            'new_records' => $newRecords,
            'duplicates' => $duplicates,
            'errors' => $errors
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Legacy function name for backward compatibility
function processCSVFile($filePath) {
    return processExcelFile($filePath);
}

// Function to process Excel file by converting to CSV first (alternative method)
function processExcelFileAlternative($filePath) {
    // This is a simple approach that works with basic Excel files
    // For more complex Excel files, you would need PhpSpreadsheet
    
    $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if ($fileExtension === 'csv') {
        return processExcelFile($filePath);
    }
    
    // For Excel files, we'll provide instructions to save as CSV
    return ['success' => false, 'error' => 'Please save your Excel file as CSV format (.csv) and try again. Go to File > Save As > CSV (Comma delimited) in Excel.'];
}

// Function to get participant by number
function getParticipantByNumber($number) {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM participants WHERE number = ?");
    $stmt->execute([$number]);
    return $stmt->fetch();
}

// Function to mark participant as selected
function markAsSelected($number) {
    $pdo = getConnection();
    $stmt = $pdo->prepare("UPDATE participants SET isSelected = 1, selected_at = NOW() WHERE number = ?");
    return $stmt->execute([$number]);
}

// Function to get all participants
function getAllParticipants() {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT * FROM participants ORDER BY number");
    return $stmt->fetchAll();
}

// Function to delete all participants
function deleteAllParticipants() {
    $pdo = getConnection();
    $stmt = $pdo->prepare("DELETE FROM participants");
    return $stmt->execute();
}

// Function to get total participant count
function getParticipantCount() {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM participants");
    return $stmt->fetchColumn();
}

// Function to get selected participant count
function getSelectedCount() {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM participants WHERE isSelected = 1");
    return $stmt->fetchColumn();
}
?>