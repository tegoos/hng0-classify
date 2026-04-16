<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// --- Configuration & Initialization ---
$db = new PDO('sqlite:profiles.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$method = $_SERVER['REQUEST_METHOD'];
$path = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));

// --- Helper Functions ---
function sendResponse($status_code, $data) {
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

function getAgeGroup($age) {
    if ($age <= 12) return 'child';
    if ($age <= 19) return 'teenager';
    if ($age <= 59) return 'adult';
    return 'senior';
}

// UUID v7 Generator (Simple Implementation)
function generateUUIDv7() {
    $milli = (int)(microtime(true) * 1000);
    $hex = str_pad(dechex($milli), 12, '0', STR_PAD_LEFT);
    $rand = bin2hex(random_bytes(10));
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        '7' . substr($rand, 0, 3),
        dechex(8 + (hexdec(substr($rand, 3, 1)) & 3)) . substr($rand, 4, 3),
        substr($rand, 7, 12)
    );
}

// --- Router ---

// POST /api/profiles
if ($method === 'POST' && ($path[1] ?? '') === 'profiles') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = isset($input['name']) ? strtolower(trim($input['name'])) : null;

    if (empty($name)) {
        sendResponse(400, ["status" => "error", "message" => "Missing or empty name"]);
    }

    // Idempotency Check
    $stmt = $db->prepare("SELECT * FROM profiles WHERE name = ?");
    $stmt->execute([$name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        sendResponse(200, [
            "status" => "success",
            "message" => "Profile already exists",
            "data" => $existing
        ]);
    }

    // Concurrent API Calls using curl_multi
    $urls = [
        'gender' => "https://api.genderize.io?name=$name",
        'age'    => "https://api.agify.io?name=$name",
        'nat'    => "https://api.nationalize.io?name=$name"
    ];

    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $url) {
        $handles[$key] = curl_init($url);
        curl_setopt($handles[$key], CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $handles[$key]);
    }

    do { curl_multi_exec($mh, $running); } while ($running);

    $results = [];
    foreach ($handles as $key => $ch) {
        $results[$key] = json_decode(curl_multi_getcontent($ch), true);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    // Validation
    if (!$results['gender']['gender'] || $results['gender']['count'] === 0) 
        sendResponse(502, ["status" => "error", "message" => "Genderize returned an invalid response"]);
    if ($results['age']['age'] === null) 
        sendResponse(502, ["status" => "error", "message" => "Agify returned an invalid response"]);
    if (empty($results['nat']['country'])) 
        sendResponse(502, ["status" => "error", "message" => "Nationalize returned an invalid response"]);

    // Process Country (Highest Probability)
    usort($results['nat']['country'], fn($a, $b) => $b['probability'] <=> $a['probability']);
    $topCountry = $results['nat']['country'][0];

    // Create Profile
    $newProfile = [
        'id' => generateUUIDv7(),
        'name' => $name,
        'gender' => $results['gender']['gender'],
        'gender_probability' => $results['gender']['probability'],
        'sample_size' => $results['gender']['count'],
        'age' => $results['age']['age'],
        'age_group' => getAgeGroup($results['age']['age']),
        'country_id' => $topCountry['country_id'],
        'country_probability' => $topCountry['probability'],
        'created_at' => gmdate('Y-m-d\TH:i:s\Z')
    ];

    // Persist
    $sql = "INSERT INTO profiles (id, name, gender, gender_probability, sample_size, age, age_group, country_id, country_probability, created_at)
            VALUES (:id, :name, :gender, :gender_probability, :sample_size, :age, :age_group, :country_id, :country_probability, :created_at)";
    $db->prepare($sql)->execute($newProfile);

    sendResponse(201, ["status" => "success", "data" => $newProfile]);
}

// GET /api/profiles
if ($method === 'GET' && count($path) === 2 && $path[1] === 'profiles') {
    $where = ["1=1"];
    $params = [];

    foreach (['gender', 'country_id', 'age_group'] as $filter) {
        if (!empty($_GET[$filter])) {
            $where[] = "LOWER($filter) = ?";
            $params[] = strtolower($_GET[$filter]);
        }
    }

    $sql = "SELECT * FROM profiles WHERE " . implode(" AND ", $where);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse(200, ["status" => "success", "count" => count($results), "data" => $results]);
}

// GET /api/profiles/{id} or DELETE /api/profiles/{id}
if (count($path) === 3 && $path[1] === 'profiles') {
    $id = $path[2];

    if ($method === 'GET') {
        $stmt = $db->prepare("SELECT * FROM profiles WHERE id = ?");
        $stmt->execute([$id]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$profile) sendResponse(404, ["status" => "error", "message" => "Profile not found"]);
        sendResponse(200, ["status" => "success", "data" => $profile]);
    }

    if ($method === 'DELETE') {
        $stmt = $db->prepare("DELETE FROM profiles WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) sendResponse(404, ["status" => "error", "message" => "Profile not found"]);
        http_response_code(204);
        exit;
    }
}

// Fallback for 404
sendResponse(404, ["status" => "error", "message" => "Endpoint not found"]);
