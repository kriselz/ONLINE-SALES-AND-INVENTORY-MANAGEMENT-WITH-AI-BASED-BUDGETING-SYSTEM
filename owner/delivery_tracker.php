<?php
session_start();
include 'config.php';
require_once __DIR__ . '/../rider/delivery_helpers.php';
require_once __DIR__ . '/access_control.php';

$isPharmacist = requireOwnerPageAccess($conn, true);

ensureRiderDeliverySchema($conn);
$orderId = (int)($_GET['order'] ?? 0);
$order = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['location'] ?? '') === '1' && $orderId > 0) {
    if ($isPharmacist && !pharmacistCanAccessOrder($conn, $orderId)) {
        http_response_code(403);
        exit();
    }
    $locationStmt = $conn->prepare('SELECT rp.latitude, rp.longitude, rp.last_seen_at FROM orders o LEFT JOIN rider_profiles rp ON rp.user_id = o.rider_id WHERE o.id = ? LIMIT 1');
    $location = null;
    if ($locationStmt) {
        $locationStmt->bind_param('i', $orderId);
        $locationStmt->execute();
        $location = $locationStmt->get_result()->fetch_assoc();
        $locationStmt->close();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['latitude' => $location && $location['latitude'] !== null ? (float)$location['latitude'] : null, 'longitude' => $location && $location['longitude'] !== null ? (float)$location['longitude'] : null, 'lastSeen' => $location['last_seen_at'] ?? null]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'share_tracking') {
    $sharedOrderId = (int)($_POST['order_id'] ?? 0);
    if ($isPharmacist && !pharmacistCanAccessOrder($conn, $sharedOrderId)) {
        http_response_code(403);
        exit('You are not allowed to share tracking for this order.');
    }
    $shareToken = bin2hex(random_bytes(24));
    $shareStmt = $conn->prepare('UPDATE orders SET tracking_shared_at = NOW(), tracking_shared_by = ?, tracking_share_token = ? WHERE id = ? AND user_id IS NOT NULL AND rider_id IS NOT NULL');
    if ($shareStmt) {
        $ownerId = (int)$_SESSION['user_id'];
        $shareStmt->bind_param('isi', $ownerId, $shareToken, $sharedOrderId);
        $shared = $shareStmt->execute() && $shareStmt->affected_rows > 0;
        $shareStmt->close();
        $customerStmt = $conn->prepare('SELECT user_id FROM orders WHERE id = ? AND tracking_share_token = ? LIMIT 1');
        if ($customerStmt) {
            $customerStmt->bind_param('is', $sharedOrderId, $shareToken);
            $customerStmt->execute();
            $customer = $customerStmt->get_result()->fetch_assoc();
            $customerStmt->close();
            if ($shared && !empty($customer['user_id'])) {
                $trackingUrl = capstoneUrl('user/order_details.php?id=' . $sharedOrderId . '&tracking_token=' . rawurlencode($shareToken));
                $message = 'Live rider tracking is now available for order ' . formatOrderNumber($conn, $sharedOrderId) . '. Open this link: ' . $trackingUrl;
                $noticeStmt = $conn->prepare('INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())');
                if ($noticeStmt) {
                    $noticeStmt->bind_param('is', $customer['user_id'], $message);
                    $noticeStmt->execute();
                    $noticeStmt->close();
                }
            }
        }
    }
    header('Location: delivery_tracker.php?order=' . $sharedOrderId . '&shared=1');
    exit();
}

if ($orderId > 0) {
    $stmt = $conn->prepare("SELECT o.id, o.user_id, o.rider_id, o.fullname, o.address, o.contact_number, o.status, o.delivery_type, o.delivery_status, o.tracking_shared_at, o.tracking_share_token, o.delivery_latitude, o.delivery_longitude, COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(o.fullname), ''), u.username) AS customer_name, COALESCE(NULLIF(TRIM(r.full_name), ''), r.username) AS rider_name, rp.phone AS rider_phone, rp.vehicle_type, rp.plate_number, rp.latitude AS rider_latitude, rp.longitude AS rider_longitude, rp.last_seen_at FROM orders o LEFT JOIN users u ON u.id = o.user_id LEFT JOIN users r ON r.id = o.rider_id AND r.role = 'rider' LEFT JOIN rider_profiles rp ON rp.user_id = r.id WHERE o.id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($order && $isPharmacist && !pharmacistCanAccessOrder($conn, $orderId)) {
        $order = null;
    }
    if ($order && in_array(strtolower(trim($order['delivery_type'] ?? 'delivery')), ['onsite', 'pickup', 'self_pickup', 'self-pickup'], true)) {
        $order = null;
    }
}

$events = [];
if ($order) {
    $eventStmt = $conn->prepare('SELECT event_type, note, created_at FROM delivery_events WHERE order_id = ? ORDER BY created_at DESC LIMIT 12');
    $eventStmt->bind_param('i', $orderId);
    $eventStmt->execute();
    $eventResult = $eventStmt->get_result();
    while ($event = $eventResult->fetch_assoc()) {
        $events[] = $event;
    }
    $eventStmt->close();
}

$mapData = [
    'orderId' => $orderId,
    'customer' => $order['customer_name'] ?? '',
    'address' => $order['address'] ?? '',
    'latitude' => $order && $order['delivery_latitude'] !== null ? (float)$order['delivery_latitude'] : null,
    'longitude' => $order && $order['delivery_longitude'] !== null ? (float)$order['delivery_longitude'] : null,
    'riderLatitude' => $order && $order['rider_latitude'] !== null ? (float)$order['rider_latitude'] : null,
    'riderLongitude' => $order && $order['rider_longitude'] !== null ? (float)$order['rider_longitude'] : null,
    'riderLastSeen' => $order['last_seen_at'] ?? null
];
$trackingToken = $order['tracking_share_token'] ?? '';
$trackingUrl = '';
if (!empty($order['tracking_shared_at']) && $trackingToken !== '') {
    $trackingUrl = capstoneUrl('user/order_details.php?id=' . $orderId . '&tracking_token=' . rawurlencode($trackingToken));
}
function trackerLabel($value) {
    return ucwords(str_replace('_', ' ', $value ?: 'Not set'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery Tracker</title>
<link rel="stylesheet" href="css/sidebar_owner.css?v=20260905-4">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
*{box-sizing:border-box}body{margin:0;background:#f7f8fa;color:#252a30;font-family:'Segoe UI',Tahoma,sans-serif}.content{margin-left:244px;width:calc(100% - 244px);min-height:100vh;padding:22px 28px}.heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.heading h1{margin:0 0 4px;font-size:24px}.heading p{margin:0;color:#7b8189;font-size:12px}.back{color:#ed1b3b;text-decoration:none;font-size:11px;font-weight:700}.tracker{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:15px}.panel{background:#fff;border:1px solid #edf0f2;border-radius:10px;box-shadow:0 2px 9px #1c20260d;overflow:hidden}.map-panel{padding:12px}.map-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:3px 2px 12px}.map-toolbar strong{font-size:13px}.map-toolbar span{color:#7b8189;font-size:10px}.map-button{border:1px solid #dce3e8;border-radius:6px;background:#fff;color:#276b68;padding:8px 10px;font-size:10px;font-weight:700;cursor:pointer}.map-button:hover{background:#edf8f5}.map{height:620px;border-radius:8px;background:#eef3f1}.legend{display:flex;flex-wrap:wrap;gap:12px;margin:10px 2px 0;color:#6f7780;font-size:10px}.legend i{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:4px;background:#df334f}.legend i.origin{background:#187c76}.sidebar{display:grid;gap:15px;align-content:start}.card{padding:15px}.card h2{font-size:13px;margin:0 0 12px}.info-row{padding:9px 0;border-top:1px solid #f0f1f3;font-size:10px}.info-row span{display:block;color:#8a929a;font-size:9px;margin-bottom:3px}.info-row strong{line-height:1.45;overflow-wrap:anywhere}.status{display:inline-block;padding:5px 8px;border-radius:999px;background:#fff3d3;color:#b37a0b;font-size:9px;text-transform:uppercase}.status.delivered{background:#e5f7ed;color:#278c58}.status.failed{background:#ffe8ee;color:#c83b5a}.notice{padding:10px;border-radius:7px;background:#fff8e7;color:#8c6412;font-size:10px;line-height:1.5;margin-top:10px}.events{display:grid;gap:0}.event{position:relative;padding:9px 0 9px 18px;border-top:1px solid #f0f1f3;font-size:10px}.event:before{content:'';position:absolute;left:2px;top:14px;width:8px;height:8px;background:#2ca567;border-radius:50%}.event time{display:block;color:#8a929a;font-size:9px;margin-top:3px}.empty{color:#7b8189;font-size:11px;padding:8px 0}@media(max-width:950px){.tracker{grid-template-columns:1fr}.sidebar{grid-template-columns:1fr 1fr}.map{height:480px}}@media(max-width:650px){.content{margin-left:0;width:100%;padding:18px}.heading{align-items:flex-start;gap:10px}.sidebar{grid-template-columns:1fr}.map{height:420px}.map-toolbar{align-items:flex-start;flex-direction:column}}
.content, .content * { color: #000 !important; }
</style>
</head>
<body>
<?php include 'appbar_owner.php'; include 'sidebar_owner.php'; ?>
<main class="content">
    <header class="heading">
        <div><h1>Delivery Tracker</h1><p>Check the rider's route and confirm the customer's delivery location.</p></div>
        <a class="back" href="admin_orders.php?order=<?php echo $orderId; ?>">Back to order</a>
    </header>
    <?php if (!$order): ?>
        <section class="panel card"><div class="empty">Order not found. Select an order from the Orders page first.</div></section>
    <?php else: ?>
    <div class="tracker">
        <section class="panel map-panel">
            <div class="map-toolbar"><div><strong>Route to <?php echo htmlspecialchars($order['customer_name']); ?></strong><br><span id="routeStatus">Preparing map...</span></div><button class="map-button" id="useLocation" type="button">Use my location as pharmacy</button></div>
            <div class="map" id="deliveryMap" aria-label="Delivery route map"></div>
            <div class="legend"><span><i class="origin"></i>Pharmacy / route start</span><span><i></i>Customer destination</span><span id="routeDistance"></span></div>
        </section>
        <aside class="sidebar">
            <section class="panel card">
                <h2>Destination Check</h2>
                <div class="info-row"><span>Order</span><strong><?php echo htmlspecialchars(formatOrderNumber($conn, (int)$order['id']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="info-row"><span>Customer</span><strong><?php echo htmlspecialchars($order['customer_name']); ?></strong></div>
                <div class="info-row"><span>Delivery address</span><strong><?php echo nl2br(htmlspecialchars($order['address'] ?: 'No address provided')); ?></strong></div>
                <div class="info-row"><span>Saved coordinates</span><strong><?php echo $mapData['latitude'] !== null ? htmlspecialchars($mapData['latitude'] . ', ' . $mapData['longitude']) : 'Not saved; address will be located on map'; ?></strong></div>
                <div class="notice">Verify that the red destination pin matches the user's address before the rider leaves. Ask the user to correct the address if the pin is wrong.</div>
            </section>
            <section class="panel card">
                <h2>Rider Status</h2>
                <div class="info-row"><span>Rider</span><strong><?php echo htmlspecialchars($order['rider_name'] ?: 'Not assigned'); ?></strong></div>
                <div class="info-row"><span>Contact</span><strong><?php echo htmlspecialchars($order['rider_phone'] ?: 'No phone provided'); ?></strong></div>
                <div class="info-row"><span>Vehicle</span><strong><?php echo htmlspecialchars(trim(($order['vehicle_type'] ?: 'No vehicle') . ' / ' . ($order['plate_number'] ?: 'No plate'))); ?></strong></div>
                <div class="info-row"><span>Delivery status</span><strong><span class="status <?php echo strtolower($order['delivery_status'] ?? '') === 'delivered' ? 'delivered' : (strtolower($order['delivery_status'] ?? '') === 'failed' ? 'failed' : ''); ?>"><?php echo htmlspecialchars(trackerLabel($order['delivery_status'] ?? 'in_transit')); ?></span></strong></div>
                <div class="info-row"><span>Customer tracking</span><strong><?php echo !empty($order['tracking_shared_at']) ? 'Shared on ' . htmlspecialchars(date('M d, Y h:i A', strtotime($order['tracking_shared_at']))) : 'Not shared yet'; ?></strong></div>
                <?php if ($trackingUrl !== ''): ?><div class="info-row"><span>Tracking link</span><strong><input id="trackingLink" value="<?php echo htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8'); ?>" readonly style="width:100%;height:30px;border:1px solid #dce3e8;border-radius:6px;padding:0 7px;font-size:9px"><button class="map-button" type="button" id="copyTrackingLink" style="margin-top:7px">Copy tracking link</button></strong></div><?php endif; ?>
                <?php if (!empty($order['rider_id'])): ?><form method="post" style="margin-top:10px"><input type="hidden" name="action" value="share_tracking"><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><button class="map-button" type="submit"><?php echo !empty($order['tracking_shared_at']) ? 'Share Again / Notify Customer' : 'Share Live Tracking with Customer'; ?></button></form><?php endif; ?>
            </section>
            <section class="panel card"><h2>Delivery Activity</h2><div class="events">
                <?php if ($events): foreach ($events as $event): ?><div class="event"><strong><?php echo htmlspecialchars(trackerLabel($event['event_type'])); ?></strong><?php if ($event['note']): ?><br><?php echo htmlspecialchars($event['note']); ?><?php endif; ?><time><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($event['created_at']))); ?></time></div><?php endforeach; else: ?><div class="empty">No rider events recorded yet.</div><?php endif; ?>
            </div></section>
        </aside>
    </div>
    <?php endif; ?>
</main>
<?php if ($order): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const delivery = <?php echo json_encode($mapData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const map = L.map('deliveryMap').setView([14.6760, 121.0437], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'}).addTo(map);
let originMarker; let destinationMarker; let routeLine;
let riderMarker;
const routeStatus = document.getElementById('routeStatus');
const routeDistance = document.getElementById('routeDistance');
const destinationIcon = L.divIcon({className: 'destination-pin', html: '<div style="width:16px;height:16px;background:#df334f;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 7px #0005"></div>', iconSize: [16,16], iconAnchor: [8,8]});
const originIcon = L.divIcon({className: 'origin-pin', html: '<div style="width:16px;height:16px;background:#187c76;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 7px #0005"></div>', iconSize: [16,16], iconAnchor: [8,8]});
const riderIcon = L.divIcon({className: 'rider-pin', html: '<div style="width:20px;height:20px;background:#1769aa;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 7px #0005"></div>', iconSize: [20,20], iconAnchor: [10,10]});
function updateRiderLocation(location) {
    if (location.latitude === null || location.longitude === null) return;
    const point = [location.latitude, location.longitude];
    if (!riderMarker) riderMarker = L.marker(point, {icon: riderIcon}).addTo(map);
    else riderMarker.setLatLng(point);
    riderMarker.bindPopup('<strong>Rider location</strong><br>Last update: ' + (location.lastSeen || 'Just now'));
}
function pollRiderLocation() {
    fetch('delivery_tracker.php?order=' + encodeURIComponent(delivery.orderId) + '&location=1', {cache: 'no-store'})
        .then(response => response.json()).then(updateRiderLocation).catch(() => {});
}
function setDestination(lat, lon, label) { destinationMarker = L.marker([lat, lon], {icon: destinationIcon}).addTo(map).bindPopup('<strong>Customer destination</strong><br>' + label).openPopup(); }
function drawRoute(origin, destination) {
    if (originMarker) originMarker.remove();
    if (routeLine) routeLine.remove();
    originMarker = L.marker(origin, {icon: originIcon}).addTo(map).bindPopup('<strong>Store location</strong><br>454 Rizal Avenue Extension, Caloocan, Metro Manila');
    const bounds = L.latLngBounds([origin, destination]); map.fitBounds(bounds, {padding: [30,30]});
    routeStatus.textContent = 'Loading driving route...';
    fetch('https://router.project-osrm.org/route/v1/driving/' + origin[1] + ',' + origin[0] + ';' + destination[1] + ',' + destination[0] + '?overview=full&geometries=geojson')
        .then(response => response.json()).then(data => {
            if (!data.routes || !data.routes[0]) throw new Error('No route found');
            routeLine = L.geoJSON(data.routes[0].geometry, {style: {color: '#187c76', weight: 5, opacity: .82}}).addTo(map);
            routeDistance.textContent = (data.routes[0].distance / 1000).toFixed(1) + ' km route';
            routeStatus.textContent = 'Route ready. Confirm the red pin with the saved address.';
            map.fitBounds(routeLine.getBounds(), {padding: [30,30]});
        }).catch(() => { routeStatus.textContent = 'Destination found, but route service is unavailable.'; });
}
function geocode(query) { return fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=ph&q=' + encodeURIComponent(query), {headers: {'Accept-Language': 'en'}}).then(response => response.json()).then(rows => rows[0] ? [parseFloat(rows[0].lat), parseFloat(rows[0].lon)] : null); }
function startTracker(origin) {
    let destination = delivery.latitude !== null && delivery.longitude !== null ? [delivery.latitude, delivery.longitude] : null;
    const destinationPromise = destination ? Promise.resolve(destination) : geocode(delivery.address);
    destinationPromise.then(point => {
        if (!point) { routeStatus.textContent = 'Could not locate the delivery address. Check the address text.'; return; }
        setDestination(point[0], point[1], delivery.address || delivery.customer);
        drawRoute(origin, point);
    }).catch(() => { routeStatus.textContent = 'Could not locate the delivery address.'; });
}
startTracker([14.6391328, 120.9833358]);
updateRiderLocation({latitude: delivery.riderLatitude, longitude: delivery.riderLongitude, lastSeen: delivery.riderLastSeen});
pollRiderLocation();
setInterval(pollRiderLocation, 10000);
document.getElementById('useLocation').addEventListener('click', function() {
    if (!navigator.geolocation) { routeStatus.textContent = 'Browser location is not available.'; return; }
    routeStatus.textContent = 'Requesting pharmacy location...';
    navigator.geolocation.getCurrentPosition(position => startTracker([position.coords.latitude, position.coords.longitude]), () => { routeStatus.textContent = 'Location permission was not granted.'; });
});
const copyTrackingLink = document.getElementById('copyTrackingLink');
if (copyTrackingLink) copyTrackingLink.addEventListener('click', function() {
    navigator.clipboard.writeText(document.getElementById('trackingLink').value).then(() => { copyTrackingLink.textContent = 'Copied'; });
});
</script>
<?php endif; ?>
</body>
</html>