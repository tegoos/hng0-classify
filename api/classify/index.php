<?php
// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// 1. Input Validation
if (!isset($_GET['name']) || $_GET['name'] === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing or empty name"]);
    exit;
}

$name = $_GET['name'];

// Validate that name is a string (and not an array from multiple name parameters)
if (!is_string($name)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Non-string name"]);
    exit;
}

// 2. Call the Genderize API
$url = "https://api.genderize.io/?name=" . urlencode($name);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Keep performance high
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch) || $http_code >= 500) {
    http_response_code(502);
    echo json_encode(["status" => "error", "message" => "External API connection failed"]);
    curl_close($ch);
    exit;
}
curl_close($ch);

$data = json_decode($response, true);

// 3. Genderize Edge Cases
if (!isset($data['gender']) || $data['gender'] === null || $data['count'] === 0) {
    echo json_encode([
        "status" => "error", 
        "message" => "No prediction available for the provided name"
    ]);
    exit;
}

// 4. The Processing Rules
$probability = (float)$data['probability'];
$sample_size = (int)$data['count'];

// is_confident: true if prob >= 0.7 AND sample_size >= 100
$is_confident = ($probability >= 0.7 && $sample_size >= 100);

// Generate ISO 8601 UTC timestamp
$processed_at = gmdate('Y-m-d\TH:i:s\Z');

// 5. Success Response
echo json_encode([
    "status" => "success",
    "data" => [
        "name" => $name,
        "gender" => $data['gender'],
        "probability" => $probability,
        "sample_size" => $sample_size,
        "is_confident" => $is_confident,
        "processed_at" => $processed_at
    ]
], JSON_PRETTY_PRINT);

?>
