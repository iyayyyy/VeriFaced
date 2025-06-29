<?php
// check_database.php - Run this to see your database structure

require_once 'db_connect.php';

echo "<h2>Database Connection Test</h2>";

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "✅ Connected to database: " . DB_NAME . "<br><br>";

// Check if 'admins' table exists
echo "<h3>Checking for 'admins' table:</h3>";
$result = $conn->query("SHOW TABLES LIKE 'admins'");
if ($result->num_rows > 0) {
    echo "✅ 'admins' table exists!<br><br>";
    
    // Show table structure
    echo "<h3>Table Structure for 'admins':</h3>";
    $result = $conn->query("DESCRIBE admins");
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Column Name</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td><strong>" . $row['Field'] . "</strong></td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    // Count admin records
    $result = $conn->query("SELECT COUNT(*) as count FROM admins");
    $count = $result->fetch_assoc()['count'];
    echo "<p><strong>Number of admin records:</strong> " . $count . "</p>";
    
    // Show sample data (without passwords)
    if ($count > 0) {
        echo "<h3>Sample Admin Records (passwords hidden):</h3>";
        $result = $conn->query("SELECT * FROM admins LIMIT 3");
        echo "<table border='1' style='border-collapse: collapse;'>";
        
        // Get column names
        $fields = $result->fetch_fields();
        echo "<tr>";
        foreach ($fields as $field) {
            echo "<th>" . $field->name . "</th>";
        }
        echo "</tr>";
        
        // Show data
        $result->data_seek(0); // Reset result pointer
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            foreach ($row as $key => $value) {
                // Hide password fields
                if (stripos($key, 'password') !== false) {
                    echo "<td>[HIDDEN]</td>";
                } else {
                    echo "<td>" . htmlspecialchars($value) . "</td>";
                }
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
} else {
    echo "❌ 'admins' table does NOT exist!<br>";
    
    // Show all tables in the database
    echo "<h3>Available tables in database:</h3>";
    $result = $conn->query("SHOW TABLES");
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_array()) {
            echo "📄 " . $row[0] . "<br>";
        }
    } else {
        echo "No tables found in database.<br>";
    }
}

$conn->close();
?>

<style>
table {
    margin: 10px 0;
}
th, td {
    padding: 8px 12px;
    text-align: left;
}
th {
    background-color: #f0f0f0;
}
</style>