<?php
include 'config.php';
require_once __DIR__ . '/delivery_pricing.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$orderId = (int)($_GET['id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
if ($orderId <= 0) {
    header('Location: my_orders.php');
    exit();
}

$orderStmt = $conn->prepare("SELECT o.id, o.user_id, o.rider_id, o.status, o.delivery_status, o.delivery_type, o.address, o.delivery_latitude, o.delivery_longitude, COALESCE(NULLIF(TRIM(r.full_name), ''), r.username) AS rider_name, rp.phone FROM orders o LEFT JOIN users r ON r.id = o.rider_id AND r.role = 'rider' LEFT JOIN rider_profiles rp ON rp.user_id = r.id WHERE o.id = ? AND o.user_id = ? LIMIT 1");
if (!$orderStmt) {
    error_log('Customer tracking order query preparation failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load delivery tracking.');
}
$orderStmt->bind_param('ii', $orderId, $userId);
if (!$orderStmt->execute()) {
    error_log('Customer tracking order query failed: ' . $orderStmt->error);
    $orderStmt->close();
    http_response_code(500);
    exit('Unable to load delivery tracking.');
}
$order = $orderStmt->get_result()->fetch_assoc();
$orderStmt->close();
if (!$order) {
    http_response_code(404);
    exit('Order not found or access denied.');
}

$isDelivery = !in_array(strtolower(trim($order['delivery_type'] ?? 'delivery')), ['pickup', 'self_pickup', 'self-pickup', 'onsite'], true);
$isOutForDelivery = strtolower(trim($order['status'] ?? '')) === 'shipped'
    && strtolower(trim($order['delivery_status'] ?? 'in_transit')) === 'in_transit'
    && !empty($order['rider_id'])
    && $isDelivery;

if (isset($_GET['rider_location']) && $_GET['rider_location'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isOutForDelivery) {
        http_response_code(409);
        echo json_encode(['error' => 'Rider tracking is not currently available for this order.']);
        exit();
    }
    $locationStmt = $conn->prepare('SELECT latitude, longitude, last_seen_at FROM rider_profiles WHERE user_id = ? LIMIT 1');
    if (!$locationStmt) {
        error_log('Customer tracking location query preparation failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load rider location.']);
        exit();
    }
    $riderId = (int)$order['rider_id'];
    $locationStmt->bind_param('i', $riderId);
    if (!$locationStmt->execute()) {
        error_log('Customer tracking location query failed: ' . $locationStmt->error);
        $locationStmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load rider location.']);
        exit();
    }
    $location = $locationStmt->get_result()->fetch_assoc();
    $locationStmt->close();
    echo json_encode([
        'latitude' => $location && $location['latitude'] !== null ? (float)$location['latitude'] : null,
        'longitude' => $location && $location['longitude'] !== null ? (float)$location['longitude'] : null,
        'lastSeen' => $location['last_seen_at'] ?? null
    ]);
    exit();
}

$deliveryPoint = $order['delivery_latitude'] !== null && $order['delivery_longitude'] !== null
    ? [(float)$order['delivery_latitude'], (float)$order['delivery_longitude']]
    : null;
$deliveryAddress = trim((string)($order['address'] ?? ''));
if ($deliveryAddress === '') {
    $deliveryAddress = 'Address not provided';
}
$storePoint = [PHARMACY_STORE_LATITUDE, PHARMACY_STORE_LONGITUDE];
$deliveryStatus = strtoupper(str_replace('_', ' ', (string)($order['delivery_status'] ?? 'in_transit')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Delivery - <?= htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f7f8fb; color: #263247; font-family: 'Segoe UI', Arial, sans-serif; }
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; padding: 32px; }
        .page-shell { width: 100%; max-width: 1500px; margin: 0 auto; }
        .back-link { display: inline-block; margin: 0 0 16px; color: #4b5563; font-size: 14px; font-weight: 600; text-decoration: none; }
        .panel { padding: 18px; border: 1px solid #e8ebf0; border-radius: 10px; background: #fff; box-shadow: 0 2px 8px rgba(35, 49, 70, .04); }
        h1 { margin: 0 0 5px; font-size: 24px; }
        .subtitle { margin: 0 0 16px; color: #68758a; font-size: 13px; }
        .summary { display: flex; flex-wrap: wrap; gap: 8px 24px; margin-bottom: 14px; font-size: 13px; }
        .summary span { color: #68758a; }
        .summary strong { margin-left: 5px; }
        #deliveryMap { height: 520px; overflow: hidden; border: 1px solid #dfe4e7; border-radius: 8px; background: #eef3f1; }
        #mapStatus { min-height: 18px; margin: 10px 2px 0; color: #68758a; font-size: 12px; }
        .map-legend { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 2px 0; color: #68758a; font-size: 12px; }
        .map-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .map-legend img { width: 20px; height: 20px; object-fit: contain; }
        .rider-legend-icon { font-size: 17px; line-height: 1; }
        .map-legend .route-key { width: 22px; height: 4px; border-radius: 3px; background: #5b4ce6; }
        .store-logo-marker, .rider-motor-marker { border: 0; background: transparent; }
        .store-logo-marker img { display: block; width: 40px; height: 40px; padding: 3px; border: 2px solid #fff; border-radius: 50%; background: #fff; box-shadow: 0 2px 8px #17212f55; object-fit: contain; }
        .rider-motor-marker svg { display: block; width: 44px; height: 44px; padding: 5px; border: 2px solid #fff; border-radius: 50%; background: #79141d; box-shadow: 0 2px 8px #17212f55; }
        .unavailable { margin-top: 12px; padding: 16px; border: 1px solid #f0d4d6; border-radius: 8px; background: #fff7f7; color: #8e1c24; }
        @media (max-width: 900px) { .main-content { margin-left: 0; width: 100%; padding: 20px; } }
        @media (max-width: 600px) { .main-content { padding: 16px 12px; } .panel { padding: 14px; } #deliveryMap { height: 400px; } }
    </style>
</head>
<body>
    <?php include 'sidebar_user.php'; ?>
    <main class="main-content">
        <div class="page-shell">
            <a class="back-link" href="order_details.php?id=<?= (int)$order['id'] ?>#orderTimeline">&larr; Back to order</a>
            <section class="panel">
                <h1>Track Delivery</h1>
                <p class="subtitle">Order <?= htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8') ?> · Follow your rider’s latest shared location.</p>
                <?php if ($isOutForDelivery): ?>
                    <div class="summary">
                        <div><span>Assigned Rider</span><strong><?= htmlspecialchars($order['rider_name'] ?? 'Rider', ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div><span>Number</span><strong><?= htmlspecialchars($order['phone'] ?? 'No phone provided', ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div><span>Status</span><strong><?= htmlspecialchars($deliveryStatus, ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div><span>Store</span><strong><?= htmlspecialchars(PHARMACY_STORE_ADDRESS, ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div><span>Delivery address</span><strong><?= htmlspecialchars($deliveryAddress, ENT_QUOTES, 'UTF-8') ?></strong></div>
                    </div>
                    <div id="deliveryMap" role="region" aria-label="Live rider map"></div>
                    <div class="map-legend" aria-label="Map legend">
                        <span><img src="images/logo.png" alt=""> Store</span>
                        <span><span class="rider-legend-icon" aria-hidden="true">🏍</span> Rider</span>
                        <span><span class="route-key" aria-hidden="true"></span> Road route to delivery</span>
                        <span>Directions via OSRM · © OpenStreetMap contributors</span>
                    </div>
                    <p id="mapStatus" aria-live="polite">Finding the road route from the store to your delivery address…</p>
                    <p id="locationStatus" aria-live="polite">Connecting to rider location…</p>
                <?php else: ?>
                    <div class="unavailable">Live tracking is available only while your order is out for delivery.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <?php if ($isOutForDelivery): ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            (function () {
                const storePoint = <?= json_encode($storePoint) ?>;
                const mapViewRadiusKm = 15;
                const latitudeDelta = mapViewRadiusKm / 111.32;
                const longitudeDelta = mapViewRadiusKm / (111.32 * Math.cos(storePoint[0] * Math.PI / 180));
                const serviceAreaBounds = L.latLngBounds(
                    [storePoint[0] - latitudeDelta, storePoint[1] - longitudeDelta],
                    [storePoint[0] + latitudeDelta, storePoint[1] + longitudeDelta]
                );
                const map = L.map('deliveryMap', {
                    maxBounds: serviceAreaBounds,
                    maxBoundsViscosity: 1
                }).setView(storePoint, 14);
                map.setMinZoom(map.getBoundsZoom(serviceAreaBounds));
                const status = document.getElementById('mapStatus');
                const deliveryPoint = <?= json_encode($deliveryPoint) ?>;
                let riderMarker = null;
                let destinationMarker = null;
                let routeLayer = null;
                let lastRoutePoint = null;
                let lastRouteRequestAt = 0;
                let routeRequestSequence = 0;

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);
                const storeIcon = L.divIcon({
                    className: 'store-logo-marker',
                    html: '<img src="images/logo.png" alt="">',
                    iconSize: [40, 40],
                    iconAnchor: [20, 20]
                });
                const riderIcon = L.divIcon({
                    className: 'rider-motor-marker',
                    html: '<svg viewBox="0 0 32 32" role="img" aria-label="Rider motorcycle"><circle cx="8" cy="23" r="4" fill="none" stroke="white" stroke-width="2.2"/><circle cx="24" cy="23" r="4" fill="none" stroke="white" stroke-width="2.2"/><path d="M8 23h7l4-8h4l3 8M13 23l-4-9h5l4 4h6M17 12h4" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                    iconSize: [44, 44],
                    iconAnchor: [22, 22]
                });
                L.marker(storePoint, {icon: storeIcon}).addTo(map).bindPopup(
                    document.createTextNode(<?= json_encode('Store: ' . PHARMACY_STORE_ADDRESS, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)
                );
                if (deliveryPoint) {
                    destinationMarker = L.marker(deliveryPoint).addTo(map).bindPopup(
                        document.createTextNode(<?= json_encode('Delivery address: ' . $deliveryAddress, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)
                    );
                }

                function metersBetween(first, second) {
                    return map.distance(L.latLng(first[0], first[1]), L.latLng(second[0], second[1]));
                }

                async function drawRoadRoute(startPoint, fitMap) {
                    if (!deliveryPoint) {
                        throw new Error('Delivery coordinates are unavailable, so the road route cannot be displayed.');
                    }
                    const sequence = ++routeRequestSequence;
                    const coordinates = startPoint[1] + ',' + startPoint[0]
                        + ';' + deliveryPoint[1] + ',' + deliveryPoint[0];
                    const routeEndpoints = [
                        'https://router.project-osrm.org/route/v1/driving/',
                        'https://routing.openstreetmap.de/routed-car/route/v1/driving/'
                    ];
                    let lastError = null;
                    for (const endpoint of routeEndpoints) {
                        try {
                            const response = await fetch(
                                endpoint + coordinates + '?overview=full&geometries=geojson',
                                {headers: {Accept: 'application/json'}}
                            );
                            const data = await response.json();
                            if (!response.ok || !data || data.code !== 'Ok' || !Array.isArray(data.routes) || !data.routes[0]) {
                                throw new Error(data && data.message ? data.message : 'No driving route was found.');
                            }
                            const routeCoordinates = data.routes[0].geometry && data.routes[0].geometry.coordinates;
                            if (!Array.isArray(routeCoordinates) || routeCoordinates.length < 2) {
                                throw new Error('The routing service returned an empty road route.');
                            }
                            const routePoints = routeCoordinates.map(function (coordinate) {
                                if (!Array.isArray(coordinate) || coordinate.length < 2
                                    || !Number.isFinite(Number(coordinate[0]))
                                    || !Number.isFinite(Number(coordinate[1]))) {
                                    throw new Error('The routing service returned invalid route coordinates.');
                                }
                                return [Number(coordinate[1]), Number(coordinate[0])];
                            });
                            if (sequence !== routeRequestSequence) {
                                return;
                            }
                            const routeBounds = L.latLngBounds(routePoints);
                            routeBounds.extend(storePoint);
                            routeBounds.extend(deliveryPoint);
                            const routeOutline = L.polyline(routePoints, {
                                color: '#ffffff',
                                weight: 12,
                                opacity: 1,
                                lineCap: 'round',
                                lineJoin: 'round'
                            });
                            const routeLine = L.polyline(routePoints, {
                                color: '#5b4ce6',
                                weight: 6,
                                opacity: 1,
                                lineCap: 'round',
                                lineJoin: 'round'
                            });
                            if (routeLayer) {
                                map.removeLayer(routeLayer);
                            }
                            routeLayer = L.layerGroup([routeOutline, routeLine]).addTo(map);
                            if (fitMap) {
                                map.fitBounds(routeBounds, {padding: [32, 32], maxZoom: 15});
                            }
                            const distanceKm = (Number(data.routes[0].distance) / 1000).toFixed(1);
                            status.textContent = 'Road route shown: ' + distanceKm + ' km.';
                            return;
                        } catch (error) {
                            lastError = error;
                        }
                    }
                    throw lastError instanceof Error ? lastError : new Error('Routing services are unavailable.');
                }

                async function refreshRoadRoute(riderPoint) {
                    const now = Date.now();
                    const riderMoved = !lastRoutePoint || metersBetween(lastRoutePoint, riderPoint) >= 100;
                    if (lastRouteRequestAt && (now - lastRouteRequestAt < 45000 || !riderMoved)) {
                        return;
                    }
                    lastRouteRequestAt = now;
                    try {
                        await drawRoadRoute(riderPoint, false);
                        lastRoutePoint = riderPoint;
                    } catch (error) {
                        status.textContent = 'Showing the store-to-address route. Rider route unavailable: '
                            + (error.message || 'routing service unavailable.');
                    }
                }

                async function refreshRiderLocation() {
                    const locationStatus = document.getElementById('locationStatus');
                    try {
                        const response = await fetch('track_delivery.php?id=<?= (int)$order['id'] ?>&rider_location=1', {cache: 'no-store'});
                        if (!response.ok) {
                            throw new Error('Location request failed.');
                        }
                        const location = await response.json();
                        if (location.latitude === null || location.longitude === null) {
                            locationStatus.textContent = 'Waiting for the rider’s GPS location. The store-to-address route remains visible.';
                            return;
                        }

                        const riderPoint = [location.latitude, location.longitude];
                        if (!riderMarker) {
                            riderMarker = L.marker(riderPoint, {icon: riderIcon}).addTo(map).bindPopup('Your rider');
                        } else {
                            riderMarker.setLatLng(riderPoint);
                        }
                        await refreshRoadRoute(riderPoint);
                        locationStatus.textContent = location.lastSeen
                            ? 'Rider GPS updated: ' + location.lastSeen
                            : 'Rider location updated.';
                    } catch (error) {
                        locationStatus.textContent = 'Unable to load the rider’s live GPS yet. The store-to-address route remains visible.';
                    }
                }

                if (deliveryPoint) {
                    drawRoadRoute(storePoint, true).catch(function (error) {
                        status.textContent = 'Could not load the road route: '
                            + (error.message || 'Routing services are unavailable.');
                    });
                } else {
                    status.textContent = 'Delivery coordinates are unavailable, so the road route cannot be displayed.';
                }
                refreshRiderLocation();
                window.setInterval(refreshRiderLocation, 10000);
            })();
        </script>
    <?php endif; ?>
</body>
</html>
