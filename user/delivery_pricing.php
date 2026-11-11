<?php

const PHARMACY_STORE_ADDRESS = '454 Rizal Avenue Extension, Caloocan, Metro Manila';
const PHARMACY_STORE_LATITUDE = 14.6391328;
const PHARMACY_STORE_LONGITUDE = 120.9833358;

function deliveryRoadDistanceKm(float $latitude, float $longitude): float
{
    if (!is_finite($latitude) || !is_finite($longitude)
        || $latitude < -90 || $latitude > 90
        || $longitude < -180 || $longitude > 180) {
        throw new InvalidArgumentException('The delivery coordinates are invalid.');
    }

    $coordinates = PHARMACY_STORE_LONGITUDE . ',' . PHARMACY_STORE_LATITUDE
        . ';' . $longitude . ',' . $latitude;
    $endpoints = [
        'https://router.project-osrm.org/route/v1/driving/',
        'https://routing.openstreetmap.de/routed-car/route/v1/driving/'
    ];
    $errors = [];

    foreach ($endpoints as $endpoint) {
        $request = curl_init($endpoint . $coordinates . '?overview=false');
        if ($request === false) {
            $errors[] = 'Could not initialize a routing service request.';
            continue;
        }

        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'CapstoneDeliveryCheckout/1.0'
        ]);
        $response = curl_exec($request);
        $httpStatus = (int)curl_getinfo($request, CURLINFO_HTTP_CODE);
        $curlError = curl_error($request);
        curl_close($request);

        if (!is_string($response) || $httpStatus < 200 || $httpStatus >= 300) {
            $errors[] = $curlError !== ''
                ? $curlError
                : 'Routing service returned HTTP ' . $httpStatus . '.';
            continue;
        }

        $data = json_decode($response, true);
        $distanceMeters = $data['routes'][0]['distance'] ?? null;
        if (($data['code'] ?? null) !== 'Ok' || !is_numeric($distanceMeters) || $distanceMeters < 0) {
            $errors[] = is_string($data['message'] ?? null)
                ? $data['message']
                : 'Routing service returned no valid road distance.';
            continue;
        }

        return (float)$distanceMeters / 1000;
    }

    throw new RuntimeException('Unable to calculate delivery road distance: ' . implode(' ', $errors));
}

function deliveryFeeForDistance(float $distanceKm): ?float
{
    if ($distanceKm < 0 || $distanceKm > 10) {
        return null;
    }
    $tierDistance = round($distanceKm, 1);
    if ($tierDistance <= 3) {
        return 50.0;
    }
    if ($tierDistance <= 5) {
        return 70.0;
    }
    if ($tierDistance <= 7) {
        return 90.0;
    }
    return 120.0;
}

function getRiderAvailability(mysqli $conn): array
{
    $stmt = $conn->prepare(
        'SELECT EXISTS(
            SELECT 1
            FROM rider_profiles
            WHERE is_available = 1
              AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ) AS rider_available'
    );
    if (!$stmt) {
        error_log('Rider availability query preparation failed: ' . $conn->error);
        return ['available' => false, 'error' => 'Unable to verify rider availability.'];
    }

    if (!$stmt->execute()) {
        error_log('Rider availability query failed: ' . $stmt->error);
        $stmt->close();
        return ['available' => false, 'error' => 'Unable to verify rider availability.'];
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        error_log('Rider availability query returned no result.');
        return ['available' => false, 'error' => 'Unable to verify rider availability.'];
    }

    return ['available' => (bool)$row['rider_available'], 'error' => null];
}
